package ci.masante.payment.service;

import ci.masante.payment.domain.model.PaiementStatut;

import java.util.UUID;

/**
 * Résultat d'un débit MIT d'échéance (ServiceCarte.debiterMandat). Porte les références du paiement et de
 * la transaction carte créés (traçabilité), l'issue, et {@code rejoue} si la clé d'idempotence existait déjà.
 */
public record ResultatDebitMandat(
        UUID paiementId,
        UUID carteTransactionId,
        PaiementStatut statut,
        boolean reussi,
        String codeRefus,
        boolean rejoue
) {
}
