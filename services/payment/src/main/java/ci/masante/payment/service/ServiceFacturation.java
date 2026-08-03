package ci.masante.payment.service;

import ci.masante.payment.domain.billing.EntreeFacturation;
import ci.masante.payment.domain.billing.LigneCalculee;
import ci.masante.payment.domain.billing.MoteurFacturation;
import ci.masante.payment.domain.billing.ResultatFacturation;
import ci.masante.payment.domain.model.Avoir;
import ci.masante.payment.domain.model.AvoirCompteur;
import ci.masante.payment.domain.model.Facture;
import ci.masante.payment.domain.model.FactureCompteur;
import ci.masante.payment.domain.model.FactureLigne;
import ci.masante.payment.domain.model.FactureStatut;
import ci.masante.payment.repository.AvoirCompteurRepository;
import ci.masante.payment.repository.AvoirRepository;
import ci.masante.payment.repository.FactureCompteurRepository;
import ci.masante.payment.repository.FactureLigneRepository;
import ci.masante.payment.repository.FactureRepository;
import org.springframework.stereotype.Service;
import org.springframework.transaction.annotation.Transactional;

import java.nio.charset.StandardCharsets;
import java.security.MessageDigest;
import java.security.NoSuchAlgorithmException;
import java.time.Year;
import java.util.ArrayList;
import java.util.List;
import java.util.Map;
import java.util.UUID;

/**
 * Émission, règlement, correction (versionnée), annulation et signature des factures + avoirs
 * (CDC_06 §7). Tout le calcul délègue au {@link MoteurFacturation} (frontière). Numérotation unique
 * par établissement/exercice sous verrou ; chaque opération est auditée (chaîne P5.1) et signée
 * (§7.4, si la signature est active).
 */
@Service
public class ServiceFacturation {

    private final FactureRepository factures;
    private final FactureLigneRepository lignes;
    private final FactureCompteurRepository compteurs;
    private final AvoirRepository avoirs;
    private final AvoirCompteurRepository avoirCompteurs;
    private final ServiceAudit audit;
    private final ServiceSignature signature;

    public ServiceFacturation(FactureRepository factures, FactureLigneRepository lignes,
                              FactureCompteurRepository compteurs, AvoirRepository avoirs,
                              AvoirCompteurRepository avoirCompteurs, ServiceAudit audit,
                              ServiceSignature signature) {
        this.factures = factures;
        this.lignes = lignes;
        this.compteurs = compteurs;
        this.avoirs = avoirs;
        this.avoirCompteurs = avoirCompteurs;
        this.audit = audit;
        this.signature = signature;
    }

    @Transactional
    public Facture creer(EntreeFacturation entree) {
        int exercice = entree.exercice() != null ? entree.exercice() : Year.now().getValue();
        String devise = entree.devise() != null ? entree.devise() : "XOF";
        String numero = allouerNumeroFacture(entree.etablissementRef(), exercice);
        return construire(entree, numero, 1, null, exercice, devise, "InvoiceIssued");
    }

    @Transactional(readOnly = true)
    public Facture trouver(UUID id) {
        return factures.findById(id).orElseThrow(() -> new FactureIntrouvableException(id.toString()));
    }

    @Transactional(readOnly = true)
    public List<FactureLigne> lignesDe(UUID factureId) {
        return lignes.findByFactureIdOrderByOrdreAsc(factureId);
    }

    /** Impute un règlement à une facture et met à jour son statut (§7.3). Appelé dans la tx d'un paiement. */
    @Transactional
    public Facture enregistrerReglement(UUID factureId, long montant) {
        Facture facture = trouver(factureId);
        if (facture.getStatut() == FactureStatut.ANNULEE || facture.getStatut() == FactureStatut.REMPLACEE) {
            throw new IllegalStateException("Facture " + facture.getStatut() + " : règlement impossible.");
        }
        long cumul = facture.getMontantRegle() + montant;
        facture.setMontantRegle(cumul);
        facture.setStatut(cumul >= facture.getResteAPayer()
                ? FactureStatut.PAYEE : FactureStatut.PARTIELLEMENT_PAYEE);
        factures.save(facture);

        audit.enregistrer("InvoicePaymentApplied", "invoice", facture.getId().toString(),
                Map.of("montant", montant, "montantRegle", cumul, "statut", facture.getStatut().name()));
        return facture;
    }

