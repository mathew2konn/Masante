package ci.masante.payment.domain.billing;

import ci.masante.payment.domain.coverage.TypePriseEnCharge;

import java.util.List;

/**
 * Résultat du calcul d'une facture. Invariant : {@code montantCouvert + resteAPayer == montantTtc}.
 * Ces montants sont calculés dans le domaine (frontière) et seulement affichés par le front.
 */
public record ResultatFacturation(
        long sousTotalHt,
        long totalRemises,
        long totalTva,
        long montantTtc,
        TypePriseEnCharge couvertureType,
        Integer couvertureTaux,
        long montantCouvert,
        long resteAPayer,
        List<LigneCalculee> lignes
) {
}
