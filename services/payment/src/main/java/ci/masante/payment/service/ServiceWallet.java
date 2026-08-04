package ci.masante.payment.service;

import ci.masante.payment.domain.fraud.FraudSuspecteeException;
import ci.masante.payment.domain.fraud.ResultatFraude;
import ci.masante.payment.domain.model.Facture;
import ci.masante.payment.domain.model.OwnerTypeWallet;
import ci.masante.payment.domain.model.TypeOperationWallet;
import ci.masante.payment.domain.model.Wallet;
import ci.masante.payment.domain.model.WalletEntry;
import ci.masante.payment.domain.model.WalletOperation;
import ci.masante.payment.domain.model.WalletStatut;
import ci.masante.payment.domain.wallet.OperationWalletInvalideException;
import ci.masante.payment.domain.wallet.ReglesWallet;
import ci.masante.payment.repository.WalletEntryRepository;
import ci.masante.payment.repository.WalletOperationRepository;
import ci.masante.payment.repository.WalletRepository;
import org.springframework.context.annotation.Lazy;
import org.springframework.stereotype.Service;
import org.springframework.transaction.annotation.Transactional;

import java.time.Instant;
import java.util.List;
import java.util.Map;
import java.util.UUID;

/**
 * Portefeuille MASANTÉ (CDC_06 §6). <b>Comptabilité en double écriture</b> : chaque opération produit
 * deux écritures de somme nulle ; le <b>solde n'est jamais stocké</b> (= somme des écritures, §6.3).
 *
 * <p><b>Frontière</b> : contrôle de suffisance, refus si gelé, calcul du solde = backend seul.
 * Idempotence (verrou Redis + unicité PG) comme les paiements. PAIEMENT SIMULÉ (le crédit ne
 * correspond à aucun flux monétaire réel).</p>
 */
@Service
public class ServiceWallet {

    private static final String CONTREPARTIE = "SYSTEME-CONTREPARTIE";

    private final WalletRepository wallets;
    private final WalletOperationRepository operations;
    private final WalletEntryRepository entries;
    private final ServiceIdempotence idempotence;
    private final ServiceAudit audit;
    private final ServiceFacturation facturation;
    private final ServiceSecuriteWallet securite;
    private final ServiceSignature signature;
    private final ServiceDetectionFraude detection;
    private final ServiceWallet self;

    public ServiceWallet(WalletRepository wallets, WalletOperationRepository operations,
                         WalletEntryRepository entries, ServiceIdempotence idempotence,
                         ServiceAudit audit, ServiceFacturation facturation,
                         ServiceSecuriteWallet securite, ServiceSignature signature,
                         ServiceDetectionFraude detection, @Lazy ServiceWallet self) {
        this.wallets = wallets;
        this.operations = operations;
        this.entries = entries;
        this.idempotence = idempotence;
        this.audit = audit;
        this.facturation = facturation;
        this.securite = securite;
        this.signature = signature;
        this.detection = detection;
        this.self = self;
    }

    // --- consultation -------------------------------------------------------------------------

    @Transactional
    public Wallet creer(String ownerRef, OwnerTypeWallet ownerType, String devise) {
        String dev = devise == null ? "XOF" : devise;
        return wallets.findByOwnerRefAndOwnerTypeAndDevise(ownerRef, ownerType, dev)
                .orElseGet(() -> wallets.save(new Wallet(ownerRef, ownerType, dev)));
    }

    @Transactional(readOnly = true)
    public Wallet trouver(UUID id) {
        return wallets.findById(id).orElseThrow(() -> new WalletIntrouvableException(id.toString()));
    }

    @Transactional(readOnly = true)
    public long solde(UUID walletId) {
        return entries.solde(walletId);
    }

    @Transactional(readOnly = true)
    public List<WalletEntry> entriesDe(UUID walletId) {
        trouver(walletId);
        return entries.findByWalletIdOrderByCreatedAtAsc(walletId);
    }

    @Transactional
    public Wallet geler(UUID id) {
        return changerStatut(id, WalletStatut.GELE, "WalletFrozen");
    }

    @Transactional
    public Wallet degeler(UUID id) {
        return changerStatut(id, WalletStatut.ACTIF, "WalletUnfrozen");
    }

