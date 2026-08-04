package ci.masante.payment.web.dto;

import jakarta.validation.constraints.Positive;

/** Corps de {@code POST /api/v1/wallets/{id}/otp} : montant de l'opération à autoriser (FCFA). */
public record GenererOtpRequete(
        @Positive(message = "Le montant doit être strictement positif.") long montant
) {
}
