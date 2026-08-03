package ci.masante.payment.web.dto;

import ci.masante.payment.domain.coverage.ResultatCouverture;
import ci.masante.payment.domain.coverage.TypePriseEnCharge;

/**
 * Vue publique du calcul de prise en charge. Le front N'AFFICHE que ces montants — il ne les
 * recalcule jamais (frontière). Invariant : {@code montantCouvert + resteACharge == montantTotal}.
 */
public record CouvertureReponse(
        long montantTotal,
        TypePriseEnCharge type,
        int tauxApplique,
        long montantCouvert,
        long ticketModerateur,
        long resteACharge,
        boolean plafondApplique,
        boolean exclu
) {
    public static CouvertureReponse de(ResultatCouverture r) {
        return new CouvertureReponse(r.montantTotal(), r.type(), r.tauxApplique(), r.montantCouvert(),
                r.ticketModerateur(), r.resteACharge(), r.plafondApplique(), r.exclu());
    }
}