    // --- opérations financières (idempotentes) ------------------------------------------------

    public WalletOperation crediter(UUID walletId, long montant, String ref, String libelle, String cle) {
        return soumettre(DemandeOperationWallet.simple(
                TypeOperationWallet.CREDIT, null, walletId, montant, ref, libelle, null, cle));
    }

    /** Crédit de BONUS (acte admin). Contrepartie dédiée SYSTEME-BONUS. Idempotent + audité. */
    public WalletOperation crediterBonus(UUID walletId, long montant, String motif, String cle) {
        return soumettre(DemandeOperationWallet.simple(
                TypeOperationWallet.BONUS, null, walletId, montant, null, motif, null, cle));
    }

    /** Crédit de CASHBACK rattaché à sa campagne et à son op source. Contrepartie SYSTEME-CASHBACK. */
    public WalletOperation crediterCashback(UUID walletId, long montant, String campagneCode,
                                            UUID operationSourceId, String cle) {
        return soumettre(new DemandeOperationWallet(TypeOperationWallet.CASHBACK, null, walletId, montant,
                campagneCode, "Cashback " + campagneCode, null, cle, null, false, campagneCode,
                operationSourceId));
    }

    /** Reprise (clawback) d'un cashback : débit du wallet vers SYSTEME-CASHBACK. Peut rendre le solde
     *  négatif (dette assumée — pas de contrôle d'overdraft sur une reprise système). */
    public WalletOperation reprendreCashback(UUID walletId, long montant, String campagneCode,
                                             UUID operationSourceId, String cle) {
        return soumettre(new DemandeOperationWallet(TypeOperationWallet.CASHBACK_ANNULATION, walletId, null,
                montant, campagneCode, "Reprise cashback " + campagneCode, null, cle, null, false,
                campagneCode, operationSourceId));
    }

    public WalletOperation debiter(UUID walletId, long montant, String ref, String libelle, String cle,
                                   String pin, String otp) {
        var rejeu = operations.findByIdempotencyKey(cle);
        if (rejeu.isPresent()) {
            return rejeu.get(); // rejeu idempotent : déjà autorisé, ne pas redemander le PIN
        }
        self.autoDegelSiExpire(walletId);
        securite.autoriserOperation(walletId, montant, pin, otp);
        return executerAvecFraude(new DemandeOperationWallet(TypeOperationWallet.DEBIT, walletId, null,
                montant, ref, libelle, null, cle, otp, securite.otpExigeParMontant(montant), null, null));
    }

    public WalletOperation transferer(UUID sourceId, UUID destId, long montant, String libelle, String cle,
                                      String pin, String otp) {
        var rejeu = operations.findByIdempotencyKey(cle);
        if (rejeu.isPresent()) {
            return rejeu.get();
        }
        self.autoDegelSiExpire(sourceId);
        securite.autoriserOperation(sourceId, montant, pin, otp);
        return executerAvecFraude(new DemandeOperationWallet(TypeOperationWallet.TRANSFERT, sourceId,
                destId, montant, null, libelle, null, cle, otp, securite.otpExigeParMontant(montant),
                null, null));
    }

    public WalletOperation payerFacture(UUID walletId, UUID factureId, long montant, String cle,
                                        String pin, String otp) {
        var rejeu = operations.findByIdempotencyKey(cle);
        if (rejeu.isPresent()) {
            return rejeu.get();
        }
        self.autoDegelSiExpire(walletId);
        long effectif = montant > 0 ? montant : duFacture(factureId);
        securite.autoriserOperation(walletId, effectif, pin, otp);
        return executerAvecFraude(new DemandeOperationWallet(TypeOperationWallet.PAIEMENT_FACTURE,
                walletId, null, montant, null, null, factureId, cle, otp,
                securite.otpExigeParMontant(effectif), null, null));
    }

    /**
     * Enveloppe l'exécution : sur palier GEL, la transaction de l'opération a été annulée (aucune
     * trace) ; on gèle + alerte + audit dans une transaction PROPRE, hors verrou (pas d'interblocage),
     * puis on relève l'exception (409 générique).
     */
    private WalletOperation executerAvecFraude(DemandeOperationWallet d) {
        try {
            return soumettre(d);
        } catch (FraudSuspecteeException e) {
            detection.traiterSuspicion(e.walletId(), e.resultat(), e.montantTente());
            throw e;
        }
    }

