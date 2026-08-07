package ci.masante.payment.domain.reversement;

import java.time.Instant;
import java.util.UUID;

/**
 * Remboursement (REUSSI) candidat à l'imputation sur un relevé. Donnée d'entrée PURE : fournie déjà
 * bornée par la requête d'assiette (établissement dénormalisé, {@code cree_le ∈ [début,fin)}, non déjà
 * imputé). Date d'imputation = {@code creeLe} (immuable ; le remboursement naît REUSSI).
 */
public record RemboursementImputable(UUID remboursementId, String reference, Instant creeLe, long montant) {
}
