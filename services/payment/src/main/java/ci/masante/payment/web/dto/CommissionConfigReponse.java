package ci.masante.payment.web.dto;

import ci.masante.payment.domain.model.CommissionConfig;

import java.time.Instant;
import java.util.UUID;

/** Taux de commission historisé (bps entiers ; etablissementRef null = défaut plateforme). */
public record CommissionConfigReponse(UUID id, String etablissementRef, int tauxBps, Instant valideDu,
                                      Instant valideAu, String motif, String creePar, Instant creeA) {

    public static CommissionConfigReponse de(CommissionConfig c) {
        return new CommissionConfigReponse(c.getId(), c.getEtablissementRef(), c.getTauxBps(),
                c.getValideDu(), c.getValideAu(), c.getMotif(), c.getCreePar(), c.getCreeA());
    }
}
