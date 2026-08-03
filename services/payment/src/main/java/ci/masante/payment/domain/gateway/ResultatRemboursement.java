package ci.masante.payment.domain.gateway;

/** Résultat d'un remboursement. {@code reussi} vrai si la passerelle a accepté le remboursement. */
public record ResultatRemboursement(
        boolean reussi,
        String referenceOperateur,
        String message
) {
}
