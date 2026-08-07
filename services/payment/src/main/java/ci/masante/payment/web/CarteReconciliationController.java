package ci.masante.payment.web;

import ci.masante.payment.repository.CarteReconciliationRepository;
import ci.masante.payment.service.ServiceReconciliationCarte;
import ci.masante.payment.web.dto.CarteReconciliationReponse;
import io.swagger.v3.oas.annotations.Operation;
import io.swagger.v3.oas.annotations.tags.Tag;
import org.springframework.format.annotation.DateTimeFormat;
import org.springframework.web.bind.annotation.GetMapping;
import org.springframework.web.bind.annotation.PostMapping;
import org.springframework.web.bind.annotation.RequestMapping;
import org.springframework.web.bind.annotation.RequestParam;
import org.springframework.web.bind.annotation.RestController;

import java.time.LocalDate;
import java.util.List;

/**
 * Réconciliation quotidienne carte ↔ PSP (CDC_06 §6.3, ADR-015). Le déclenchement manuel sur une journée
 * choisie sert la preuve G2/G4 (le job planifié traite la veille). DÉTECTION SEULE : jamais de correction.
 */
@RestController
@RequestMapping("/api/v1/card-reconciliations")
@Tag(name = "Cartes — Réconciliation", description = "Confrontation registre local ↔ vérité PSP (simulé)")
public class CarteReconciliationController {

    private final ServiceReconciliationCarte reconciliation;
    private final CarteReconciliationRepository reconciliations;

    public CarteReconciliationController(ServiceReconciliationCarte reconciliation,
                                         CarteReconciliationRepository reconciliations) {
        this.reconciliation = reconciliation;
        this.reconciliations = reconciliations;
    }

    @PostMapping("/run")
    @Operation(summary = "Lancer la réconciliation d'une journée (tous PSP) — idempotent")
    public List<CarteReconciliationReponse> lancer(
            @RequestParam @DateTimeFormat(iso = DateTimeFormat.ISO.DATE) LocalDate date) {
        return reconciliation.executerJournee(date).stream().map(CarteReconciliationReponse::de).toList();
    }

    @GetMapping
    @Operation(summary = "Consulter les rapports de réconciliation d'une journée")
    public List<CarteReconciliationReponse> consulter(
            @RequestParam @DateTimeFormat(iso = DateTimeFormat.ISO.DATE) LocalDate date) {
        return reconciliations.findByDateRapportOrderByPsp(date).stream()
                .map(CarteReconciliationReponse::de).toList();
    }
}
