package ci.masante.payment.service;

import ci.masante.payment.domain.reversement.rapprochement.EcartRapprochement;
import ci.masante.payment.domain.reversement.rapprochement.ReglesRapprochement;
import ci.masante.payment.domain.model.ReversementReconciliation;
import ci.masante.payment.repository.RequetesRapprochement;
import ci.masante.payment.repository.ReversementReconciliationRepository;
import ci.masante.payment.repository.projection.LigneRapprochementProj;
import ci.masante.payment.repository.projection.PieceNonReverseeProj;
import com.fasterxml.jackson.databind.ObjectMapper;
import org.springframework.beans.factory.annotation.Value;
import org.springframework.stereotype.Service;
import org.springframework.transaction.annotation.Transactional;

import java.time.Instant;
import java.time.LocalDate;
import java.time.ZoneOffset;
import java.time.temporal.ChronoUnit;
import java.util.ArrayList;
import java.util.LinkedHashMap;
import java.util.List;
import java.util.Map;

/**
 * Rapprochement à DEUX sources « factures ↔ reversements » (P5.5c, CDC_06 §11, ADR-016 §7).
 *
 * <p><b>Vraie confrontation</b> : la source A (facturation — factures {@code PAYEE} imputables,
 * remboursements carte {@code REUSSI}) et la source B (reversements — lignes de relevé actives), deux
 * sous-systèmes maintenus INDÉPENDAMMENT. C'est le bras « factures ↔ reversements » du rapprochement
 * quotidien ; le bras « opérateurs ↔ MASANTÉ » reste différé (aucun relevé opérateur réel, FT5).</p>
 *
 * <p><b>Lecture seule</b> sur les données financières : le service n'écrit QUE son rapport
 * ({@code reversement_reconciliations}) et le journal d'audit. Il ne corrige JAMAIS un écart
 * (CDC_06 §11). Snapshot au cut-off T ({@code < T}) ; la complétude A→B applique un <b>délai de
 * grâce</b> (donnée) pour ne pas signaler une pièce dont le relevé périodique n'a pas encore tourné.
 * Idempotent : rejouer une journée remplace le rapport. Tout le JUGEMENT est dans
 * {@link ReglesRapprochement} (pur, testable).</p>
 */
@Service
public class ServiceRapprochementReversement {

    private final RequetesRapprochement requetes;
    private final ReversementReconciliationRepository rapports;
    private final ServiceAudit audit;
    private final ObjectMapper json;
    private final int graceJours;
    private final ReglesRapprochement regles = new ReglesRapprochement();

    public ServiceRapprochementReversement(
            RequetesRapprochement requetes, ReversementReconciliationRepository rapports,
            ServiceAudit audit, ObjectMapper json,
            @Value("${masante.payment.reversement.rapprochement.delai-grace-jours:2}") int graceJours) {
        this.requetes = requetes;
        this.rapports = rapports;
        this.audit = audit;
        this.json = json;
        this.graceJours = graceJours;
    }

    /**
     * Exécute le rapprochement d'une journée comptable (UTC) et persiste le rapport + les écarts. Le
     * cut-off T = début du jour suivant (on examine {@code < T}). Le seuil de grâce = T − délai (donnée).
     */
    @Transactional
    public ReversementReconciliation executer(LocalDate journee) {
        Instant cutOffT = journee.plusDays(1).atStartOfDay(ZoneOffset.UTC).toInstant();
        Instant seuilGrace = cutOffT.minus(graceJours, ChronoUnit.DAYS);

        List<EcartRapprochement> detectes = new ArrayList<>();

        // Source A → B (complétude) : pièces dues jamais reversées (délai de grâce appliqué).
        List<PieceNonReverseeProj> facturesDues = requetes.facturesNonReversees(seuilGrace);
        List<PieceNonReverseeProj> remboursementsDus = requetes.remboursementsNonReverses(seuilGrace);
        for (PieceNonReverseeProj p : concat(facturesDues, remboursementsDus)) {
            regles.pieceNonReversee(p.getTypePiece(), p.getReference(), p.getDateeA(), seuilGrace, p.getMontant())
                    .ifPresent(detectes::add);
        }

        // Source B → A (intégrité + montant) : lignes actives confrontées à leur pièce courante.
        List<LigneRapprochementProj> lignesFacture = requetes.lignesFactureActives(cutOffT);
        List<LigneRapprochementProj> lignesRemb = requetes.lignesRemboursementActives(cutOffT);
        for (LigneRapprochementProj l : lignesFacture) {
            regles.evaluerLigne(l.getReference(), l.getMontantImpute(), l.getPieceStatut(), "PAYEE",
                    l.getPieceEtab(), l.getReleveEtab(), l.getMontantCourant()).ifPresent(detectes::add);
        }
        for (LigneRapprochementProj l : lignesRemb) {
            regles.evaluerLigne(l.getReference(), l.getMontantImpute(), l.getPieceStatut(), "REUSSI",
                    l.getPieceEtab(), l.getReleveEtab(), l.getMontantCourant()).ifPresent(detectes::add);
        }

        int nbPieces = facturesDues.size() + remboursementsDus.size();
        int nbLignes = lignesFacture.size() + lignesRemb.size();

        ReversementReconciliation rapport = rapports.findByDateRapport(journee)
                .orElseGet(() -> new ReversementReconciliation(journee));
        rapport.renseigner(cutOffT, graceJours, seuilGrace, nbPieces, nbLignes, detectes.size(),
                serialiser(detectes));
        rapports.save(rapport);

        audit.enregistrer("SettlementReconciliationRun", "reversement_reconciliation", journee.toString(),
                Map.of("statut", rapport.getStatut().name(), "nbEcarts", detectes.size(),
                        "nbPiecesExaminees", nbPieces, "nbLignesExaminees", nbLignes,
                        "cutOffT", cutOffT.toString(), "graceJours", graceJours));
        return rapport;
    }

    @Transactional(readOnly = true)
    public List<ReversementReconciliation> listerRecents() {
        return rapports.findTop60ByOrderByDateRapportDesc();
    }

    @Transactional(readOnly = true)
    public List<ReversementReconciliation> consulter(LocalDate journee) {
        return rapports.findByDateRapport(journee).map(List::of).orElseGet(List::of);
    }

    /** Sérialise les écarts en tableau JSON (rejouable) ; les {@code null} de montant sont conservés. */
    private String serialiser(List<EcartRapprochement> ecarts) {
        List<Map<String, Object>> lignes = new ArrayList<>();
        for (EcartRapprochement e : ecarts) {
            Map<String, Object> m = new LinkedHashMap<>();
            m.put("type", e.type().name());
            m.put("severite", e.severite().name());
            m.put("reference", e.reference());
            m.put("montantAttendu", e.montantAttendu());
            m.put("montantConstate", e.montantConstate());
            m.put("details", e.details());
            lignes.add(m);
        }
        try {
            return json.writeValueAsString(lignes);
        } catch (Exception ex) {
            throw new IllegalStateException("Sérialisation des écarts de rapprochement impossible", ex);
        }
    }

    private static List<PieceNonReverseeProj> concat(List<PieceNonReverseeProj> a, List<PieceNonReverseeProj> b) {
        List<PieceNonReverseeProj> t = new ArrayList<>(a);
        t.addAll(b);
        return t;
    }
}
