package ci.masante.payment.service;

import ci.masante.payment.domain.integrity.ControleStatut;
import ci.masante.payment.domain.integrity.Ecart;
import ci.masante.payment.domain.integrity.ReglesControle;
import ci.masante.payment.domain.model.ControleEcart;
import ci.masante.payment.domain.model.ControleRun;
import ci.masante.payment.domain.model.FactureStatut;
import ci.masante.payment.domain.model.OwnerTypeWallet;
import ci.masante.payment.repository.ControleEcartRepository;
import ci.masante.payment.repository.ControleRunRepository;
import ci.masante.payment.repository.RequetesControle;
import ci.masante.payment.web.dto.ControleEcartReponse;
import ci.masante.payment.web.dto.ControleRunDetailReponse;
import ci.masante.payment.web.dto.ControleRunReponse;
import com.fasterxml.jackson.databind.ObjectMapper;
import org.springframework.stereotype.Service;
import org.springframework.transaction.annotation.Transactional;

import java.time.Instant;
import java.time.LocalDate;
import java.time.ZoneOffset;
import java.util.ArrayList;
import java.util.List;
import java.util.Map;
import java.util.UUID;

/**
 * Contrôle d'intégrité financière INTERNE (P5.3b-4, CDC_06 §6.3).
 *
 * <p><b>Ce n'est pas un rapprochement</b> : un rapprochement confronte deux sources indépendantes
 * (relevé opérateur ↔ base). Tant que la passerelle réelle et les reversements §11 n'existent pas, il
 * n'y a qu'une source — cet auditeur vérifie la cohérence de la base avec ELLE-MÊME. Le vrai
 * rapprochement à deux sources = S11.x (ADR-014).</p>
 *
 * <p><b>Lecture seule</b> sur les données financières : le service n'écrit QUE son rapport
 * ({@code controle_runs}), ses écarts ({@code controle_ecarts}) et le journal d'audit. Il ne corrige
 * JAMAIS un écart (CDC_06 §11). Il opère sur un <b>snapshot</b> figé au cut-off T (fin de la journée
 * comptable, UTC) : seules les lignes {@code created_at < T} sont examinées. Idempotent : rejouer une
 * journée en remplace le verdict.</p>
 */
@Service
public class ServiceControleIntegrite {

    private static final int NB_CONTROLES = 3;

    private final RequetesControle requetes;
    private final ControleRunRepository runs;
    private final ControleEcartRepository ecarts;
    private final ServiceAudit audit;
    private final ObjectMapper json;
    private final ReglesControle regles = new ReglesControle();

    public ServiceControleIntegrite(RequetesControle requetes, ControleRunRepository runs,
                                    ControleEcartRepository ecarts, ServiceAudit audit, ObjectMapper json) {
        this.requetes = requetes;
        this.runs = runs;
        this.ecarts = ecarts;
        this.audit = audit;
        this.json = json;
    }

    /**
     * Exécute les 3 contrôles pour une journée comptable (UTC) et persiste le verdict + les écarts.
     * Le cut-off T = début du jour suivant : on examine tout ce qui a {@code created_at < T}.
     */
    @Transactional
    public ControleRun executer(LocalDate journee) {
        long t0 = System.nanoTime();
        Instant arreteA = journee.plusDays(1).atStartOfDay(ZoneOffset.UTC).toInstant();

        List<Ecart> detectes = collecter(arreteA);

        // Idempotence : un rejeu de la même journée remplace le verdict (pas de doublon).
        runs.supprimerParJournee(journee);

        ControleStatut statut = detectes.isEmpty() ? ControleStatut.OK : ControleStatut.ECARTS;
        long dureeMs = (System.nanoTime() - t0) / 1_000_000;
        ControleRun run = runs.save(new ControleRun(journee, arreteA, statut, NB_CONTROLES,
                detectes.size(), dureeMs));

        for (Ecart e : detectes) {
            ecarts.save(new ControleEcart(run.getId(), e, serialiser(e.details())));
        }

        audit.enregistrer("IntegrityCheckRun", "controle", run.getId().toString(),
                Map.of("journee", journee.toString(), "statut", statut.name(),
                        "nbEcarts", detectes.size(), "arreteA", arreteA.toString()));
        return run;
    }

    /** Applique les règles pures sur les agrégats bornés au cut-off. Aucune écriture ici. */
    private List<Ecart> collecter(Instant arreteA) {
        List<Ecart> tous = new ArrayList<>();

        // Contrôle 1 — double écriture du grand livre wallet (§6.3).
        regles.grandLivreNonNul(requetes.sommeGlobale(arreteA)).ifPresent(tous::add);
        requetes.operationsDesequilibrees(arreteA).forEach(a ->
                regles.operationDesequilibree(a.getOperationId(), a.getNombre(), a.getSomme())
                        .ifPresent(tous::add));
        requetes.soldesNegatifs(arreteA).forEach(s ->
                regles.soldeNegatif(s.getWalletId(), OwnerTypeWallet.valueOf(s.getOwnerType()), s.getSolde())
                        .ifPresent(tous::add));

        // Contrôle 2 — cohérence statut de facture ↔ règlements (§7.3).
        requetes.etatsFactures(arreteA).forEach(f ->
                regles.factureIncoherente(f.getNumero(), FactureStatut.valueOf(f.getStatut()),
                        f.getMontantRegle(), f.getResteAPayer()).ifPresent(tous::add));
        requetes.encaissementsNonRepercutes(arreteA).forEach(e ->
                regles.encaissementNonRepercute(e.getNumero(), e.getMontantRegle(), e.getSommePasserelle())
                        .ifPresent(tous::add));

        // Contrôle 3 — cohérence des campagnes de cashback (§6.1/§6.2).
        requetes.consommationsCampagnes(arreteA).forEach(c ->
                regles.cashbackBudgetDepasse(c.getCode(), c.getConsomme(), c.getBudget())
                        .ifPresent(tous::add));
        requetes.clawbacksParSource(arreteA).forEach(c ->
                regles.clawbackSuperieurOrigine(c.getSourceId(), c.getCashback(), c.getClawback())
                        .ifPresent(tous::add));

        return tous;
    }

    @Transactional(readOnly = true)
    public List<ControleRunReponse> listerRuns() {
        return runs.findTop60ByOrderByJourneeDesc().stream().map(ControleRunReponse::de).toList();
    }

    @Transactional(readOnly = true)
    public ControleRunDetailReponse detail(UUID runId) {
        ControleRun run = runs.findById(runId)
                .orElseThrow(() -> new ControleIntrouvableException(runId));
        List<ControleEcartReponse> lignes = ecarts.findByRunIdOrderByCreatedAtAsc(runId).stream()
                .map(e -> new ControleEcartReponse(e.getControle().name(), e.getTypeEcart().name(),
                        e.getSeverite().name(), e.getReference(), e.getMontantAttendu(),
                        e.getMontantConstate(), deserialiser(e.getDetails()), e.getCreatedAt()))
                .toList();
        return new ControleRunDetailReponse(ControleRunReponse.de(run), lignes);
    }

    private String serialiser(Map<String, Object> details) {
        try {
            return json.writeValueAsString(details == null ? Map.of() : details);
        } catch (Exception e) {
            throw new IllegalStateException("Sérialisation des détails d'écart impossible", e);
        }
    }

    private Object deserialiser(String detailsJson) {
        try {
            return json.readValue(detailsJson, Object.class);
        } catch (Exception e) {
            return detailsJson; // dernier recours : la chaîne brute
        }
    }
}
