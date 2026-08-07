package ci.masante.payment.web.dto;

import jakarta.validation.constraints.Max;
import jakarta.validation.constraints.Min;
import jakarta.validation.constraints.NotBlank;
import jakarta.validation.constraints.NotNull;

/**
 * Corps de {@code POST /api/v1/settlements/commission-config}. {@code etablissementRef} null = taux
 * par défaut de la plateforme. Taux en points de base entiers (250 = 2,50 %).
 */
public record OuvrirCommissionRequete(
        String etablissementRef,
        @NotNull @Min(0) @Max(10000) Integer tauxBps,
        @NotBlank String motif) {
}
