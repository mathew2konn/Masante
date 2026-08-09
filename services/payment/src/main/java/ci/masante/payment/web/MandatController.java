package ci.masante.payment.web;

import ci.masante.payment.domain.mandat.ActionMandat;
import ci.masante.payment.service.ServiceMandat;
import ci.masante.payment.web.dto.CreerMandatRequete;
import ci.masante.payment.web.dto.MandatReponse;
import io.swagger.v3.oas.annotations.Operation;
import io.swagger.v3.oas.annotations.Parameter;
import io.swagger.v3.oas.annotations.tags.Tag;
import jakarta.validation.Valid;
import jakarta.validation.constraints.NotBlank;
import org.springframework.format.annotation.DateTimeFormat;
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

import java.time.LocalDate;
import java.time.ZoneOffset;
import java.util.List;
import java.util.UUID;

/**
 * API des mandats de paiement récurrents (CDC_06 §5.4). PAIEMENT SIMULÉ (FT5).
 *
 * <p><b>Identité</b> : {@code X-Utilisateur-Id} (propriétaire du mandat, posé par la passerelle) ;
 * {@code X-Acteur-Id} pour les actions de cycle de vie (audité). <b>Idempotence</b> : {@code Idempotency-Key}
 * obligatoire à la création. Le montant, la périodicité et les échéances sont calculés backend (§0.1).</p>
 */
@RestController
@RequestMapping("/api/v1/mandats")
@Validated
@Tag(name = "Mandats", description = "Paiements récurrents par carte (§5.4) — mandats, échéancier, préavis, annulation")
public class MandatController {

    private final ServiceMandat mandats;

    public MandatController(ServiceMandat mandats) {
        this.mandats = mandats;
    }

    @PostMapping
    @Operation(summary = "Créer un mandat récurrent (idempotent) et planifier la 1re échéance")
    public ResponseEntity<MandatReponse> creer(
            @RequestHeader("X-Utilisateur-Id") @NotBlank String utilisateurRef,
            @RequestHeader("Idempotency-Key") @NotBlank String idempotencyKey,
            @RequestHeader(value = "X-Acteur-Id", required = false) String acteur,
            @Valid @RequestBody CreerMandatRequete requete) {
        var mandat = mandats.creer(requete.versCommande(utilisateurRef, acteur), idempotencyKey);
        return ResponseEntity.status(HttpStatus.CREATED)
                .body(MandatReponse.avecEcheances(mandat, mandats.echeancesDe(mandat.getId())));
    }

    @GetMapping("/{mandatId}")
    @Operation(summary = "Consulter un mandat et son échéancier")
    public MandatReponse consulter(@PathVariable UUID mandatId) {
        var mandat = mandats.consulter(mandatId);
        return MandatReponse.avecEcheances(mandat, mandats.echeancesDe(mandatId));
    }

    @GetMapping
    @Operation(summary = "Lister les mandats de l'utilisateur")
    public List<MandatReponse> lister(@RequestHeader("X-Utilisateur-Id") @NotBlank String utilisateurRef) {
        return mandats.listerParUtilisateur(utilisateurRef).stream().map(MandatReponse::de).toList();
    }

    @PostMapping("/{mandatId}/suspend")
    @Operation(summary = "Suspendre un mandat (les prélèvements s'arrêtent)")
    public MandatReponse suspendre(@PathVariable UUID mandatId,
                                   @RequestHeader(value = "X-Acteur-Id", required = false) String acteur) {
        return MandatReponse.de(mandats.appliquerAction(mandatId, ActionMandat.SUSPENDRE, acteur));
    }

    @PostMapping("/{mandatId}/resume")
    @Operation(summary = "Reprendre un mandat suspendu")
    public MandatReponse reprendre(@PathVariable UUID mandatId,
                                   @RequestHeader(value = "X-Acteur-Id", required = false) String acteur) {
        return MandatReponse.de(mandats.appliquerAction(mandatId, ActionMandat.REPRENDRE, acteur));
    }

    @PostMapping("/{mandatId}/cancel")
    @Operation(summary = "Annuler un mandat (possible à tout moment, §5.4)")
    public MandatReponse annuler(@PathVariable UUID mandatId,
                                 @RequestHeader(value = "X-Acteur-Id", required = false) String acteur) {
        return MandatReponse.de(mandats.appliquerAction(mandatId, ActionMandat.ANNULER, acteur));
    }

    @PostMapping("/executer-echeances")
    @Operation(summary = "Déclencher l'exécution des échéances dues (débit MIT simulé) — endpoint d'exploitation")
    public ServiceMandat.ResumeExecution executerEcheances(
            @Parameter(description = "Date de traitement (défaut : aujourd'hui, UTC)")
            @RequestParam(required = false) @DateTimeFormat(iso = DateTimeFormat.ISO.DATE) LocalDate date) {
        return mandats.executerEcheancesDues(date == null ? LocalDate.now(ZoneOffset.UTC) : date);
    }

    @PostMapping("/poser-preavis")
    @Operation(summary = "Poser les préavis dus (notifications avant prélèvement — livraison différée)")
    public int poserPreavis(
            @RequestParam(required = false) @DateTimeFormat(iso = DateTimeFormat.ISO.DATE) LocalDate date) {
        return mandats.poserPreavisDus(date == null ? LocalDate.now(ZoneOffset.UTC) : date);
    }
}
