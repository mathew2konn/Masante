package ci.masante.payment.domain.gateway;

/** Requête de remboursement (SUCCESS → REFUNDED). Montant en FCFA. */
public record RequeteRemboursement(
        String referenceInterne,
        String referenceOperateur,
        long montant,
        String motif
) {
}