    /** Dû restant d'une facture, pour dimensionner le contrôle de sécurité (montant 0 = solder tout). */
    private long duFacture(UUID factureId) {
        Facture f = facturation.trouver(factureId);
        return Math.max(f.getResteAPayer() - f.getMontantRegle(), 0);
    }

    private WalletOperation soumettre(DemandeOperationWallet d) {
        var existant = operations.findByIdempotencyKey(d.idempotencyKey());
        if (existant.isPresent()) {
            return existant.get(); // rejeu idempotent
        }
        if (!idempotence.acquerir(d.idempotencyKey())) {
            return operations.findByIdempotencyKey(d.idempotencyKey())
                    .orElseThrow(() -> new ConflitIdempotenceException(d.idempotencyKey()));
        }
        try {
            return self.executer(d);
        } finally {
            idempotence.liberer(d.idempotencyKey());
        }
    }

    @Transactional
    public WalletOperation executer(DemandeOperationWallet d) {
        var deja = operations.findByIdempotencyKey(d.idempotencyKey());
        if (deja.isPresent()) {
            return deja.get();
        }
        return switch (d.type()) {
            case CREDIT -> crediterInterne(d);
            case DEBIT -> debiterInterne(d);
            case TRANSFERT -> transfererInterne(d);
            case PAIEMENT_FACTURE -> payerFactureInterne(d);
            case BONUS -> crediterRecompenseInterne(d, "SYSTEME-BONUS", "WalletBonus");
            case CASHBACK -> crediterRecompenseInterne(d, "SYSTEME-CASHBACK", "WalletCashback");
            case CASHBACK_ANNULATION -> reprendreCashbackInterne(d);
        };
    }

    private WalletOperation crediterInterne(DemandeOperationWallet d) {
        Wallet dest = trouver(d.destWalletId());
        ReglesWallet.verifierMontant(d.montant());
        Wallet systeme = compteSysteme(dest.getDevise());
        WalletOperation op = enregistrer(d.idempotencyKey(), TypeOperationWallet.CREDIT, d.montant(),
                systeme.getId(), dest.getId(), d.reference(), d.libelle(), null, null, null);
        auditer("WalletCredited", dest.getId(), d.montant());
        return op;
    }

    /** Crédit de récompense (BONUS/CASHBACK) depuis un compte système dédié (double écriture). */
    private WalletOperation crediterRecompenseInterne(DemandeOperationWallet d, String refSysteme,
                                                      String evenement) {
        Wallet dest = trouver(d.destWalletId());
        ReglesWallet.verifierMontant(d.montant());
        Wallet systeme = compteSystemeNomme(refSysteme, dest.getDevise());
        WalletOperation op = enregistrer(d.idempotencyKey(), d.type(), d.montant(),
                systeme.getId(), dest.getId(), d.reference(), d.libelle(), null,
                d.campagneCode(), d.operationSourceId());
        auditer(evenement, dest.getId(), d.montant());
        return op;
    }

    /** Reprise (clawback) : débit du wallet vers SYSTEME-CASHBACK, SANS contrôle d'overdraft
     *  (solde peut devenir négatif = dette). Aucun PIN/limite/fraude (acte système). */
    private WalletOperation reprendreCashbackInterne(DemandeOperationWallet d) {
        Wallet source = verrouiller(d.sourceWalletId());
        Wallet systeme = compteSystemeNomme("SYSTEME-CASHBACK", source.getDevise());
        WalletOperation op = enregistrer(d.idempotencyKey(), TypeOperationWallet.CASHBACK_ANNULATION,
                d.montant(), source.getId(), systeme.getId(), d.reference(), d.libelle(), null,
                d.campagneCode(), d.operationSourceId());
        auditer("WalletCashbackReversed", source.getId(), d.montant());
        return op;
    }

