package ci.masante.payment.service;

import ci.masante.payment.domain.model.CommissionConfig;
import ci.masante.payment.domain.model.DestinationReversement;
import ci.masante.payment.domain.model.EcritureReversement;
import ci.masante.payment.domain.model.LigneGrandLivre;
import ci.masante.payment.domain.model.LigneReversement;
import ci.masante.payment.domain.model.ReversementCompteur;
import ci.masante.payment.domain.model.ReversementReleve;
import ci.masante.payment.domain.model.ReversementStatut;
import ci.masante.payment.domain.model.SensEcriture;
import ci.masante.payment.domain.model.TypeEcriture;
import ci.masante.payment.domain.reversement.EncaissementImputable;
import ci.masante.payment.domain.reversement.JambeCalculee;
import ci.masante.payment.domain.reversement.LigneCalculeeReversement;
import ci.masante.payment.domain.reversement.ReglesEcritureReversement;
import ci.masante.payment.domain.reversement.RemboursementImputable;
import ci.masante.payment.domain.reversement.ReglesReversement;
import ci.masante.payment.domain.reversement.ResultatReversement;
import ci.masante.payment.repository.CarteRemboursementRepository;
import ci.masante.payment.repository.EcritureReversementRepository;
import ci.masante.payment.repository.FactureRepository;
import ci.masante.payment.repository.LigneGrandLivreRepository;
import ci.masante.payment.repository.LigneReversementRepository;
import ci.masante.payment.repository.ReversementCompteurRepository;
import ci.masante.payment.repository.ReversementReleveRepository;
import org.springframework.stereotype.Service;
import org.springframework.transaction.annotation.Transactional;

