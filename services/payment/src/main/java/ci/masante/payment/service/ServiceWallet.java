package ci.masante.payment.service;

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
    private final ServiceWallet self;

    public ServiceWallet(WalletRepository wallets, WalletOperationRepository operations,
                         WalletEntryRepository entries, ServiceIdempotence idempotence,
                         ServiceAudit audit, ServiceFacturation facturation,
                         @Lazy ServiceWallet self) {
        this.wallets = wallets;
        this.operations = operations;
        this.entries = entries;
        this.idempotence = idempotence;
        this.audit = audit;
        this.facturation = facturation;
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
        return soumettre(new DemandeOperationWallet(
                TypeOperationWallet.CREDIT, null, walletId, montant, ref, libelle, null, cle));
    }

    public WalletOperation debiter(UUID walletId, long montant, String ref, String libelle, String cle) {
        return soumettre(new DemandeOperationWallet(
                TypeOperationWallet.DEBIT, walletId, null, montant, ref, libelle, null, cle));
    }

    public WalletOperation transferer(UUID sourceId, UUID destId, long montant, String libelle, String cle) {
        return soumettre(new DemandeOperationWallet(
                TypeOperationWallet.TRANSFERT, sourceId, destId, montant, null, libelle, null, cle));
    }

    public WalletOperation payerFacture(UUID walletId, UUID factureId, long montant, String cle) {
        return soumettre(new DemandeOperationWallet(
                TypeOperationWallet.PAIEMENT_FACTURE, walletId, null, montant, null, null, factureId, cle));
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
        };
    }

    private WalletOperation crediterInterne(DemandeOperationWallet d) {
        Wallet dest = trouver(d.destWalletId());
        ReglesWallet.verifierMontant(d.montant());
        Wallet systeme = compteSysteme(dest.getDevise());
        WalletOperation op = enregistrer(d.idempotencyKey(), TypeOperationWallet.CREDIT, d.montant(),
                systeme.getId(), dest.getId(), d.reference(), d.libelle(), null);
        auditer("WalletCredited", dest.getId(), d.montant());
        return op;
    }

    private WalletOperation debiterInterne(DemandeOperationWallet d) {
        Wallet source = verrouiller(d.sourceWalletId());
        ReglesWallet.verifierDebit(source.getStatut(), solde(source.getId()), d.montant());
        Wallet systeme = compteSysteme(source.getDevise());
        WalletOperation op = enregistrer(d.idempotencyKey(), TypeOperationWallet.DEBIT, d.montant(),
                source.getId(), systeme.getId(), d.reference(), d.libelle(), null);
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
        WalletOperation op = enregistrer(d.idempotencyKey(), TypeOperationWallet.TRANSFERT, d.montant(),
                source.getId(), dest.getId(), null, d.libelle(), null);
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

        WalletOperation op = enregistrer(d.idempotencyKey(), TypeOperationWallet.PAIEMENT_FACTURE, montant,
                source.getId(), etab.getId(), facture.getNumero(), "Paiement facture", facture.getId());
        facturation.enregistrerReglement(facture.getId(), montant); // met à jour EMISE→…→PAYEE
        auditer("WalletDebited", source.getId(), montant);
        return op;
    }

    /** Crée l'opération + ses DEUX écritures (source −montant, dest +montant → somme 0). */
    private WalletOperation enregistrer(String cle, TypeOperationWallet type, long montant,
                                        UUID sourceId, UUID destId, String ref, String libelle, UUID factureId) {
        WalletOperation op = operations.save(new WalletOperation(
                cle, type, montant, sourceId, destId, ref, libelle, factureId));
        entries.save(new WalletEntry(op.getId(), sourceId, -montant));
        entries.save(new WalletEntry(op.getId(), destId, montant));
        return op;
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
        return wallets.findByIdVerrouille(id).orElseThrow(() -> new WalletIntrouvableException(id.toString()));
    }

    private void memeDevise(Wallet a, Wallet b) {
        if (!a.getDevise().equals(b.getDevise())) {
            throw new OperationWalletInvalideException("Devises incompatibles entre les portefeuilles.");
        }
    }

    /** Compte technique de contrepartie (assure la double écriture des crédits/débits externes). */
    private Wallet compteSysteme(String devise) {
        return wallets.findByOwnerRefAndOwnerTypeAndDevise(CONTREPARTIE, OwnerTypeWallet.SYSTEME, devise)
                .orElseGet(() -> wallets.save(new Wallet(CONTREPARTIE, OwnerTypeWallet.SYSTEME, devise)));
    }

    private Wallet compteEtablissement(String etablissementRef, String devise) {
        return wallets.findByOwnerRefAndOwnerTypeAndDevise(etablissementRef, OwnerTypeWallet.ETABLISSEMENT, devise)
                .orElseGet(() -> wallets.save(new Wallet(etablissementRef, OwnerTypeWallet.ETABLISSEMENT, devise)));
    }
}
