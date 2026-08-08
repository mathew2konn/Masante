package ci.masante.payment.web;

import ci.masante.payment.domain.model.ReversementReconciliation;
import ci.masante.payment.service.SeedeurAnomaliesRapprochement;
import ci.masante.payment.service.ServiceRapprochementReversement;
import ci.masante.payment.web.dto.ReversementReconciliationReponse;
import io.swagger.v3.oas.annotations.Operation;
import io.swagger.v3.oas.annotations.tags.Tag;
import org.springframework.format.annotation.DateTimeFormat;
import org.springframework.http.HttpStatus;
import org.springframework.web.bind.annotation.GetMapping;
import org.springframework.web.bind.annotation.PostMapping;
import org.springframework.web.bind.annotation.RequestMapping;
import org.springframework.web.bind.annotation.RequestParam;
import org.springframework.web.bind.annotation.RestController;
import org.springframework.web.server.ResponseStatusException;

import java.time.LocalDate;
import java.time.ZoneOffset;
import java.util.List;
import java.util.Map;

/**
 * Rapprochement à deux sources « factures ↔ reversements » (P5.5c, CDC_06 §11, ADR-016 §7). Le
 * déclenchement manuel sur une journée choisie sert la preuve G2/G4 (le job planifié traite la veille).
 * DÉTECTION SEULE — aucun endpoint ne corrige un écart. Lecture seule sur les données financières :
 * aucun mouvement d'argent, donc pas de principal signé requis (comme les autres rapprochements).
 */
@RestController
@RequestMapping("/api/v1/settlement-reconciliations")
@Tag(name = "Reversements — Rapprochement 2 sources",
        description = "Confrontation facturation ↔ reversements (CDC_06 §11). Détection seule, jamais de correction.")
public class ReversementReconciliationController {

    private final ServiceRapprochementReversement service;
    private final SeedeurAnomaliesRapprochement seedeur;

    public ReversementReconciliationController(ServiceRapprochementReversement service,
                                               SeedeurAnomaliesRapprochement seedeur) {
        this.service = service;
        this.seedeur = seedeur;
    }

    @PostMapping("/run")
    @Operation(summary = "Lancer le rapprochement d'une journée comptable (UTC ; défaut = aujourd'hui) — idempotent")
    public ReversementReconciliationReponse lancer(
            @RequestParam(required = false) @DateTimeFormat(iso = DateTimeFormat.ISO.DATE) LocalDate date) {
        LocalDate journee = date != null ? date : LocalDate.now(ZoneOffset.UTC);
        ReversementReconciliation r = service.executer(journee);
        return ReversementReconciliationReponse.de(r);
    }

    @GetMapping
    @Operation(summary = "Consulter les rapports récents (ou une journée si date fournie)")
    public List<ReversementReconciliationReponse> consulter(
            @RequestParam(required = false) @DateTimeFormat(iso = DateTimeFormat.ISO.DATE) LocalDate date) {
        List<ReversementReconciliation> rapports =
                date != null ? service.consulter(date) : service.listerRecents();
        return rapports.stream().map(ReversementReconciliationReponse::de).toList();
    }

    @PostMapping("/dev/seed-anomalies")
    @Operation(summary = "[DÉV] Injecter des anomalies factures↔reversements pour prouver la détection (gaté OFF)")
    public Map<String, Object> injecterAnomalies() {
        if (!seedeur.estActif()) {
            throw new ResponseStatusException(HttpStatus.NOT_FOUND, "Endpoint de dév désactivé.");
        }
        List<String> refs = seedeur.injecter();
        return Map.of("injecte", refs.size(), "references", refs,
                "note", "Lancez POST /run (date = aujourd'hui) pour détecter ces anomalies.");
    }
}
