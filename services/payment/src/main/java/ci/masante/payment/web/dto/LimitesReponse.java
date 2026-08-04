package ci.masante.payment.web.dto;

import ci.masante.payment.service.ServiceSecuriteWallet.LimitesEffectives;

/** Limites effectives d'un wallet (FCFA). Une valeur {@code <= 0} signifie « illimité ». */
public record LimitesReponse(long plafondOperation, long plafondJour, long plafondMois) {

    public static LimitesReponse de(LimitesEffectives e) {
        return new LimitesReponse(e.operation(), e.jour(), e.mois());
    }
}