import java.nio.charset.StandardCharsets;
import java.security.MessageDigest;
import java.security.NoSuchAlgorithmException;
import java.time.Instant;
import java.time.LocalDate;
import java.time.ZoneId;
import java.time.ZoneOffset;
import java.util.List;
import java.util.Map;
import java.util.Optional;
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
    private final ServiceDestinationReversement destinations;
    private final EcritureReversementRepository ecritures;
    private final LigneGrandLivreRepository lignesGL;
    private final ServiceAudit audit;

    private static final ZoneId ABIDJAN = ZoneId.of("Africa/Abidjan");

    public ServiceReversement(ReversementReleveRepository releves, LigneReversementRepository lignes,
                              ReversementCompteurRepository compteurs, FactureRepository factures,
                              CarteRemboursementRepository remboursements,
                              ServiceCommissionConfig commissionConfig,
                              ServiceDestinationReversement destinations,
                              EcritureReversementRepository ecritures, LigneGrandLivreRepository lignesGL,
                              ServiceAudit audit) {
        this.releves = releves;
        this.lignes = lignes;
        this.compteurs = compteurs;
        this.factures = factures;
        this.remboursements = remboursements;
        this.commissionConfig = commissionConfig;
        this.destinations = destinations;
        this.ecritures = ecritures;
        this.lignesGL = lignesGL;
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
        // Snapshot de l'empreinte de la destination active AU CALCUL (terme gauche du contrôle
        // anti-substitution à l'approbation). Null si aucune destination active à ce stade.
        destinations.active(etablissementRef)
                .ifPresent(d -> releve.poserEmpreinteCalcul(d.getEmpreinte()));
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

    /**
     * Approbation (CALCULE → APPROUVE) par un approbateur ADMIN_FINANCE (rôle vérifié au contrôleur via
     * le principal signé). Quatre-yeux : l'approbateur ≠ le calculateur (CHECK base + ici) ET ≠ le
     * créateur de la destination (inter-tables → Java). Anti-substitution : la destination active doit
     * être IDENTIQUE (empreinte) à celle figée au calcul, sinon rejet → recalcul. Fige la destination et
     * poste l'écriture de CONSTATATION de la dette.
     */
    @Transactional
    public ReversementReleve approuver(UUID id, String approbateur) {
        ReversementReleve releve = trouver(id);
        if (releve.getStatut() != ReversementStatut.CALCULE) {
            throw new IllegalStateException("Seul un relevé CALCULE peut être approuvé (état : " + releve.getStatut() + ").");
        }
        if (approbateur.equals(releve.getCalculePar())) {
            throw new IllegalStateException("Quatre-yeux : l'approbateur ne peut pas être le calculateur.");
        }
        DestinationReversement dest = destinations.active(releve.getEtablissementRef())
                .orElseThrow(() -> new IllegalStateException(
                        "Aucune destination de versement active pour cet établissement : en ouvrir une avant approbation."));
        if (approbateur.equals(dest.getCreePar())) {
            throw new IllegalStateException("Quatre-yeux : l'approbateur ne peut pas être le créateur de la destination.");
        }
        if (releve.getDestinationEmpreinteCalcul() == null
                || !releve.getDestinationEmpreinteCalcul().equals(dest.getEmpreinte())) {
            throw new IllegalStateException(
                    "La destination a changé depuis le calcul (ou était absente) : recalculer le relevé.");
        }

        Instant maintenant = Instant.now();
        releve.approuver(approbateur, maintenant, dest.getId(), dest.getEmpreinte(), maintenant);
        releves.save(releve);

        // Écriture de constatation de la dette (partie double, équilibrée par construction).
        List<JambeCalculee> jambes = ReglesEcritureReversement.constatation(
                releve.getMontantBrutDu(), releve.getMontantCommission(), releve.getMontantRembourse(),
                releve.getMontantNetAReverser(), releve.getReportAnterieur(), releve.getSoldeReporte());
        posterEcriture(releve.getId(), TypeEcriture.CONSTATATION, jambes, null, approbateur);

        audit.enregistrer("SettlementApproved", "settlement", releve.getId().toString(),
                Map.of("numero", releve.getNumero(), "approbateur", approbateur,
                        "destination", dest.getId().toString(), "jambes", jambes.size()));
        return releve;
    }

    /** Rejet par l'approbateur (CALCULE → REJETE) : libère les pièces, aucune écriture comptable. */
    @Transactional
    public ReversementReleve rejeter(UUID id, String approbateur, String motif) {
        ReversementReleve releve = trouver(id);
        if (releve.getStatut() != ReversementStatut.CALCULE) {
            throw new IllegalStateException("Seul un relevé CALCULE peut être rejeté (état : " + releve.getStatut() + ").");
        }
        if (motif == null || motif.isBlank()) {
            throw new IllegalArgumentException("Motif de rejet obligatoire.");
        }
        if (approbateur.equals(releve.getCalculePar())) {
            throw new IllegalStateException("Quatre-yeux : le rejet ne peut pas venir du calculateur.");
        }
        releve.rejeter(approbateur, Instant.now(), motif);
        releves.save(releve);
        libererPieces(releve.getId());
        audit.enregistrer("SettlementRejected", "settlement", releve.getId().toString(),
                Map.of("numero", releve.getNumero(), "approbateur", approbateur, "motif", motif));
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
        boolean etaitApprouve = releve.getStatut() == ReversementStatut.APPROUVE;
        releve.annuler(acteur, Instant.now(), motif);
        releves.save(releve);
        libererPieces(releve.getId());
        // Si la dette avait été constatée (relevé APPROUVE), la contre-passer (append-only : jamais d'UPDATE).
        if (etaitApprouve) {
            extournerConstatation(releve.getId(), acteur);
        }
        audit.enregistrer("SettlementCancelled", "settlement", releve.getId().toString(),
                Map.of("numero", releve.getNumero(), "motif", motif, "acteur", acteur, "extourne", etaitApprouve));
        return releve;
    }

    /** Désactive les lignes d'un relevé → libère les pièces (facture/remboursement) pour un recalcul. */
    private void libererPieces(UUID releveId) {
        for (LigneReversement l : lignes.findByReleveIdOrderByCreatedAtAsc(releveId)) {
            l.desactiver();
            lignes.save(l);
        }
    }

    /** Poste une écriture (en-tête + jambes). N'écrit rien si aucune jambe (relevé à montants nuls). */
    private void posterEcriture(UUID releveId, TypeEcriture type, List<JambeCalculee> jambes,
                                UUID extourneeId, String acteur) {
        if (jambes.isEmpty()) {
            return;
        }
        UUID ecritureId = UUID.randomUUID();
        ecritures.save(new EcritureReversement(ecritureId, releveId, type,
                LocalDate.now(ABIDJAN), extourneeId, acteur));
        for (JambeCalculee j : jambes) {
            lignesGL.save(new LigneGrandLivre(ecritureId, (short) j.sequence(), j.compte(), j.sens(),
                    j.montant(), j.libelle()));
        }
    }

    /** Contre-passe la constatation d'un relevé (écriture EXTOURNE inverse, référençant l'originale). */
    private void extournerConstatation(UUID releveId, String acteur) {
        Optional<EcritureReversement> constatation =
                ecritures.findByReleveIdAndTypeEcriture(releveId, TypeEcriture.CONSTATATION);
        if (constatation.isEmpty()) {
            return;
        }
        EcritureReversement cons = constatation.get();
        List<LigneGrandLivre> origine = lignesGL.findByEcritureIdOrderBySequenceAsc(cons.getEcritureId());
        UUID extId = UUID.randomUUID();
        ecritures.save(new EcritureReversement(extId, releveId, TypeEcriture.EXTOURNE,
                LocalDate.now(ABIDJAN), cons.getEcritureId(), acteur));
        short seq = 1;
        for (LigneGrandLivre l : origine) {
            SensEcriture inverse = l.getSens() == SensEcriture.DEBIT ? SensEcriture.CREDIT : SensEcriture.DEBIT;
            lignesGL.save(new LigneGrandLivre(extId, seq++, l.getCompte(), inverse, l.getMontant(),
                    "Extourne " + l.getCompte()));
        }
    }

    @Transactional(readOnly = true)
    public List<EcritureReversement> ecrituresDe(UUID releveId) {
        return ecritures.findByReleveIdOrderByCreeLeAsc(releveId);
    }

    @Transactional(readOnly = true)
    public List<LigneGrandLivre> jambesDe(UUID ecritureId) {
        return lignesGL.findByEcritureIdOrderBySequenceAsc(ecritureId);
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
