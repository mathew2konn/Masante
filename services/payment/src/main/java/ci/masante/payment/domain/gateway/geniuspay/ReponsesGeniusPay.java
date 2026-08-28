package ci.masante.payment.domain.gateway.geniuspay;

import com.fasterxml.jackson.annotation.JsonIgnoreProperties;
import com.fasterxml.jackson.annotation.JsonProperty;

import java.math.BigDecimal;
import java.util.Map;

/**
 * Formes de fil du prestataire. {@code ignoreUnknown} n'est pas une facilité : la vérification en bac
 * à sable a montré des champs absents de la documentation ({@code scenario}, {@code gateway},
 * {@code tokens_remaining}) et des champs documentés absents de la réponse. Casser sur un champ
 * inattendu ferait échouer une transaction pour une raison qui ne la concerne pas.
 *
 * <p><b>Les montants sont des {@link BigDecimal}, jamais des {@code double}.</b> GeniusPay renvoie
 * {@code "amount": 10000.00} : lu en virgule flottante, un montant peut perdre un franc, et un franc
 * perdu sur un compte de tiers est une erreur comptable.</p>
 */
public final class ReponsesGeniusPay {

    private ReponsesGeniusPay() {
    }

    @JsonIgnoreProperties(ignoreUnknown = true)
    public record Enveloppe<T>(boolean success, String message, T data) {
    }

    @JsonIgnoreProperties(ignoreUnknown = true)
    public record Paiement(
            String reference,
            BigDecimal amount,
            String currency,
            String status,
            BigDecimal fees,
            @JsonProperty("net_amount") BigDecimal netAmount,
            @JsonProperty("checkout_url") String checkoutUrl,
            @JsonProperty("expires_at") String expiresAt,
            @JsonProperty("payment_method") String paymentMethod,
            @JsonProperty("payment_provider") String paymentProvider,
            String environment,
            Map<String, Object> metadata,
            @JsonProperty("completed_at") String completedAt
    ) {
    }

    @JsonIgnoreProperties(ignoreUnknown = true)
    public record Webhook(
            String id,
            String url,
            String secret,
            java.util.List<String> events
    ) {
    }

    @JsonIgnoreProperties(ignoreUnknown = true)
    public record Erreur(String code, String message) {
    }
}
