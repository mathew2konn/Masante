package ci.masante.payment.web.dto;

import io.swagger.v3.oas.annotations.media.Schema;
import jakarta.validation.constraints.NotNull;
import jakarta.validation.constraints.PositiveOrZero;

import java.util.UUID;

/**
 * Corps de {@code POST /api/v1/wallets/{id}/pay-invoice}. Montant 0 = solder tout le dû restant.
 * Le {@code pin} est exigé (opération sortante) ; l'{@code otp} au-delà du seuil (§6.4).
 */
public record PayerFactureWalletRequete(
        @NotNull(message = "La facture est obligatoire.") UUID factureId,
        @PositiveOrZero(message = "Le montant ne peut pas être négatif.") long montant,
        @Schema(description = "PIN wallet (requis).") String pin,
        @Schema(description = "OTP (requis au-delà du seuil de montant).") String otp
) {
    @Override
    public String toString() {
        return "PayerFactureWalletRequete[factureId=" + factureId + ", montant=" + montant
                + ", pin=***, otp=***]";
    }
}
