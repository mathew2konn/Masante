package ci.masante.payment.service;

import ci.masante.payment.domain.model.CommissionConfig;
import ci.masante.payment.domain.model.LigneReversement;
import ci.masante.payment.domain.model.ReversementCompteur;
import ci.masante.payment.domain.model.ReversementReleve;
import ci.masante.payment.domain.model.ReversementStatut;
import ci.masante.payment.domain.reversement.EncaissementImputable;
import ci.masante.payment.domain.reversement.LigneCalculeeReversement;
import ci.masante.payment.domain.reversement.RemboursementImputable;
import ci.masante.payment.domain.reversement.ReglesReversement;
import ci.masante.payment.domain.reversement.ResultatReversement;
import ci.masante.payment.repository.CarteRemboursementRepository;
import ci.masante.payment.repository.FactureRepository;
import ci.masante.payment.repository.LigneReversementRepository;
import ci.masante.payment.repository.ReversementCompteurRepository;
import ci.masante.payment.repository.ReversementReleveRepository;
import org.springframework.stereotype.Service;
import org.springframework.transaction.annotation.Transactional;

import java.nio.charset.StandardCharsets;
import java.security.MessageDigest;
import java.security.NoSuchAlgorithmException;
import java.time.Instant;
import java.time.ZoneOffset;
import java.util.List;
import java.util.Map;
import java.util.UUID;

/**
 * Calcul, approbation et annulation des relevés de reversement (CDC_06 §11). Frontière : tout le calcul
 * délègue à {@link ReglesReversement} (règle pure). Le décaissement, le grand livre et la destination
 * sont hors périmètre P5.5a (→ P5.5b/V11).
 *
 * <h2>Ordre de verrou (ADR-016 §4)</h2>
 * {@code calculerReleve} verrouille la ligne de compteur AVANT de lire l'assiette : cela sérialise
 * calcul + numérotation + chaînage de report par établissement, et évite qu'une exécution concurrente
 * ne lise la même assiette pour la rejeter ensuite par violation brute de {@code uq_ligne_*} (I1).
 * Séquence : {@code INSERT compteur ON CONFLICT DO NOTHING → SELECT … FOR UPDATE → lecture assiette →
 * calcul → écriture}.
 */
@Service
public class ServiceReversement {

    private final ReversementReleveRepository releves;
    private final LigneReversementRepository lignes;
    private final ReversementCompteurRepository compteurs;
    private final FactureRepository factures;
    private final CarteRemboursementRepository remboursements;
    private final ServiceCommissionConfig commissionConfig;
    private final ServiceAudit audit;

    public ServiceReversement(ReversementReleveRepository releves, LigneReversementRepository lignes,
                              ReversementCompteurRepository compteurs, FactureRepository factures,
                              CarteRemboursementRepository remboursements,
                              ServiceCommissionConfig commissionConfig, ServiceAudit audit) {
        this.releves = releves;
        this.lignes = lignes;
        this.compteurs = compteurs;
        this.factures = factures;
        this.remboursements = remboursements;
        this.commissionConfig = commissionConfig;
        this.audit = audit;
    }

    @Transactional
    public ReversementReleve calculerReleve(String etablissementRef, Instant periodeDebut,
                                            Instant periodeFin, Instant cutOff, String acteur) {
        if (periodeDebut == null || periodeFin == null || !periodeDebut.isBefore(periodeFin)) {
            throw new IllegalArgumentException("Fenêtre invalide : periode_debut doit précéder periode_fin.");
        }
        Instant cutOffT = cutOff != null ? cutOff : periodeFin;
        if (cutOffT.isBefore(periodeFin)) {
            throw new IllegalArgumentException("Le cut-off doit être ≥ à la fin de période.");
        }
        int exercice = periodeDebut.atZone(ZoneOffset.UTC).getYear();

        // Conflit explicite (plutôt qu'une violation brute d'index) si un relevé vivant couvre déjà
        // exactement cette période.
        if (releves.existsByEtablissementRefAndPeriodeDebutAndPeriodeFinAndStatutNot(
                etablissementRef, periodeDebut, periodeFin, ReversementStatut.ANNULE)) {
            throw new IllegalStateException("Un relevé actif couvre déjà cette période pour cet établissement.");
        }

        // 1-2. Ordre de verrou : créer si absent puis verrouiller AVANT de lire l'assiette.
        compteurs.creerSiAbsent(etablissementRef, exercice);
        ReversementCompteur compteur = compteurs.trouverVerrouille(etablissementRef, exercice)
                .orElseThrow(() -> new IllegalStateException("Compteur de reversement introuvable après création."));

        // 3. Taux résolu (donnée) + report chaîné (queue de la chaîne vivante).
        CommissionConfig config = commissionConfig.resoudre(etablissementRef);
        List<ReversementReleve> queue = releves.trouverQueue(etablissementRef);
        if (queue.size() > 1) {
            throw new IllegalStateException("Chaîne de reversement incohérente : plusieurs queues vivantes.");
        }
        ReversementReleve precedent = queue.isEmpty() ? null : queue.get(0);
        long reportAnterieur = precedent == null ? 0L : precedent.getSoldeReporte();

        // 4-5. Assiette (borne haute seule → rattrapage) puis calcul pur.
        List<EncaissementImputable> encaissements = factures.encaissementsImputables(etablissementRef, periodeFin);
        List<RemboursementImputable> rembs = remboursements.remboursementsImputables(etablissementRef, periodeFin);
        ResultatReversement r = ReglesReversement.calculer(encaissements, rembs, config.getTauxBps(), reportAnterieur);

        // 6. Numérotation (sous verrou) + tentative + hash + persistance.
        long tentative = releves.countByEtablissementRefAndPeriodeDebutAndPeriodeFin(
                etablissementRef, periodeDebut, periodeFin) + 1L;
        long seq = compteur.prochain();
        compteurs.save(compteur);
        String numero = "REV-%s-%d-%06d".formatted(codeEtab(etablissementRef), exercice, seq);
        String hash = hashReleve(numero, etablissementRef, periodeDebut, periodeFin,
                r.montantBrutDu(), r.montantCommission(), r.montantRembourse(),
                r.montantNetAReverser(), r.soldeReporte(), r.reportAnterieur());

        ReversementReleve releve = new ReversementReleve(numero, etablissementRef, exercice, periodeDebut,
                periodeFin, cutOffT, (int) tentative, "XOF", r.montantBrutDu(), r.tauxCommissionBps(),
                config.getId(), r.montantCommission(), r.montantRembourse(), r.reportAnterieur(),
                r.montantNetAReverser(), r.soldeReporte(), precedent == null ? null : precedent.getId(),
                hash, acteur);
        releves.save(releve);

        for (LigneCalculeeReversement l : r.lignes()) {
            lignes.save(new LigneReversement(releve.getId(), l.type(), l.factureId(), l.remboursementId(),
                    l.pieceReference(), l.pieceDateeA(), l.montantRegleImpute(), l.montantCommissionLigne(),
                    l.montantRembourseImpute(), l.montantNetLigne()));
        }

        audit.enregistrer("SettlementCalculated", "settlement", releve.getId().toString(),
                Map.of("numero", numero, "etablissement", etablissementRef, "brut", r.montantBrutDu(),
                        "commission", r.montantCommission(), "rembourse", r.montantRembourse(),
                        "net", r.montantNetAReverser(), "soldeReporte", r.soldeReporte(),
                        "tentative", tentative, "acteur", acteur));
        return releve;
    }

