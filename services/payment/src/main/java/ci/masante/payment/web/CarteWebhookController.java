package ci.masante.payment.web;

import ci.masante.payment.service.ServiceCarte;
import io.swagger.v3.oas.annotations.Operation;
import io.swagger.v3.oas.annotations.tags.Tag;
import org.springframework.http.ResponseEntity;
import org.springframework.web.bind.annotation.PathVariable;
import org.springframework.web.bind.annotation.PostMapping;
import org.springframework.web.bind.annotation.RequestBody;
import org.springframework.web.bind.annotation.RequestHeader;
import org.springframework.web.bind.annotation.RequestMapping;
import org.springframework.web.bind.annotation.RestController;

import java.util.Map;

/**
 * Réception des webhooks PSP (CDC_06 §7.3, ADR-015) — <b>source de vérité</b> du résultat 3DS/autorisation.
 *
 * <p>Le corps est reçu en <b>octets bruts</b> ({@code byte[]}) : la signature HMAC est calculée sur ces
 * octets EXACTS, avant toute désérialisation. Ce chemin est EXCLU du {@code FiltreAntiPan} (une charge PSP
 * signée ne doit être ni mise en cache ni inspectée par le filtre — sa vérité est la signature). Réponse
 * TOUJOURS 200 en cas de succès OU de rejeu ; un webhook invalide renvoie 401 générique (anti-fuite).</p>
 */
@RestController
@RequestMapping("/api/v1/card-webhooks")
@Tag(name = "Cartes — Webhooks", description = "Réception signée des événements PSP (simulé)")
public class CarteWebhookController {

    private final ServiceCarte cartes;

    public CarteWebhookController(ServiceCarte cartes) {
        this.cartes = cartes;
    }

    @PostMapping("/{psp}")
    @Operation(summary = "Recevoir un webhook PSP signé (HMAC + anti-rejeu + déduplication)")
    public ResponseEntity<Void> recevoir(@PathVariable String psp,
                                         @RequestBody(required = false) byte[] corps,
                                         @RequestHeader Map<String, String> entetes) {
        cartes.appliquerWebhook(psp, corps, entetes);
        return ResponseEntity.ok().build();
    }
}
