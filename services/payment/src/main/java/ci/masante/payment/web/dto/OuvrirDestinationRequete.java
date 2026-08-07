package ci.masante.payment.web.dto;

import ci.masante.payment.domain.model.TypeDestination;
import jakarta.validation.constraints.NotBlank;
import jakarta.validation.constraints.NotNull;

/**
 * Corps de {@code POST /api/v1/settlements/destinations}. La {@code reference} (MSISDN/IBAN) est
 * validée, chiffrée et n'est JAMAIS re-renvoyée. L'acteur/rôle viennent du principal signé.
 */
public record OuvrirDestinationRequete(
        @NotBlank String etablissementRef,
        @NotNull TypeDestination type,
        @NotBlank String reference,
        @NotBlank String motif) {
}
