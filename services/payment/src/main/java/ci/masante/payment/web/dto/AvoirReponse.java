package ci.masante.payment.web.dto;

import ci.masante.payment.domain.model.Avoir;

import java.time.Instant;
import java.util.UUID;

/** Vue publique d'un avoir / note de crédit (CDC_06 §7.1). */
public record AvoirReponse(
        UUID id,
        String numero,
        UUID factureId,
        String etablissementRef,
        int exercice,
        long montant,
        String motif,
        String hashIntegrite,
        boolean signe,
        String signatureAlgo,
        Instant createdAt
) {
    public static AvoirReponse de(Avoir a) {
        return new AvoirReponse(a.getId(), a.getNumero(), a.getFactureId(), a.getEtablissementRef(),
                a.getExercice(), a.getMontant(), a.getMotif(), a.getHashIntegrite(),
                a.getSignature() != null, a.getSignatureAlgo(), a.getCreatedAt());
    }
}
