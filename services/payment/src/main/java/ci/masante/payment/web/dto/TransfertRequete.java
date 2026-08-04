package ci.masante.payment.web.dto;

import io.swagger.v3.oas.annotations.media.Schema;
import jakarta.validation.constraints.NotNull;
import jakarta.validation.constraints.Positive;

import java.util.UUID;

/** Corps de {@code POST /api/v1/wallets/transfer}. Le {@code pin} du wallet source est exigé (§6.4). */
public record TransfertRequete(
        @NotNull(message = "Le portefeuille source est obligatoire.") UUID sourceWalletId,
        @NotNull(message = "Le portefeuille destinataire est obligatoire.") UUID destWalletId,
        @Positive(message = "Le montant doit être strictement positif.") long montant,
        String libelle,
        @Schema(description = "PIN du wallet source (requis).") String pin,
        @Schema(description = "OTP (requis au-delà du seuil de montant).") String otp
) {
    @Override
    public String toString() {
        return "TransfertRequete[sourceWalletId=" + sourceWalletId + ", destWalletId=" + destWalletId
                + ", montant=" + montant + ", libelle=" + libelle + ", pin=***, otp=***]";
    }
}
