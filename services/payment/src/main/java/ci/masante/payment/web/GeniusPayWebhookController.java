package ci.masante.payment.web;

import ci.masante.payment.service.ServiceWebhookGeniusPay;
import io.swagger.v3.oas.annotations.Operation;
import io.swagger.v3.oas.annotations.tags.Tag;
import jakarta.servlet.http.HttpServletRequest;
import org.springframework.http.ResponseEntity;
import org.springframework.web.bind.annotation.PathVariable;
import org.springframework.web.bind.annotation.PostMapping;
import org.springframework.web.bind.annotation.RequestBody;
import org.springframework.web.bind.annotation.RequestHeader;
import org.springframework.web.bind.annotation.RequestMapping;
import org.springframework.web.bind.annotation.RestController;

import java.util.Map;

/**
 * Réception des webhooks GeniusPay. Route conforme à la convention déjà en place dans le service
 * ({@code /api/v1/…-webhooks/{psp}}, cf. {@code CarteWebhookController}), jamais
 * {@code POST /webhooks/geniuspay} que proposait le prompt.
 *
 * <h2>Le slug dans l'URL — le trou du montage A, et sa réponse</h2>
 * <p>Un compte marchand par établissement signifie un secret webhook par établissement. Or la
 * signature doit être vérifiée <b>avant</b> toute lecture de confiance du corps, c'est-à-dire avant
 * de savoir de quel établissement il s'agit. Essayer les secrets en cascade jusqu'à ce qu'un HMAC
 * passe coûterait O(n) par requête et offrirait un oracle de temps à l'attaquant. La réponse est
 * une <b>URL de rappel distincte par établissement</b>, enregistrée telle quelle chez le prestataire,
 * portant un identifiant <b>opaque et aléatoire</b>. Jamais l'identifiant de l'établissement : une
 * URL énumérable révélerait la liste des partenaires.</p>
 *
 * <h2>Authentification</h2>
 * <p>Cette route n'est volontairement soumise à aucune authentification applicative : <b>la signature
 * HMAC fait foi</b>, et le prestataire ne peut pas porter notre principal signé. Ce n'est pas une
 * exemption large — l'autorisation, dans ce service, est <b>opt-in par contrôleur</b> (chaque
 * contrôleur protégé appelle explicitement {@code ServicePrincipal}), il n'existe aucun filtre
 * global à contourner. L'absence de {@code spring-boot-starter-security} est délibérée et
 * documentée : son auto-configuration verrouillerait d'un coup les vingt-trois contrôleurs
 * existants.</p>
 *
 * <h2>Le corps est lu en octets bruts</h2>
 * <p>{@code byte[]}, jamais un DTO désérialisé ni un {@code JsonNode} : la signature porte sur les
 * octets exacts. Un ré-encodage transforme {@code 10000.00} en {@code 10000.0} et la vérification
 * échoue — c'est le piège dans lequel tombe l'exemple PHP de la documentation officielle.</p>
 */
@RestController
@RequestMapping("/api/v1/paiement-webhooks/geniuspay")
@Tag(name = "GeniusPay — Webhooks", description = "Réception signée des événements de paiement")
public class GeniusPayWebhookController {

    private final ServiceWebhookGeniusPay webhooks;

    public GeniusPayWebhookController(ServiceWebhookGeniusPay webhooks) {
        this.webhooks = webhooks;
    }

    /**
     * Répond en moins de 500 ms parce qu'elle ne fait qu'enregistrer : le prestataire attend une
     * réponse sous dix secondes et réessaie cinq fois au-delà. Le traitement, lui, est repris par le
     * relais planifié.
     */
    @PostMapping("/{slug}")
    @Operation(summary = "Recevoir un événement GeniusPay (HMAC sur corps brut + anti-rejeu + déduplication)")
    public ResponseEntity<Void> recevoir(@PathVariable String slug,
                                         @RequestBody(required = false) byte[] corps,
                                         @RequestHeader Map<String, String> entetes,
                                         HttpServletRequest requete) {
        int statut = webhooks.recevoir(slug, corps, entetes, requete.getRemoteAddr());
        // Corps TOUJOURS vide : ni sur 200, ni sur 401, ni sur 400. Un attaquant qui sonde cette route
        // ne doit pas pouvoir distinguer « slug inconnu » de « signature fausse ».
        return ResponseEntity.status(statut).build();
    }
}
