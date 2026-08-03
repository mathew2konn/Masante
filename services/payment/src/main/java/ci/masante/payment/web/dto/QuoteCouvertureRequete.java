package ci.masante.payment.web.dto;

import ci.masante.payment.domain.coverage.RequeteCouverture;
import ci.masante.payment.domain.coverage.TypePriseEnCharge;
import jakarta.validation.constraints.Max;
import jakarta.validation.constraints.Min;
import jakarta.validation.constraints.NotNull;
import jakarta.validation.constraints.Positive;
import jakarta.validation.constraints.PositiveOrZero;

/** Corps de {@code POST /api/v1/coverage/quote} (CDC_06 §8). Montants en FCFA. */
public record QuoteCouvertureRequete(
        @Positive(message = "Le montant total doit être strictement positif.") long montantTotal,
        @NotNull(message = "Le type de prise en charge est obligatoire.") TypePriseEnCharge type,
        @Min(value = 0, message = "Le taux doit être ≥ 0.")
        @Max(value = 100, message = "Le taux doit être ≤ 100.") int tauxCouverture,
        @PositiveOrZero(message = "Le plafond ne peut pas être négatif.") Long plafond,
        boolean exclu
) {
    public RequeteCouverture versRequete() {
        return new RequeteCouverture(montantTotal, type, tauxCouverture, plafond, exclu);
    }
}
