package ci.masante.payment.domain.coverage;

/**
 * Résultat du calcul de prise en charge (CDC_06 §8.3). Tous les montants sont en FCFA.
 *
 * <p>Invariant : {@code montantCouvert + resteACharge == montantTotal}. Le patient ne paie que
 * {@code resteACharge}. Ces montants sont calculés ICI (backend) et seulement AFFICHÉS par le front.</p>
 *
 * @param ticketModerateur part de coassurance laissée au patient (= reste à charge tant qu'aucune
 *                         franchise/forfait n'est modélisé — incrément ultérieur)
 * @param plafondApplique  vrai si le plafond a limité la couverture
 */
public record ResultatCouverture(
        long montantTotal,
        TypePriseEnCharge type,
        int tauxApplique,
        long montantCouvert,
        long ticketModerateur,
        long resteACharge,
        boolean plafondApplique,
        boolean exclu
) {
}
