package ci.masante.payment.web;

import ci.masante.payment.service.ServicePrincipal;
import ci.masante.payment.service.ServicePrincipal.PrincipalAuthentifie;
import ci.masante.payment.service.ServiceSignauxFraude;
import ci.masante.payment.web.dto.SignauxFactureReponse;
import ci.masante.payment.web.dto.SignauxLotRequete;
import io.swagger.v3.oas.annotations.Operation;
import io.swagger.v3.oas.annotations.tags.Tag;
import jakarta.servlet.http.HttpServletRequest;
import jakarta.validation.Valid;
import jakarta.validation.constraints.NotBlank;
import org.springframework.format.annotation.DateTimeFormat;
import org.springframework.validation.annotation.Validated;
import org.springframework.web.bind.annotation.GetMapping;
import org.springframework.web.bind.annotation.PathVariable;
import org.springframework.web.bind.annotation.PostMapping;
import org.springframework.web.bind.annotation.RequestBody;
import org.springframework.web.bind.annotation.RequestHeader;
import org.springframework.web.bind.annotation.RequestMapping;
import org.springframework.web.bind.annotation.RequestParam;
import org.springframework.web.bind.annotation.RestController;

import java.time.Instant;
import java.util.List;

/**
 * EXTRACTION des signaux de facturation pour la détection de fraude (CDC_05, incrément A). Projection
 * <b>lecture seule</b> du domaine paiement vers le contrat {@code SignalFacturation} consommé par le
 * microservice fraude (Python). <b>Aucune décision de fraude</b> : ce sont des données agrégées.
 *
 * <p>Ces agrégats exposent des informations financières patient/établissement → endpoint <b>sensible</b> :
 * garde par <b>principal signé</b> vérifié en service ({@code X-Principal} + {@code X-Principal-Sig},
 * lié méthode+chemin, anti-rejeu) et rôle {@code ADMIN_FINANCE} — jamais un rôle déclaré en clair. Même
 * mécanisme que les actes sensibles de {@link ReversementController} (P5.5b-1, CDC_10).</p>
 */
@RestController
@RequestMapping("/api/v1/fraud-signals")
@Validated
@Tag(name = "Signaux fraude", description = "Extraction lecture seule des signaux de facturation (CDC_05)")
public class FraudSignalsController {

    private static final String ROLE_ADMIN_FINANCE = "ADMIN_FINANCE";

    private final ServiceSignauxFraude signaux;
    private final ServicePrincipal principal;

    public FraudSignalsController(ServiceSignauxFraude signaux, ServicePrincipal principal) {
        this.signaux = signaux;
        this.principal = principal;
    }

    @GetMapping("/{reference}")
    @Operation(summary = "Signaux d'une facture (principal signé ADMIN_FINANCE ; lecture seule, cut-off asOf optionnel)")
    public SignauxFactureReponse parReference(
            @PathVariable @NotBlank String reference,
            @RequestParam(value = "asOf", required = false)
            @DateTimeFormat(iso = DateTimeFormat.ISO.DATE_TIME) Instant asOf,
            @RequestHeader("X-Principal") String xPrincipal,
            @RequestHeader("X-Principal-Sig") String xSig,
            HttpServletRequest requete) {
        adminFinance(xPrincipal, xSig, requete);
        return signaux.extraire(reference, asOf);
    }

    @PostMapping("/lot")
    @Operation(summary = "Signaux d'un LOT de factures au même cut-off (principal signé ADMIN_FINANCE ; §6.9)")
    public List<SignauxFactureReponse> lot(
            @Valid @RequestBody SignauxLotRequete r,
            @RequestHeader("X-Principal") String xPrincipal,
            @RequestHeader("X-Principal-Sig") String xSig,
            HttpServletRequest requete) {
        adminFinance(xPrincipal, xSig, requete);
        return signaux.extraireLot(r.references(), r.asOf());
    }

    /** Vérifie le principal signé (lié à méthode+chemin, anti-rejeu) et exige le rôle ADMIN_FINANCE. */
    private PrincipalAuthentifie adminFinance(String xPrincipal, String xSig, HttpServletRequest requete) {
        PrincipalAuthentifie p = principal.verifier(xPrincipal, xSig, requete.getMethod(), requete.getRequestURI());
        principal.exigerRole(p, ROLE_ADMIN_FINANCE);
        return p;
    }
}
