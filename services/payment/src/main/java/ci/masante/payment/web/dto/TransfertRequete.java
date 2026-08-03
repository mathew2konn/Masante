package ci.masante.payment.web.dto;

import jakarta.validation.constraints.NotNull;
import jakarta.validation.constraints.Positive;

import java.util.UUID;

/** Corps de {@code POST /api/v1/wallets/transfer}. */
public record TransfertRequete(
        @NotNull(message = "Le portefeuille source est obligatoire.") UUID sourceWalletId,
        @NotNull(message = "Le portefeuille destinataire est obligatoire.") UUID destWalletId,
        @Positive(message = "Le montant doit être strictement positif.") long montant,
        String libelle
) {
}
