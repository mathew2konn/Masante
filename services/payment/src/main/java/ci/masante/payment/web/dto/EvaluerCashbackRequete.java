package ci.masante.payment.web.dto;

import jakarta.validation.constraints.NotNull;

import java.util.UUID;

/** Corps de {@code POST /api/v1/wallets/{id}/cashback} : le serveur résout la campagne + la base. */
public record EvaluerCashbackRequete(
        @NotNull(message = "L'opération source est obligatoire.") UUID operationSourceId
) {
}
