package ci.masante.payment.web.dto;

import jakarta.validation.constraints.NotBlank;
import jakarta.validation.constraints.Positive;

/** Corps de {@code POST /api/v1/wallets/{id}/bonus}. L'acteur vient de l'en-tête, pas du corps. */
public record AccorderBonusRequete(
        @Positive(message = "Le montant doit être positif.") long montant,
        @NotBlank(message = "Le motif est obligatoire.") String motif
) {
}
