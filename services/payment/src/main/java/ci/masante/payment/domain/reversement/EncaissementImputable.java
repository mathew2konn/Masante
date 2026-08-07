package ci.masante.payment.domain.reversement;

import java.time.Instant;
import java.util.UUID;

/**
 * Encaissement (facture soldée) candidat à l'imputation sur un relevé. Donnée d'entrée PURE du calcul :
 * fournie déjà bornée par la requête d'assiette (établissement, {@code soldee_a ∈ [début,fin)},
 * {@code montantRegle > 0}, non déjà imputée).
 */
public record EncaissementImputable(UUID factureId, String numero, Instant soldeeA, long montantRegle) {
}
