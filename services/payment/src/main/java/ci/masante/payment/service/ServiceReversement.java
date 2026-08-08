package ci.masante.payment.service;

import ci.masante.payment.domain.model.CommissionConfig;
import ci.masante.payment.domain.model.DecaissementReversement;
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
import ci.masante.payment.domain.reversement.ReversementInvalideException;
import ci.masante.payment.domain.reversement.versement.DemandeDecaissement;
import ci.masante.payment.domain.reversement.versement.PasserelleReversement;
import ci.masante.payment.domain.reversement.versement.RegistrePasserellesReversement;
import ci.masante.payment.domain.reversement.versement.ResultatDecaissement;
import ci.masante.payment.repository.CarteRemboursementRepository;
import ci.masante.payment.repository.DecaissementReversementRepository;
import ci.masante.payment.repository.EcritureReversementRepository;
import ci.masante.payment.repository.FactureRepository;
import ci.masante.payment.repository.LigneGrandLivreRepository;
import ci.masante.payment.repository.LigneReversementRepository;
import ci.masante.payment.repository.ReversementCompteurRepository;
import ci.masante.payment.repository.ReversementReleveRepository;
import org.springframework.context.annotation.Lazy;
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
    private final DecaissementReversementRepository decaissements;
    private final RegistrePasserellesReversement passerelles;
    private final ServiceChiffrementDestination chiffrement;
    private final ServiceIdempotence idempotence;
    private final ServiceReversement self;
    private final ServiceAudit audit;

    private static final ZoneId ABIDJAN = ZoneId.of("Africa/Abidjan");

    public ServiceReversement(ReversementReleveRepository releves, LigneReversementRepository lignes,
                              ReversementCompteurRepository compteurs, FactureRepository factures,
                              CarteRemboursementRepository remboursements,
                              ServiceCommissionConfig commissionConfig,
                              ServiceDestinationReversement destinations,
                              EcritureReversementRepository ecritures, LigneGrandLivreRepository lignesGL,
                              DecaissementReversementRepository decaissements,
                              RegistrePasserellesReversement passerelles,
                              ServiceChiffrementDestination chiffrement, ServiceIdempotence idempotence,
                              @Lazy ServiceReversement self, ServiceAudit audit) {
        this.releves = releves;
        this.lignes = lignes;
        this.compteurs = compteurs;
        this.factures = factures;
        this.remboursements = remboursements;
        this.commissionConfig = commissionConfig;
        this.destinations = destinations;
        this.ecritures = ecritures;
        this.lignesGL = lignesGL;
        this.decaissements = decaissements;
        this.passerelles = passerelles;
        this.chiffrement = chiffrement;
        this.idempotence = idempotence;
        // Auto-référence via proxy : self.executerVersement(...) déclenche la transaction (un appel
        // this.executerVersement(...) court-circuiterait l'AOP transactionnelle).
        this.self = self;
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
     * Versement effectif d'un relevé approuvé (CDC_06 §11, P5.5b-2). Décaissement <b>SIMULÉ</b> (FT5) :
     * aucun virement réel. Anti-double-versement en profondeur : (1) verrou d'idempotence Redis
     * {@code Idempotency-Key}, (2) verrou pessimiste sur la ligne relevé + garde d'état, (3) unicité SGBD
     * (une écriture DÉCAISSEMENT et un décaissement EXECUTE par relevé). L'écriture de DÉCAISSEMENT n'est
     * postée qu'au succès (ECHOUE = rien n'est parti, rejouable avec une nouvelle clé).
     */
    public ReversementReleve verser(UUID id, String decaisseur, String cleIdempotence) {
        if (cleIdempotence == null || cleIdempotence.isBlank()) {
            throw new IllegalArgumentException("Idempotency-Key obligatoire pour un versement.");
        }
        // 1re barrière : verrou Redis. Si déjà détenu, un traitement de la même clé est en cours ou
        // vient de committer → on renvoie son résultat, sinon conflit.
        if (!idempotence.acquerir("rev:disb:" + cleIdempotence)) {
            return decaissements.findByIdempotencyKey(cleIdempotence)
                    .map(d -> trouver(d.getReleveId()))
                    .orElseThrow(() -> new ConflitIdempotenceException(cleIdempotence));
        }
        try {
            return self.executerVersement(id, decaisseur, cleIdempotence);
        } finally {
            idempotence.liberer("rev:disb:" + cleIdempotence);
        }
    }

    /** Cœur transactionnel du versement (proxifié pour l'AOP ; verrou Redis relâché après le commit). */
    @Transactional
    public ReversementReleve executerVersement(UUID id, String decaisseur, String cleIdempotence) {
        // Rejeu idempotent : la même clé a déjà produit une tentative → on ne re-verse pas.
        Optional<DecaissementReversement> dejaVu = decaissements.findByIdempotencyKey(cleIdempotence);
        if (dejaVu.isPresent()) {
            return trouver(dejaVu.get().getReleveId());
        }

        // Verrou pessimiste : sérialise les versements concurrents du même relevé.
        ReversementReleve releve = releves.findByIdVerrouille(id)
                .orElseThrow(() -> new ReversementIntrouvableException(id.toString()));
        if (releve.getStatut() != ReversementStatut.APPROUVE && releve.getStatut() != ReversementStatut.ECHOUE) {
            throw new IllegalStateException(
                    "Seul un relevé APPROUVE (ou ECHOUE, à rejouer) peut être versé (état : " + releve.getStatut() + ").");
        }
        long net = releve.getMontantNetAReverser();
        if (net <= 0) {
            throw new ReversementInvalideException("Relevé à net nul : rien à verser.");
        }
        // Séparation des tâches : le décaisseur ne peut pas être l'approbateur (six-yeux avec le calcul).
        if (decaisseur.equals(releve.getApprouvePar())) {
            throw new IllegalStateException("Séparation des tâches : le décaisseur ne peut pas être l'approbateur.");
        }

        // Contrôle « destination révoquée/changée depuis le figeage » : la destination active doit être
        // EXACTEMENT celle figée à l'approbation (id + empreinte).
        DestinationReversement dest = destinations.active(releve.getEtablissementRef())
                .orElseThrow(() -> new IllegalStateException(
                        "Aucune destination active : la destination figée a été révoquée. Re-approuver le relevé."));
        if (!dest.getId().equals(releve.getDestinationId())
                || !dest.getEmpreinte().equals(releve.getDestinationEmpreinte())) {
            throw new IllegalStateException(
                    "La destination a changé depuis l'approbation (révocation/substitution) : re-approuver le relevé.");
        }

        // Déchiffrement de la destination : SEUL chemin de déchiffrement du service (promesse b-1).
        // L'échec du tag GCM/AAD (blob altéré/transplanté) fait échouer ici = refus (intégrité).
        String destinationClair = chiffrement.dechiffrer(dest.getRefChiffree(), dest.getNonce(),
                dest.getCleVersion(), releve.getEtablissementRef(), dest.getId());

        // Engagement : EN_COURS + tentative EN_COURS au registre (bras local du rapprochement S11.x).
        releve.demarrerVersement();
        releves.save(releve);
        DecaissementReversement tentative = decaissements.save(new DecaissementReversement(
                releve.getId(), dest.getId(), net, releve.getDevise(), cleIdempotence, decaisseur));

        // Passerelle (OCP par type ; la vérité vient d'elle, jamais de l'appelant). referenceInterne =
        // clé d'idempotence → un vrai PSP dédupliquerait aussi cette tentative précise.
        PasserelleReversement passerelle = passerelles.pour(dest.getType());
        ResultatDecaissement res = passerelle.verser(new DemandeDecaissement(cleIdempotence, dest.getType(),
                destinationClair, net, releve.getDevise(), "Reversement " + releve.getNumero()));

        if (res.estExecute()) {
            tentative.marquerExecute(res.referencePasserelle(), res.frais());
            decaissements.save(tentative);
            releve.marquerVerse();
            releves.save(releve);
            // Écriture de DÉCAISSEMENT (partie double ; plateforme porte les frais). Une seule par relevé
            // (uq_ecr_decaissement_par_releve = dernier rempart anti-double).
            List<JambeCalculee> jambes = ReglesEcritureReversement.decaissement(net, res.frais());
            posterEcriture(releve.getId(), TypeEcriture.DECAISSEMENT, jambes, null, decaisseur);
            audit.enregistrer("SettlementDisbursed", "settlement", releve.getId().toString(),
                    Map.of("numero", releve.getNumero(), "decaisseur", decaisseur,
                            "net", net, "frais", res.frais(), "refPasserelle", res.referencePasserelle(),
                            "destination", dest.getId().toString()));
        } else {
            // Échec : rien n'est parti → aucune écriture. ECHOUE rejouable (nouvelle clé).
            tentative.marquerEchoue(res.referencePasserelle(), res.motif());
            decaissements.save(tentative);
            releve.marquerVersementEchoue();
            releves.save(releve);
            audit.enregistrer("SettlementDisbursementFailed", "settlement", releve.getId().toString(),
                    Map.of("numero", releve.getNumero(), "decaisseur", decaisseur,
                            "motif", res.motif() == null ? "" : res.motif(),
                            "refPasserelle", res.referencePasserelle() == null ? "" : res.referencePasserelle()));
        }
        return releve;
    }

    /**
     * Annulation (depuis CALCULE, APPROUVE ou ECHOUE, rien d'exécuté). Interdite si le relevé a un
     * successeur vivant : seul le DERNIER maillon de la chaîne est annulable (le report en dépend —
     * ADR-016 §2). Désactive les lignes → libère les pièces pour un recalcul (tentative+1). Si la dette
     * avait été constatée (APPROUVE/ECHOUE), la contre-passe (append-only).
     */
    @Transactional
    public ReversementReleve annuler(UUID id, String motif, String acteur) {
        ReversementReleve releve = trouver(id);
        ReversementStatut statut = releve.getStatut();
        if (statut != ReversementStatut.CALCULE && statut != ReversementStatut.APPROUVE
                && statut != ReversementStatut.ECHOUE) {
            throw new IllegalStateException("Annulation impossible dans l'état " + statut + ".");
        }
        if (motif == null || motif.isBlank()) {
            throw new IllegalArgumentException("Motif d'annulation obligatoire.");
        }
        if (releves.compterSuccesseursVivants(releve.getId()) > 0) {
            throw new IllegalStateException(
                    "Relevé avec successeur actif : annuler d'abord le dernier relevé de la chaîne.");
        }
        // La constatation a été postée à l'approbation → présente aussi si le versement a ÉCHOUÉ.
        boolean constatationPostee = statut == ReversementStatut.APPROUVE || statut == ReversementStatut.ECHOUE;
        releve.annuler(acteur, Instant.now(), motif);
        releves.save(releve);
        libererPieces(releve.getId());
        if (constatationPostee) {
            extournerConstatation(releve.getId(), acteur);
        }
        audit.enregistrer("SettlementCancelled", "settlement", releve.getId().toString(),
                Map.of("numero", releve.getNumero(), "motif", motif, "acteur", acteur, "extourne", constatationPostee));
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
    public List<DecaissementReversement> decaissementsDe(UUID releveId) {
        return decaissements.findByReleveIdOrderByCreeLeDesc(releveId);
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
