package ci.masante.payment.web;

import ci.masante.payment.domain.model.ReversementReleve;
import ci.masante.payment.service.RoleInsuffisantException;
import ci.masante.payment.service.ServiceCommissionConfig;
import ci.masante.payment.service.ServiceReversement;
import ci.masante.payment.web.dto.AnnulerReversementRequete;
import ci.masante.payment.web.dto.CalculerReversementRequete;
import ci.masante.payment.web.dto.CommissionConfigReponse;
import ci.masante.payment.web.dto.LigneReversementReponse;
import ci.masante.payment.web.dto.OuvrirCommissionRequete;
import ci.masante.payment.web.dto.ReversementReleveDetailReponse;
import ci.masante.payment.web.dto.ReversementReleveReponse;
import io.swagger.v3.oas.annotations.Operation;
import io.swagger.v3.oas.annotations.tags.Tag;
import jakarta.validation.Valid;
import jakarta.validation.constraints.NotBlank;
import org.springframework.http.HttpStatus;
import org.springframework.http.ResponseEntity;
import org.springframework.validation.annotation.Validated;
import org.springframework.web.bind.annotation.GetMapping;
import org.springframework.web.bind.annotation.PathVariable;
import org.springframework.web.bind.annotation.PostMapping;
import org.springframework.web.bind.annotation.RequestBody;
import org.springframework.web.bind.annotation.RequestHeader;
import org.springframework.web.bind.annotation.RequestMapping;
import org.springframework.web.bind.annotation.RequestParam;
import org.springframework.web.bind.annotation.RestController;

import java.util.List;
import java.util.UUID;

/**
 * Reversements aux établissements (CDC_06 §11) — P5.5a : calcul des sommes dues, relevé immuable,
 * approbation, annulation. Le décaissement, le grand livre et la destination sont hors périmètre
 * (→ P5.5b). L'<b>acteur</b> vient de l'en-tête {@code X-Acteur-Id} posé par la passerelle authentifiée
 * (non usurpable). Le changement de taux de commission (action la plus sensible) exige en outre le rôle
 * {@code ADMIN_FINANCE} via {@code X-Acteur-Role}.
 */
@RestController
@RequestMapping("/api/v1")
@Validated
@Tag(name = "Reversements", description = "Sommes dues aux établissements + relevés (CDC_06 §11)")
public class ReversementController {

    private static final String ROLE_ADMIN_FINANCE = "ADMIN_FINANCE";

    private final ServiceReversement reversement;
    private final ServiceCommissionConfig commissionConfig;

    public ReversementController(ServiceReversement reversement, ServiceCommissionConfig commissionConfig) {
        this.reversement = reversement;
        this.commissionConfig = commissionConfig;
    }

    // --- relevés ------------------------------------------------------------------------------

    @PostMapping("/settlements/run")
    @Operation(summary = "Calculer le relevé de reversement d'un établissement sur une période (acteur via X-Acteur-Id)")
    public ResponseEntity<ReversementReleveReponse> calculer(
            @RequestHeader("X-Acteur-Id") @NotBlank String acteur,
            @Valid @RequestBody CalculerReversementRequete r) {
        ReversementReleve releve = reversement.calculerReleve(
                r.etablissementRef(), r.periodeDebut(), r.periodeFin(), r.cutOff(), acteur);
        return ResponseEntity.status(HttpStatus.CREATED).body(ReversementReleveReponse.de(releve));
    }

    @PostMapping("/settlements/{id}/approve")
    @Operation(summary = "Approuver un relevé (CALCULE → APPROUVE ; acteur via X-Acteur-Id)")
    public ReversementReleveReponse approuver(
            @PathVariable UUID id, @RequestHeader("X-Acteur-Id") @NotBlank String acteur) {
        return ReversementReleveReponse.de(reversement.approuver(id, acteur));
    }

    @PostMapping("/settlements/{id}/cancel")
    @Operation(summary = "Annuler un relevé (CALCULE/APPROUVE ; libère la période ; acteur via X-Acteur-Id)")
    public ReversementReleveReponse annuler(
            @PathVariable UUID id, @RequestHeader("X-Acteur-Id") @NotBlank String acteur,
            @Valid @RequestBody AnnulerReversementRequete r) {
        return ReversementReleveReponse.de(reversement.annuler(id, r.motif(), acteur));
    }

    @GetMapping("/settlements/{id}")
    @Operation(summary = "Détail d'un relevé + ses lignes")
    public ReversementReleveDetailReponse detail(@PathVariable UUID id) {
        ReversementReleve releve = reversement.trouver(id);
        List<LigneReversementReponse> lignes = reversement.lignesDe(id).stream()
                .map(LigneReversementReponse::de).toList();
        return new ReversementReleveDetailReponse(ReversementReleveReponse.de(releve), lignes);
    }

    @GetMapping("/settlements")
    @Operation(summary = "Lister les relevés d'un établissement (exercice optionnel)")
    public List<ReversementReleveReponse> lister(
            @RequestParam("etablissement") @NotBlank String etablissement,
            @RequestParam(value = "exercice", required = false) Integer exercice) {
        return reversement.lister(etablissement, exercice).stream().map(ReversementReleveReponse::de).toList();
    }

    // --- taux de commission (ADMIN_FINANCE) ---------------------------------------------------

    @PostMapping("/settlements/commission-config")
    @Operation(summary = "Ouvrir un taux de commission (ADMIN_FINANCE ; clôture le taux précédent ; audité nominatif)")
    public ResponseEntity<CommissionConfigReponse> ouvrirTaux(
            @RequestHeader("X-Acteur-Id") @NotBlank String acteur,
            @RequestHeader("X-Acteur-Role") @NotBlank String role,
            @Valid @RequestBody OuvrirCommissionRequete r) {
        exigerRole(role, ROLE_ADMIN_FINANCE);
        return ResponseEntity.status(HttpStatus.CREATED).body(CommissionConfigReponse.de(
                commissionConfig.ouvrir(r.etablissementRef(), r.tauxBps(), r.motif(), acteur)));
    }

    @GetMapping("/settlements/commission-config")
    @Operation(summary = "Taux de commission ouvert d'un périmètre (etablissement optionnel = défaut plateforme)")
    public ResponseEntity<CommissionConfigReponse> courantTaux(
            @RequestParam(value = "etablissement", required = false) String etablissement) {
        return commissionConfig.courantPour(etablissement)
                .map(c -> ResponseEntity.ok(CommissionConfigReponse.de(c)))
                .orElseGet(() -> ResponseEntity.notFound().build());
    }

    private static void exigerRole(String role, String attendu) {
        if (role == null || !attendu.equals(role.trim())) {
            throw new RoleInsuffisantException();
        }
    }
}
