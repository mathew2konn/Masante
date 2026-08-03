package ci.masante.payment.web.dto;

import ci.masante.payment.domain.model.WalletEntry;

import java.time.Instant;
import java.util.UUID;

/** Écriture du grand livre : {@code montant} signé (+crédit / −débit). */
public record WalletEntryReponse(
        UUID operationId,
        long montant,
        Instant createdAt
) {
    public static WalletEntryReponse de(WalletEntry e) {
        return new WalletEntryReponse(e.getOperationId(), e.getMontant(), e.getCreatedAt());
    }
}
