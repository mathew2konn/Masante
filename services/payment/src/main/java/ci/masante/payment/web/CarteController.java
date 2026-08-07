package ci.masante.payment.web;

import ci.masante.payment.service.ServiceCarte;
import ci.masante.payment.web.dto.CartePaiementReponse;
import ci.masante.payment.web.dto.CarteReponse;
import ci.masante.payment.web.dto.InitierCartePaiementRequete;
import ci.masante.payment.web.dto.RembourserCarteRequete;
import io.swagger.v3.oas.annotations.Operation;
import io.swagger.v3.oas.annotations.Parameter;
import io.swagger.v3.oas.annotations.tags.Tag;
import jakarta.validation.Valid;
import jakarta.validation.constraints.NotBlank;
import org.springframework.http.HttpStatus;
import org.springframework.http.ResponseEntity;
import org.springframework.validation.annotation.Validated;
import org.springframework.web.bind.annotation.DeleteMapping;
import org.springframework.web.bind.annotation.GetMapping;
import org.springframework.web.bind.annotation.PathVariable;
import org.springframework.web.bind.annotation.PostMapping;
import org.springframework.web.bind.annotation.RequestBody;
import org.springframework.web.bind.annotation.RequestHeader;
import org.springframework.web.bind.annotation.RequestMapping;
import org.springframework.web.bind.annotation.ResponseStatus;
import org.springframework.web.bind.annotation.RestController;

import java.util.List;
import java.util.UUID;

/**
 * API paiements par CARTE (CDC_06 §5, ADR-015). PAIEMENT SIMULÉ (FT5).
 *
 * <p><b>Identité</b> : {@code X-Utilisateur-Id} est posé par la passerelle authentifiée (jamais le corps,
 * non usurpable) — il porte la propriété du vault et de l'opération. <b>Idempotence</b> :
 * {@code Idempotency-Key} obligatoire sur toute écriture financière (§9.6). <b>PCI</b> : le corps ne
 * contient jamais de PAN/CVV (le {@code FiltreAntiPan} rejette en amont, §9) ; le sous-état interne
 * {@code StatutCarte} n'est jamais exposé (interdit #8).</p>
 */
@RestController
@RequestMapping("/api/v1")
@Validated
@Tag(name = "Cartes", description = "Paiement par carte (3DS2, capture, remboursement) + vault — simulé")
public class CarteController {

    private final ServiceCarte cartes;

    public CarteController(ServiceCarte cartes) {
        this.cartes = cartes;
    }

    // --- paiement -------------------------------------------------------------------------------

    @PostMapping("/card-payments")
    @Operation(summary = "Initier un paiement carte (idempotent ; 3DS/redirection selon la modalité du PSP)")
    public ResponseEntity<CartePaiementReponse> initier(
            @Parameter(description = "Identité de l'utilisateur, posée par la passerelle", required = true)
            @RequestHeader("X-Utilisateur-Id") @NotBlank String utilisateurRef,
            @Parameter(description = "Clé d'idempotence unique par tentative", required = true)
            @RequestHeader("Idempotency-Key") @NotBlank String idempotencyKey,
            @Valid @RequestBody InitierCartePaiementRequete requete) {

        CartePaiementReponse reponse = CartePaiementReponse.de(
                cartes.initier(requete.versCommande(utilisateurRef), idempotencyKey));
        HttpStatus statut = reponse.rejoue() ? HttpStatus.OK : HttpStatus.CREATED;
        return ResponseEntity.status(statut).body(reponse);
    }

    @GetMapping("/card-payments/{paiementId}")
    @Operation(summary = "Consulter l'état d'un paiement carte (statut générique + action client)")
    public CartePaiementReponse consulter(@PathVariable UUID paiementId) {
        return CartePaiementReponse.de(cartes.consulter(paiementId));
    }

    @PostMapping("/card-payments/{paiementId}/finalize")
    @Operation(summary = "Finaliser après interaction : lit le statut AUTORITATIF du PSP (jamais le client)")
    public CartePaiementReponse finaliser(@PathVariable UUID paiementId) {
        return CartePaiementReponse.de(cartes.finaliser(paiementId));
    }

    @PostMapping("/card-payments/{paiementId}/refund")
    @Operation(summary = "Rembourser (total ou partiel) vers la carte d'origine (idempotent)")
    public CartePaiementReponse rembourser(
            @PathVariable UUID paiementId,
            @RequestHeader("Idempotency-Key") @NotBlank String idempotencyKey,
            @Valid @RequestBody RembourserCarteRequete requete) {
        return CartePaiementReponse.de(cartes.rembourser(
                paiementId, requete.montant(), requete.devise(), requete.motif(), idempotencyKey));
    }

    // --- vault ----------------------------------------------------------------------------------

    @GetMapping("/cards")
    @Operation(summary = "Lister les cartes enregistrées de l'utilisateur (métadonnées non sensibles)")
    public List<CarteReponse> lister(@RequestHeader("X-Utilisateur-Id") @NotBlank String utilisateurRef) {
        return cartes.listerCartes(utilisateurRef).stream().map(CarteReponse::de).toList();
    }

    @DeleteMapping("/cards/{carteId}")
    @Operation(summary = "Supprimer une carte du vault (soft delete ; contrôle de propriété)")
    @ResponseStatus(HttpStatus.NO_CONTENT)
    public void supprimer(@RequestHeader("X-Utilisateur-Id") @NotBlank String utilisateurRef,
                          @PathVariable UUID carteId) {
        cartes.supprimerCarte(utilisateurRef, carteId);
    }

    @PostMapping("/cards/{carteId}/default")
    @Operation(summary = "Définir une carte comme carte par défaut")
    @ResponseStatus(HttpStatus.NO_CONTENT)
    public void definirParDefaut(@RequestHeader("X-Utilisateur-Id") @NotBlank String utilisateurRef,
                                 @PathVariable UUID carteId) {
        cartes.definirParDefaut(utilisateurRef, carteId);
    }
}
