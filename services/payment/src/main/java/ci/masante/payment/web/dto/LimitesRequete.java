package ci.masante.payment.web.dto;

import io.swagger.v3.oas.annotations.media.Schema;

/** Corps de {@code PUT /api/v1/wallets/{id}/limits}. null sur un champ = « utiliser le défaut ». */
public record LimitesRequete(
        @Schema(description = "Plafond par opération (FCFA), null = défaut.") Long plafondOperation,
        @Schema(description = "Plafond cumulé par jour (FCFA), null = défaut.") Long plafondJour,
        @Schema(description = "Plafond cumulé par mois (FCFA), null = défaut.") Long plafondMois
) {
}
