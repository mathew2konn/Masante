package ci.masante.payment.web.dto;

import ci.masante.payment.domain.model.OwnerTypeWallet;
import jakarta.validation.constraints.NotBlank;
import jakarta.validation.constraints.NotNull;

/** Corps de {@code POST /api/v1/wallets}. */
public record CreerWalletRequete(
        @NotBlank(message = "La référence du titulaire est obligatoire.") String ownerRef,
        @NotNull(message = "Le type de titulaire est obligatoire.") OwnerTypeWallet ownerType,
        String devise
) {
}