    /**
     * Corrige une facture (§7.5) : crée une NOUVELLE version (même numéro, version+1), passe l'ancienne
     * en {@code REMPLACEE} et émet un avoir du TTC d'origine. Aucune facture n'est modifiée en place.
     */
    @Transactional
    public OperationFacture corriger(UUID factureId, List<ci.masante.payment.domain.billing.LigneEntree> nouvellesLignes,
                                     long remiseGlobale,
                                     ci.masante.payment.domain.billing.ParametresPriseEnCharge priseEnCharge,
                                     String motif) {
        Facture origine = trouver(factureId);
        garderModifiable(origine, "corrigée");

        UUID racine = origine.getOrigineFactureId() != null ? origine.getOrigineFactureId() : origine.getId();
        EntreeFacturation entree = new EntreeFacturation(origine.getEtablissementRef(), origine.getPatientRef(),
                origine.getExercice(), origine.getDevise(), nouvellesLignes, remiseGlobale, priseEnCharge);

        Facture nouvelle = construire(entree, origine.getNumero(), origine.getVersionNumero() + 1, racine,
                origine.getExercice(), origine.getDevise(), "InvoiceCorrected");

        origine.setStatut(FactureStatut.REMPLACEE);
        origine.setRemplaceeParId(nouvelle.getId());
        factures.save(origine);

        Avoir avoir = creerAvoir(origine, origine.getMontantTtc(),
                motif == null || motif.isBlank() ? "Correction facture " + origine.getNumero() : motif);
        return new OperationFacture(nouvelle, avoir);
    }

    /** Annule une facture (§7.1/§7.5) : passe en {@code ANNULEE} et émet un avoir du TTC. */
    @Transactional
    public OperationFacture annuler(UUID factureId, String motif) {
        Facture facture = trouver(factureId);
        garderModifiable(facture, "annulée");

        facture.setStatut(FactureStatut.ANNULEE);
        factures.save(facture);
        audit.enregistrer("InvoiceCancelled", "invoice", facture.getId().toString(),
                Map.of("numero", facture.getNumero(), "motif", motif == null ? "" : motif));

        Avoir avoir = creerAvoir(facture, facture.getMontantTtc(),
                motif == null || motif.isBlank() ? "Annulation facture " + facture.getNumero() : motif);
        return new OperationFacture(facture, avoir);
    }

    /** Toutes les versions d'une lignée (origine incluse), triées par numéro de version. */
    @Transactional(readOnly = true)
    public List<Facture> versions(UUID factureId) {
        Facture f = trouver(factureId);
        UUID racine = f.getOrigineFactureId() != null ? f.getOrigineFactureId() : f.getId();
        List<Facture> toutes = new ArrayList<>();
        toutes.add(factures.findById(racine).orElseThrow(() -> new FactureIntrouvableException(racine.toString())));
        toutes.addAll(factures.findByOrigineFactureIdOrderByVersionNumeroAsc(racine));
        return toutes;
    }

    @Transactional(readOnly = true)
    public Avoir trouverAvoir(UUID id) {
        return avoirs.findById(id).orElseThrow(() -> new AvoirIntrouvableException(id.toString()));
    }

    @Transactional(readOnly = true)
    public List<Avoir> avoirsDe(UUID factureId) {
        return avoirs.findByFactureIdOrderByCreatedAtAsc(factureId);
    }

    /** Vérifie l'intégrité (hash recalculé) et la signature d'une facture (§7.4). */
    @Transactional(readOnly = true)
    public VerificationSignature verifierSignatureFacture(UUID factureId) {
        Facture f = trouver(factureId);
        String recalcule = hashFacture(f.getNumero(), f.getMontantTtc(), f.getResteAPayer(),
                f.getPatientRef(), f.getEtablissementRef());
        boolean integre = recalcule.equals(f.getHashIntegrite());
        boolean signee = f.getSignature() != null;
        boolean valide = signee && signature.verifier(f.getHashIntegrite(), f.getSignature(), f.getSignaturePubkey());
        return new VerificationSignature(integre, signee, valide, f.getSignatureAlgo());
    }

    // --- interne ------------------------------------------------------------------------------

