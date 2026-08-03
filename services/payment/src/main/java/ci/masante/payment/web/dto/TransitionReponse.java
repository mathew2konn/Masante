package ci.masante.payment.web.dto;

import ci.masante.payment.domain.model.PaiementStatut;
import ci.masante.payment.domain.model.TransitionPaiement;

import java.time.Instant;

public record TransitionReponse(
        PaiementStatut statutDe,
        PaiementStatut statutVers,
        String raison,
        Instant horodatage
) {
    public static TransitionReponse de(TransitionPaiement t) {
        return new TransitionReponse(t.getStatutDe(), t.getStatutVers(), t.getRaison(), t.getCreatedAt());
    }
}
