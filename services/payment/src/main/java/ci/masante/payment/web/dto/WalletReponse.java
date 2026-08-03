package ci.masante.payment.web.dto;

import ci.masante.payment.domain.model.OwnerTypeWallet;
import ci.masante.payment.domain.model.Wallet;
import ci.masante.payment.domain.model.WalletStatut;

import java.time.Instant;
import java.util.UUID;

/** Vue publique d'un portefeuille. Le {@code solde} est calculé (somme des écritures), jamais stocké. */
public record WalletReponse(
        UUID id,
        String ownerRef,
        OwnerTypeWallet ownerType,
        String devise,
        WalletStatut statut,
        long solde,
        Instant createdAt
) {
    public static WalletReponse de(Wallet w, long solde) {
        return new WalletReponse(w.getId(), w.getOwnerRef(), w.getOwnerType(), w.getDevise(),
                w.getStatut(), solde, w.getCreatedAt());
    }
}