    private WalletOperation debiterInterne(DemandeOperationWallet d) {
        Wallet source = verrouiller(d.sourceWalletId());
        ReglesWallet.verifierDebit(source.getStatut(), solde(source.getId()), d.montant());
        appliquerDetectionFraude(source.getId(), d.montant(), d);
        Wallet systeme = compteSysteme(source.getDevise());
        WalletOperation op = enregistrer(d.idempotencyKey(), TypeOperationWallet.DEBIT, d.montant(),
                source.getId(), systeme.getId(), d.reference(), d.libelle(), null, null, null);
        auditer("WalletDebited", source.getId(), d.montant());
        return op;
    }

    private WalletOperation transfererInterne(DemandeOperationWallet d) {
        if (d.sourceWalletId().equals(d.destWalletId())) {
            throw new OperationWalletInvalideException("Source et destination doivent différer.");
        }
        Wallet source = verrouiller(d.sourceWalletId());
        Wallet dest = trouver(d.destWalletId());
        memeDevise(source, dest);
        ReglesWallet.verifierDebit(source.getStatut(), solde(source.getId()), d.montant());
        appliquerDetectionFraude(source.getId(), d.montant(), d);
        WalletOperation op = enregistrer(d.idempotencyKey(), TypeOperationWallet.TRANSFERT, d.montant(),
                source.getId(), dest.getId(), null, d.libelle(), null, null, null);
        auditer("WalletDebited", source.getId(), d.montant());
        auditer("WalletCredited", dest.getId(), d.montant());
        return op;
    }

    private WalletOperation payerFactureInterne(DemandeOperationWallet d) {
        Wallet source = verrouiller(d.sourceWalletId());
        Facture facture = facturation.trouver(d.factureId());
        long du = facture.getResteAPayer() - facture.getMontantRegle();
        long montant = d.montant() > 0 ? d.montant() : du;
        if (montant <= 0 || montant > du) {
            throw new OperationWalletInvalideException(
                    "Montant à régler invalide (dû restant = " + Math.max(du, 0) + ").");
        }
        Wallet etab = compteEtablissement(facture.getEtablissementRef(), source.getDevise());
        ReglesWallet.verifierDebit(source.getStatut(), solde(source.getId()), montant);
        appliquerDetectionFraude(source.getId(), montant, d);

        WalletOperation op = enregistrer(d.idempotencyKey(), TypeOperationWallet.PAIEMENT_FACTURE, montant,
                source.getId(), etab.getId(), facture.getNumero(), "Paiement facture", facture.getId(),
                null, null);
        facturation.enregistrerReglement(facture.getId(), montant); // met à jour EMISE→…→PAYEE
        auditer("WalletDebited", source.getId(), montant);
        return op;
    }

    /** Crée l'opération + ses DEUX écritures (source −montant, dest +montant → somme 0), puis la signe. */
    private WalletOperation enregistrer(String cle, TypeOperationWallet type, long montant,
                                        UUID sourceId, UUID destId, String ref, String libelle,
                                        UUID factureId, String campagneCode, UUID operationSourceId) {
        WalletOperation nouvelle = new WalletOperation(cle, type, montant, sourceId, destId, ref, libelle,
                factureId);
        nouvelle.rattacher(campagneCode, operationSourceId); // posé avant l'insert (colonnes non updatable)
        WalletOperation op = operations.save(nouvelle);
        entries.save(new WalletEntry(op.getId(), sourceId, -montant));
        entries.save(new WalletEntry(op.getId(), destId, montant));
        signer(op);
        return op;
    }

    /** Signature d'opération (§6.4) « prête à activer ». Sans effet si la signature est désactivée. */
    private void signer(WalletOperation op) {
        if (!signature.estActif()) {
            return;
        }
        String empreinte = op.getId() + "|" + op.getType() + "|" + op.getMontant() + "|"
                + op.getSourceWalletId() + "|" + op.getDestWalletId() + "|" + op.getIdempotencyKey();
        signature.signer(empreinte).ifPresent(sceau -> {
            op.apposerSignature(sceau.signature());
            operations.save(op);
        });
    }

    private Wallet changerStatut(UUID id, WalletStatut statut, String evenement) {
        Wallet w = trouver(id);
        w.setStatut(statut);
        wallets.save(w);
        audit.enregistrer(evenement, "wallet", w.getId().toString(), Map.of("statut", statut.name()));
        return w;
    }

