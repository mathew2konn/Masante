package ci.masante.payment.web;

import ci.masante.payment.domain.model.StatutAlerteFraudeIa;
import ci.masante.payment.service.ServiceAlertesFraude;
import ci.masante.payment.service.ServicePrincipal;
import ci.masante.payment.service.ServicePrincipal.PrincipalAuthentifie;
import ci.masante.payment.service.ServiceRoutageFraude;
import ci.masante.payment.service.ServiceRoutageFraude.RapportRoutage;
import ci.masante.payment.web.dto.AlerteFraudeReponse;
import com.fasterxml.jackson.databind.ObjectMapper;
import io.swagger.v3.oas.annotations.Operation;
import io.swagger.v3.oas.annotations.tags.Tag;
import jakarta.servlet.http.HttpServletRequest;
import org.springframework.format.annotation.DateTimeFormat;
import org.springframework.validation.annotation.Validated;
import org.springframework.web.bind.annotation.GetMapping;
import org.springframework.web.bind.annotation.PathVariable;
import org.springframework.web.bind.annotation.PostMapping;
import org.springframework.web.bind.annotation.RequestHeader;
import org.springframework.web.bind.annotation.RequestMapping;
import org.springframework.web.bind.annotation.RequestParam;
import org.springframework.web.bind.annotation.RestController;

import java.time.LocalDate;
import java.time.ZoneOffset;
import java.util.List;
import java.util.UUID;

/**
 * Alertes de fraude IA (CDC_05, B1) — routage vers le contrôleur plateforme et revue. Endpoints
 * <b>sensibles</b> (données financières + décision de conformité) : garde par <b>principal signé</b>
 * (P5.5b-1) + rôle {@code ADMIN_FINANCE}, jamais un rôle déclaré en clair (CDC_10). <b>Détection seule</b> :
 * ces endpoints déclenchent un scan / consultent / marquent « revue » — aucun gel, aucune action
 * automatique (ADR-017). Le destinataire des notifications est le contrôleur plateforme indépendant,
 * jamais la structure signalée.
 */
@RestController
@RequestMapping("/api/v1/fraud-alertes")
@Validated
@Tag(name = "Alertes fraude", description = "Routage et revue des alertes de fraude IA (CDC_05)")
public class FraudAlerteController {

    private static final String ROLE_ADMIN_FINANCE = "ADMIN_FINANCE";

    private final ServiceRoutageFraude routage;
    private final ServiceAlertesFraude alertes;
    private final ServicePrincipal principal;
    private final ObjectMapper json;

    public FraudAlerteController(ServiceRoutageFraude routage, ServiceAlertesFraude alertes,
                                 ServicePrincipal principal, ObjectMapper json) {
        this.routage = routage;
        this.alertes = alertes;
        this.principal = principal;
        this.json = json;
    }

    @PostMapping("/scan")
    @Operation(summary = "Lancer un scan de routage (principal signé ADMIN_FINANCE ; extrait→score→alerte→notif)")
    public RapportRoutage scanner(
            @RequestParam(value = "journee", required = false)
            @DateTimeFormat(iso = DateTimeFormat.ISO.DATE) LocalDate journee,
            @RequestHeader("X-Principal") String xPrincipal,
            @RequestHeader("X-Principal-Sig") String xSig,
            HttpServletRequest requete) {
        adminFinance(xPrincipal, xSig, requete);
        LocalDate cible = journee != null ? journee : LocalDate.now(ZoneOffset.UTC);
        return routage.executer(cible);
    }

    @GetMapping
    @Operation(summary = "Lister les alertes de fraude IA (principal signé ADMIN_FINANCE ; statut optionnel)")
    public List<AlerteFraudeReponse> lister(
            @RequestParam(value = "statut", required = false) StatutAlerteFraudeIa statut,
            @RequestHeader("X-Principal") String xPrincipal,
            @RequestHeader("X-Principal-Sig") String xSig,
            HttpServletRequest requete) {
        adminFinance(xPrincipal, xSig, requete);
        return alertes.lister(statut).stream().map(a -> AlerteFraudeReponse.de(a, json)).toList();
    }

    @GetMapping("/{id}")
    @Operation(summary = "Détail d'une alerte (principal signé ADMIN_FINANCE)")
    public AlerteFraudeReponse detail(
            @PathVariable UUID id,
            @RequestHeader("X-Principal") String xPrincipal,
            @RequestHeader("X-Principal-Sig") String xSig,
            HttpServletRequest requete) {
        adminFinance(xPrincipal, xSig, requete);
        return AlerteFraudeReponse.de(alertes.trouver(id), json);
    }

    @PostMapping("/{id}/revue")
    @Operation(summary = "Marquer une alerte revue (principal signé ADMIN_FINANCE ; trace, aucune action auto)")
    public AlerteFraudeReponse revue(
            @PathVariable UUID id,
            @RequestHeader("X-Principal") String xPrincipal,
            @RequestHeader("X-Principal-Sig") String xSig,
            HttpServletRequest requete) {
        PrincipalAuthentifie p = adminFinance(xPrincipal, xSig, requete);
        return AlerteFraudeReponse.de(alertes.revue(id, p.sub()), json);
    }

    private PrincipalAuthentifie adminFinance(String xPrincipal, String xSig, HttpServletRequest requete) {
        PrincipalAuthentifie p = principal.verifier(xPrincipal, xSig, requete.getMethod(), requete.getRequestURI());
        principal.exigerRole(p, ROLE_ADMIN_FINANCE);
        return p;
    }
}
