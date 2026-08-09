package ci.masante.payment.web.dto;

import ci.masante.payment.domain.model.NotificationSortie;

import java.time.Instant;
import java.util.UUID;

/** Vue d'une notification sortante (outbox). Le contenu réel de {@code chargeUtile} est du JSON. */
public record NotificationReponse(
        UUID id,
        String type,
        String agregatType,
        UUID agregatId,
        String destinataireRef,
        String statut,
        String canalLivraison,
        String detail,
        int tentatives,
        String chargeUtile,
        Instant creeLe,
        Instant traiteLe
) {
    public static NotificationReponse de(NotificationSortie n) {
        return new NotificationReponse(n.getId(), n.getType().name(), n.getAgregatType(), n.getAgregatId(),
                n.getDestinataireRef(), n.getStatut().name(), n.getCanalLivraison(), n.getDetail(),
                n.getTentatives(), n.getChargeUtile(), n.getCreeLe(), n.getTraiteLe());
    }
}