    private void auditer(String evenement, UUID walletId, long montant) {
        audit.enregistrer(evenement, "wallet", walletId.toString(),
                Map.of("montant", montant, "solde", solde(walletId)));
    }

    private Wallet verrouiller(UUID id) {
        return wallets.findByIdVerrouille(id)
                .orElseThrow(() -> new WalletIntrouvableException(id.toString()));
    }

    /**
     * Gel de fraude à TTL expiré → auto-dégel (§6.4 : gel « temporaire », pas de blocage éternel).
     * Exécuté dans une transaction PROPRE, committée AVANT l'opération verrouillée : ainsi le dégel
     * survit même si l'opération qui suit rollback (ex. re-déclenchement d'un GEL). Hors verrou → pas
     * d'interblocage. Idempotent (sans effet si le wallet n'est pas un gel expiré).
     */
    @Transactional
    public void autoDegelSiExpire(UUID walletId) {
        wallets.findById(walletId).ifPresent(w -> {
            if (w.gelExpire(Instant.now())) {
                w.setStatut(WalletStatut.ACTIF); // efface aussi gel_jusqu_a
                wallets.save(w);
                audit.enregistrer("WalletUnfrozenAuto", "wallet", w.getId().toString(),
                        Map.of("cause", "TTL_EXPIRE"));
            }
        });
    }

    /**
     * Détection de fraude (§6.4), évaluée SOUS le verrou du wallet (concurrence sérialisée) :
     * <ul><li>ALERTE : on enregistre l'alerte, l'opération passe ;</li>
     * <li>CHALLENGE : re-auth OTP (si pas déjà exigée par le montant), puis l'opération passe ;</li>
     * <li>GEL : on lève {@link FraudSuspecteeException} → l'opération est annulée et le gel + l'alerte
     * sont posés hors transaction par {@code executerAvecFraude}.</li></ul>
     */
    private void appliquerDetectionFraude(UUID walletId, long montant, DemandeOperationWallet d) {
        ResultatFraude rf = detection.evaluer(walletId, montant);
        switch (rf.palier()) {
            case NORMAL -> { /* rien */ }
            case ALERTE -> detection.enregistrerAlerte(walletId, rf, montant);
            case CHALLENGE -> {
                if (!d.otpDejaVerifie()) {
                    securite.exigerOtp(walletId, d.otp());
                }
                detection.enregistrerAlerte(walletId, rf, montant);
            }
            case GEL -> throw new FraudSuspecteeException(walletId, montant, rf);
        }
    }

    private void memeDevise(Wallet a, Wallet b) {
        if (!a.getDevise().equals(b.getDevise())) {
            throw new OperationWalletInvalideException("Devises incompatibles entre les portefeuilles.");
        }
    }

    /** Compte technique de contrepartie (assure la double écriture des crédits/débits externes). */
    private Wallet compteSysteme(String devise) {
        return compteSystemeNomme(CONTREPARTIE, devise);
    }

    /** Compte système nommé (SYSTEME-CONTREPARTIE, SYSTEME-CASHBACK, SYSTEME-BONUS…), créé au besoin. */
    private Wallet compteSystemeNomme(String ref, String devise) {
        return wallets.findByOwnerRefAndOwnerTypeAndDevise(ref, OwnerTypeWallet.SYSTEME, devise)
                .orElseGet(() -> wallets.save(new Wallet(ref, OwnerTypeWallet.SYSTEME, devise)));
    }

    // --- sous-soldes de récompense (dérivés, §6.1) --------------------------------------------

    @Transactional(readOnly = true)
    public long totalCashbackNet(UUID walletId) {
        return operations.sommeRecueParType(walletId, TypeOperationWallet.CASHBACK)
                - operations.sommeClawbackDe(walletId);
    }

    @Transactional(readOnly = true)
    public long totalBonus(UUID walletId) {
        return operations.sommeRecueParType(walletId, TypeOperationWallet.BONUS);
    }

    private Wallet compteEtablissement(String etablissementRef, String devise) {
        return wallets.findByOwnerRefAndOwnerTypeAndDevise(etablissementRef, OwnerTypeWallet.ETABLISSEMENT, devise)
                .orElseGet(() -> wallets.save(new Wallet(etablissementRef, OwnerTypeWallet.ETABLISSEMENT, devise)));
    }
}
