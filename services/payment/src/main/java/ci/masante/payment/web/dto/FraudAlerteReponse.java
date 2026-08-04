package ci.masante.payment.web.dto;

import ci.masante.payment.domain.model.FraudAlerte;
import ci.masante.payment.domain.model.StatutAlerteFraude;
import com.fasterxml.jackson.annotation.JsonRawValue;

import java.time.Instant;
import java.util.UUID;

/**
 * Vue d'une alerte de fraude (usage interne/revue). {@code motifs} et {@code parametres} sont émis
 * comme JSON natif ({@link JsonRawValue}) : le snapshot des paramètres permet de rejouer le score.
 */
public record FraudAlerteReponse(
        UUID id,
        UUID walletId,
        int score,
        String palier,
        @JsonRawValue String motifs,
        @JsonRawValue String parametres,
        long montantTente,
        StatutAlerteFraude statut,
        Instant createdAt,
        Instant revueAt,
        String revuePar
) {
    public static FraudAlerteReponse de(FraudAlerte a) {
        return new FraudAlerteReponse(a.getId(), a.getWalletId(), a.getScore(), a.getPalier(),
                a.getMotifs(), a.getParametres(), a.getMontantTente(), a.getStatut(),
                a.getCreatedAt(), a.getRevueAt(), a.getRevuePar());
    }
}
