package ci.masante.payment.web.dto;

import ci.masante.payment.service.ServiceRecompense.ResultatCashback;

/**
 * Résultat d'un cashback. {@code accorde=false} + {@code montant>0} = <b>dry-run</b> (calculé, non
 * crédité). ⚠️ Front : {@code montant} en dry-run n'est PAS un gain acquis — ne pas l'afficher comme tel.
 */
public record CashbackReponse(boolean accorde, long montant, String campagneCode, String raison) {

    public static CashbackReponse de(ResultatCashback r) {
        return new CashbackReponse(r.accorde(), r.montant(), r.campagneCode(), r.raison());
    }
}