    /** Approbation (CALCULE → APPROUVE). Quatre-yeux + destination = P5.5b. */
    @Transactional
    public ReversementReleve approuver(UUID id, String acteur) {
        ReversementReleve releve = trouver(id);
        if (releve.getStatut() != ReversementStatut.CALCULE) {
            throw new IllegalStateException("Seul un relevé CALCULE peut être approuvé (état : " + releve.getStatut() + ").");
        }
        releve.approuver(acteur, Instant.now());
        releves.save(releve);
        audit.enregistrer("SettlementApproved", "settlement", releve.getId().toString(),
                Map.of("numero", releve.getNumero(), "acteur", acteur));
        return releve;
    }

    /**
     * Annulation (depuis CALCULE ou APPROUVE, rien d'exécuté). Interdite si le relevé a un successeur
     * vivant : seul le DERNIER maillon de la chaîne est annulable (le report en dépend — ADR-016 §2).
     * Désactive les lignes → libère les pièces pour un recalcul (tentative+1).
     */
    @Transactional
    public ReversementReleve annuler(UUID id, String motif, String acteur) {
        ReversementReleve releve = trouver(id);
        if (releve.getStatut() != ReversementStatut.CALCULE && releve.getStatut() != ReversementStatut.APPROUVE) {
            throw new IllegalStateException("Annulation impossible dans l'état " + releve.getStatut() + ".");
        }
        if (motif == null || motif.isBlank()) {
            throw new IllegalArgumentException("Motif d'annulation obligatoire.");
        }
        if (releves.compterSuccesseursVivants(releve.getId()) > 0) {
            throw new IllegalStateException(
                    "Relevé avec successeur actif : annuler d'abord le dernier relevé de la chaîne.");
        }
        releve.annuler(acteur, Instant.now(), motif);
        releves.save(releve);
        for (LigneReversement l : lignes.findByReleveIdOrderByCreatedAtAsc(releve.getId())) {
            l.desactiver();
            lignes.save(l);
        }
        audit.enregistrer("SettlementCancelled", "settlement", releve.getId().toString(),
                Map.of("numero", releve.getNumero(), "motif", motif, "acteur", acteur));
        return releve;
    }

    @Transactional(readOnly = true)
    public ReversementReleve trouver(UUID id) {
        return releves.findById(id).orElseThrow(() -> new ReversementIntrouvableException(id.toString()));
    }

    @Transactional(readOnly = true)
    public List<LigneReversement> lignesDe(UUID releveId) {
        return lignes.findByReleveIdOrderByCreatedAtAsc(releveId);
    }

    @Transactional(readOnly = true)
    public List<ReversementReleve> lister(String etablissementRef, Integer exercice) {
        return exercice == null
                ? releves.findByEtablissementRefOrderByCalculeAAsc(etablissementRef)
                : releves.findByEtablissementRefAndExerciceOrderByCalculeAAsc(etablissementRef, exercice);
    }

    private static String codeEtab(String ref) {
        String nettoye = ref == null ? "" : ref.replaceAll("[^A-Za-z0-9]", "").toUpperCase();
        if (nettoye.isEmpty()) {
            nettoye = "ETAB";
        }
        return nettoye.length() > 12 ? nettoye.substring(0, 12) : nettoye;
    }

    private static String hashReleve(String numero, String etab, Instant debut, Instant fin, long brut,
                                     long commission, long rembourse, long net, long solde, long report) {
        String canonique = String.join("|", numero, etab, debut.toString(), fin.toString(),
                Long.toString(brut), Long.toString(commission), Long.toString(rembourse),
                Long.toString(net), Long.toString(solde), Long.toString(report));
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
