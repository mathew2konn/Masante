package ci.masante.payment.web;

import ci.masante.payment.domain.model.ControleRun;
import ci.masante.payment.service.SeedeurAnomalies;
import ci.masante.payment.service.ServiceControleIntegrite;
import ci.masante.payment.web.dto.ControleRunDetailReponse;
import ci.masante.payment.web.dto.ControleRunReponse;
import io.swagger.v3.oas.annotations.Operation;
import io.swagger.v3.oas.annotations.tags.Tag;
import org.springframework.format.annotation.DateTimeFormat;
import org.springframework.http.HttpStatus;
import org.springframework.web.bind.annotation.GetMapping;
import org.springframework.web.bind.annotation.PathVariable;
import org.springframework.web.bind.annotation.PostMapping;
import org.springframework.web.bind.annotation.RequestMapping;
import org.springframework.web.bind.annotation.RequestParam;
import org.springframework.web.bind.annotation.RestController;
import org.springframework.web.server.ResponseStatusException;

import java.time.LocalDate;
import java.time.ZoneOffset;
import java.util.List;
import java.util.Map;
import java.util.UUID;

/**
 * Contrôle d'intégrité financière INTERNE (P5.3b-4, CDC_06 §6.3). Ce n'est PAS un rapprochement à deux
 * sources (voir ADR-014) : il vérifie la cohérence de la base avec elle-même. Détection seule — aucun
 * endpoint ne corrige un écart.
 */
@RestController
@RequestMapping("/api/v1/integrity-checks")
@Tag(name = "Contrôle d'intégrité",
        description = "Auditeur d'intégrité financière interne — double écriture, facture↔règlement, "
                + "cashback (CDC_06 §6.3). Détection seule, jamais de correction.")
public class ControleController {

    private final ServiceControleIntegrite service;
    private final SeedeurAnomalies seedeur;

    public ControleController(ServiceControleIntegrite service, SeedeurAnomalies seedeur) {
        this.service = service;
        this.seedeur = seedeur;
    }

    @PostMapping("/run")
    @Operation(summary = "Exécuter le contrôle d'une journée comptable (UTC ; défaut = aujourd'hui)")
    public ControleRunDetailReponse lancer(
            @RequestParam(required = false) @DateTimeFormat(iso = DateTimeFormat.ISO.DATE) LocalDate date) {
        LocalDate journee = date != null ? date : LocalDate.now(ZoneOffset.UTC);
        ControleRun run = service.executer(journee);
        return service.detail(run.getId());
    }

    @GetMapping
    @Operation(summary = "Lister les runs récents (verdicts par journée)")
    public List<ControleRunReponse> lister() {
        return service.listerRuns();
    }

    @GetMapping("/{runId}")
    @Operation(summary = "Détail d'un run : le verdict et tous ses écarts")
    public ControleRunDetailReponse detail(@PathVariable UUID runId) {
        return service.detail(runId);
    }

    @PostMapping("/dev/seed-anomalies")
    @Operation(summary = "[DÉV] Injecter des anomalies pour prouver la détection (gaté OFF par défaut)")
    public Map<String, Object> injecterAnomalies() {
        if (!seedeur.estActif()) {
            // Masqué quand désactivé : en production l'endpoint n'existe pas fonctionnellement.
            throw new ResponseStatusException(HttpStatus.NOT_FOUND, "Endpoint de dév désactivé.");
        }
        List<String> refs = seedeur.injecter();
        return Map.of("injecte", refs.size(), "references", refs,
                "note", "Lancez POST /run pour détecter ces anomalies (date = aujourd'hui).");
    }
}
