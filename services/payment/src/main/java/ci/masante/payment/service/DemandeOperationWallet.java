package ci.masante.payment.service;

import ci.masante.payment.domain.model.TypeOperationWallet;

import java.util.UUID;

/**
 * Demande interne d'opération wallet (construite par les méthodes publiques du service).
 * {@code otp} et {@code otpDejaVerifie} servent au palier CHALLENGE de la détection de fraude
 * (re-auth OTP en cours d'opération). {@code toString} masque l'OTP (jamais loggé).
 */
public record DemandeOperationWallet(
        TypeOperationWallet type,
        UUID sourceWalletId,
        UUID destWalletId,
        long montant,
        String reference,
        String libelle,
        UUID factureId,
        String idempotencyKey,
        String otp,
        boolean otpDejaVerifie
) {
    @Override
    public String toString() {
        return "DemandeOperationWallet[type=" + type + ", sourceWalletId=" + sourceWalletId
                + ", destWalletId=" + destWalletId + ", montant=" + montant + ", reference=" + reference
                + ", libelle=" + libelle + ", factureId=" + factureId + ", idempotencyKey="
                + idempotencyKey + ", otp=***, otpDejaVerifie=" + otpDejaVerifie + "]";
    }
}
