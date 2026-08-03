package ci.masante.payment.web.dto;

import jakarta.validation.constraints.NotNull;
import jakarta.validation.constraints.PositiveOrZero;

import java.util.UUID;

/** Corps de {@code POST /api/v1/wallets/{id}/pay-invoice}. Montant 0 = solder tout le dû restant. */
public record PayerFactureWalletRequete(
        @NotNull(message = "La facture est obligatoire.") UUID factureId,
        @PositiveOrZero(message = "Le montant ne peut pas être négatif.") long montant
) {
}