    /** Construit, signe, persiste une facture (+ ses lignes) et l'audite. */
    private Facture construire(EntreeFacturation entree, String numero, int version, UUID origine,
                               int exercice, String devise, String evenementAudit) {
        ResultatFacturation r = MoteurFacturation.calculer(entree);
        FactureStatut statut = r.resteAPayer() == 0 ? FactureStatut.PAYEE : FactureStatut.EMISE;
        String hash = hashFacture(numero, r.montantTtc(), r.resteAPayer(),
                entree.patientRef(), entree.etablissementRef());

        Facture facture = new Facture(
                numero, entree.etablissementRef(), entree.patientRef(), exercice, devise,
                r.sousTotalHt(), r.totalRemises(), r.totalTva(), r.montantTtc(),
                r.couvertureType(), r.couvertureTaux(), r.montantCouvert(), r.resteAPayer(), statut, hash);
        facture.setVersionNumero(version);
        facture.setOrigineFactureId(origine);
        signature.signer(hash).ifPresent(s -> facture.apposerSignature(s.signature(), s.cléPublique(), s.algorithme()));
        factures.save(facture); // @UuidGenerator renseigne l'id sur l'instance lors du persist

        int ordre = 1;
        for (LigneCalculee l : r.lignes()) {
            lignes.save(new FactureLigne(facture.getId(), ordre++, l.libelle(), l.quantite(),
                    l.prixUnitaire(), l.remise(), l.tauxTva(), l.montantHt(), l.montantTva(), l.montantTtc()));
        }
        audit.enregistrer(evenementAudit, "invoice", facture.getId().toString(),
                Map.of("numero", numero, "version", version, "montantTtc", r.montantTtc(),
                        "resteAPayer", r.resteAPayer()));
        return facture;
    }

    private Avoir creerAvoir(Facture facture, long montant, String motif) {
        String numero = allouerNumeroAvoir(facture.getEtablissementRef(), facture.getExercice());
        String hash = hashAvoir(numero, montant, facture.getId(), motif);
        Avoir avoir = new Avoir(numero, facture.getId(), facture.getEtablissementRef(),
                facture.getExercice(), montant, motif, hash);
        signature.signer(hash).ifPresent(s -> avoir.apposerSignature(s.signature(), s.cléPublique(), s.algorithme()));
        avoirs.save(avoir);
        audit.enregistrer("CreditNoteIssued", "credit_note", avoir.getId().toString(),
                Map.of("numero", numero, "factureId", facture.getId().toString(), "montant", montant));
        return avoir;
    }

    private static void garderModifiable(Facture f, String action) {
        if (f.getStatut() == FactureStatut.ANNULEE || f.getStatut() == FactureStatut.REMPLACEE) {
            throw new IllegalStateException("Facture " + f.getStatut() + " : elle ne peut pas être " + action + ".");
        }
    }

    private String allouerNumeroFacture(String etablissementRef, int exercice) {
        FactureCompteur compteur = compteurs.trouverVerrouille(etablissementRef, exercice)
                .orElseGet(() -> compteurs.save(new FactureCompteur(etablissementRef, exercice)));
        long seq = compteur.prochain();
        compteurs.save(compteur);
        return "FCT-%s-%d-%06d".formatted(codeEtab(etablissementRef), exercice, seq);
    }

    private String allouerNumeroAvoir(String etablissementRef, int exercice) {
        AvoirCompteur compteur = avoirCompteurs.trouverVerrouille(etablissementRef, exercice)
                .orElseGet(() -> avoirCompteurs.save(new AvoirCompteur(etablissementRef, exercice)));
        long seq = compteur.prochain();
        avoirCompteurs.save(compteur);
        return "AV-%s-%d-%06d".formatted(codeEtab(etablissementRef), exercice, seq);
    }

    private static String codeEtab(String ref) {
        String nettoye = ref == null ? "" : ref.replaceAll("[^A-Za-z0-9]", "").toUpperCase();
        if (nettoye.isEmpty()) {
            nettoye = "ETAB";
        }
        return nettoye.length() > 12 ? nettoye.substring(0, 12) : nettoye;
    }

    private static String hashFacture(String numero, long ttc, long reste, String patient, String etab) {
        return sha256(numero + "|" + ttc + "|" + reste + "|" + nz(patient) + "|" + nz(etab));
    }

    private static String hashAvoir(String numero, long montant, UUID factureId, String motif) {
        return sha256(numero + "|" + montant + "|" + factureId + "|" + motif);
    }

    private static String nz(String s) {
        return s == null ? "" : s;
    }

    private static String sha256(String canonique) {
        try {
            byte[] d = MessageDigest.getInstance("SHA-256").digest(canonique.getBytes(StandardCharsets.UTF_8));
            StringBuilder hex = new StringBuilder(64);
            for (byte b : d) {
                hex.append(Character.forDigit((b >> 4) & 0xF, 16)).append(Character.forDigit(b & 0xF, 16));
            }
            return hex.toString();
        } catch (NoSuchAlgorithmException e) {
            throw new IllegalStateException("SHA-256 indisponible", e);
        }
    }
}
