package ci.masante.payment.web.dto;

import ci.masante.payment.domain.model.DecaissementReversement;

import java.time.Instant;
import java.util.UUID;

/**
 * Vue d'une tentative de décaissement. La référence de destination en clair n'y figure JAMAIS ; seule
 * {@code referencePasserelle} (réf opérateur) est exposée pour la traçabilité.
 */
public record DecaissementReponse(UUID id, UUID releveId, UUID destinationId, String statut,
                                  long montantNet, long frais, String devise, String referencePasserelle,
                                  String motifEchec, String creePar, Instant creeLe, Instant majLe) {

    public static DecaissementReponse de(DecaissementReversement d) {
        return new DecaissementReponse(d.getId(), d.getReleveId(), d.getDestinationId(), d.getStatut().name(),
                d.getMontantNet(), d.getFrais(), d.getDevise(), d.getReferencePasserelle(),
                d.getMotifEchec(), d.getCreePar(), d.getCreeLe(), d.getMajLe());
    }
}
