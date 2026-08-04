package ci.masante.payment.web.dto;

import ci.masante.payment.domain.model.TypeOperationWallet;
import ci.masante.payment.domain.model.WalletOperation;

import java.time.Instant;
import java.util.UUID;

/** Vue publique d'une opération wallet (les deux écritures sont consultables via /entries). */
public record WalletOperationReponse(
        UUID id,
        TypeOperationWallet type,
        long montant,
        UUID sourceWalletId,
        UUID destWalletId,
        String reference,
        String libelle,
        UUID factureId,
        boolean signee,
        Instant createdAt
) {
    public static WalletOperationReponse de(WalletOperation o) {
        return new WalletOperationReponse(o.getId(), o.getType(), o.getMontant(), o.getSourceWalletId(),
                o.getDestWalletId(), o.getReference(), o.getLibelle(), o.getFactureId(),
                o.getSignature() != null, o.getCreatedAt());
    }
}
