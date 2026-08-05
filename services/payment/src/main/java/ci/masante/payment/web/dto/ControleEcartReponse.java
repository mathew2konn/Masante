package ci.masante.payment.web.dto;

import java.time.Instant;

/** Un écart détecté (détection seule — jamais corrigé). {@code details} = objet JSON rejoué du snapshot. */
public record ControleEcartReponse(String controle, String typeEcart, String severite, String reference,
                                   Long montantAttendu, Long montantConstate, Object details,
                                   Instant createdAt) {
}
