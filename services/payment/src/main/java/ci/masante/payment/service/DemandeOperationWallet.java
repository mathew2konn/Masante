package ci.masante.payment.service;

import ci.masante.payment.domain.model.TypeOperationWallet;

import java.util.UUID;

/** Demande interne d'opération wallet (construite par les méthodes publiques du service). */
public record DemandeOperationWallet(
        TypeOperationWallet type,
        UUID sourceWalletId,
        UUID destWalletId,
        long montant,
        String reference,
        String libelle,
        UUID factureId,
        String idempotencyKey
) {
}
