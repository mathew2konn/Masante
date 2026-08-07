package ci.masante.payment.web.dto;

import ci.masante.payment.domain.model.DestinationReversement;

import java.time.Instant;
import java.util.UUID;

/**
 * Vue d'une destination de reversement. {@code ref_chiffree} n'est JAMAIS exposée ; {@code libelle}
 * (opérateur + 8 hex d'empreinte) et {@code empreinte} suffisent à distinguer/vérifier sans fuite.
 */
public record DestinationReponse(UUID id, String etablissementRef, String type, String libelle,
                                 String empreinte, Instant valideDu, Instant valideAu, String creePar) {

    public static DestinationReponse de(DestinationReversement d) {
        return new DestinationReponse(d.getId(), d.getEtablissementRef(), d.getType().name(), d.getLibelle(),
                d.getEmpreinte(), d.getValideDu(), d.getValideAu(), d.getCreePar());
    }
}
