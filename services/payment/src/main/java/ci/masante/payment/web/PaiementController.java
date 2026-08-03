package ci.masante.payment.web;

import ci.masante.payment.domain.model.Paiement;
import ci.masante.payment.service.ResultatInitiation;
import ci.masante.payment.service.ServiceAudit;
import ci.masante.payment.service.ServicePaiement;
import ci.masante.payment.web.dto.AuditReponse;
import ci.masante.payment.web.dto.InitierPaiementRequete;
import ci.masante.payment.web.dto.PaiementReponse;
import ci.masante.payment.web.dto.RemboursementRequete;
import ci.masante.payment.web.dto.TransitionReponse;
import io.swagger.v3.oas.annotations.Operation;
import io.swagger.v3.oas.annotations.Parameter;
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
import org.springframework.web.bind.annotation.RestController;

import java.util.List;
import java.util.UUID;

/**
 * API paiements (CDC_06 §4). PAIEMENT SIMULÉ. L'en-tête {@code Idempotency-Key} est OBLIGATOIRE sur
 * toute écriture financière (§9.6) : un rejeu de la même clé renvoie le paiement existant (HTTP 200).
 */
@RestController
@RequestMapping("/api/v1/payments")
@Validated
@Tag(name = "Paiements", description = "Initiation (simulée), suivi d'état, transitions, audit, remboursement")
public class PaiementController {

    private final ServicePaiement paiements;
    private final ServiceAudit audit;

    public PaiementController(ServicePaiement paiements, ServiceAudit audit) {
        this.paiements = paiements;
        this.audit = audit;
    }

    @PostMapping
    @Operation(summary = "Initier un paiement (idempotent, simulé)")
    public ResponseEntity<PaiementReponse> initier(
            @Parameter(description = "Clé d'idempotence unique par tentative de paiement", required = true)
            @RequestHeader("Idempotency-Key") @NotBlank String idempotencyKey,
            @Valid @RequestBody InitierPaiementRequete requete) {

        ResultatInitiation resultat = paiements.initier(requete.versCommande(), idempotencyKey);
        HttpStatus statut = resultat.rejoue() ? HttpStatus.OK : HttpStatus.CREATED;
        return ResponseEntity.status(statut).body(PaiementReponse.de(resultat.paiement()));
    }

    @GetMapping("/{id}")
    @Operation(summary = "Consulter un paiement")
    public PaiementReponse consulter(@PathVariable UUID id) {
        return PaiementReponse.de(paiements.trouver(id));
    }

    @GetMapping("/{id}/transitions")
    @Operation(summary = "Historique des transitions d'état")
    public List<TransitionReponse> transitions(@PathVariable UUID id) {
        return paiements.transitionsDe(id).stream().map(TransitionReponse::de).toList();
    }

    @GetMapping("/{id}/audit")
    @Operation(summary = "Piste d'audit (append-only, hachage chaîné) du paiement")
    public List<AuditReponse> auditDuPaiement(@PathVariable UUID id) {
        Paiement p = paiements.trouver(id);
        return audit.pour("payment", p.getId().toString()).stream().map(AuditReponse::de).toList();
    }

    @PostMapping("/{id}/refund")
    @Operation(summary = "Rembourser un paiement réussi (SUCCESS → REFUNDED)")
    public PaiementReponse rembourser(@PathVariable UUID id, @RequestBody(required = false) RemboursementRequete requete) {
        String motif = requete == null ? null : requete.motif();
        return PaiementReponse.de(paiements.rembourser(id, motif));
    }
}
