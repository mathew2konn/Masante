package ci.masante.payment.web;

import ci.masante.payment.service.ServiceAudit;
import io.swagger.v3.oas.annotations.Operation;
import io.swagger.v3.oas.annotations.tags.Tag;
import org.springframework.web.bind.annotation.GetMapping;
import org.springframework.web.bind.annotation.RequestMapping;
import org.springframework.web.bind.annotation.RestController;

import java.util.Map;

/** Contrôle d'intégrité du journal d'audit (CDC_06 §9.7). */
@RestController
@RequestMapping("/api/v1/audit")
@Tag(name = "Audit", description = "Vérification d'intégrité de la chaîne d'audit")
public class AuditController {

    private final ServiceAudit audit;

    public AuditController(ServiceAudit audit) {
        this.audit = audit;
    }

    @GetMapping("/verify")
    @Operation(summary = "Recalculer et vérifier toute la chaîne de hachage d'audit")
    public Map<String, Boolean> verifier() {
        return Map.of("integre", audit.verifierIntegrite());
    }
}
