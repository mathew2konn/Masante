package ci.masante.payment.web.dto;

import ci.masante.payment.domain.model.ObjetPaiement;
import ci.masante.payment.domain.model.Paiement;
import ci.masante.payment.domain.model.PaiementStatut;

import java.time.Instant;
import java.util.UUID;

/** Vue publique d'un paiement. Le numéro de téléphone n'apparaît que masqué. */
public record PaiementReponse(
        UUID id,
        PaiementStatut statut,
        long montant,
        String devise,
        String canal,
        ObjetPaiement objet,
        String telephoneMasque,
        String providerRef,
        String correlationId,
        String etablissementRef,
        String patientRef,
        UUID factureId,
        Instant createdAt,
        Instant confirmedAt
) {
    public static PaiementReponse de(Paiement p) {
        return new PaiementReponse(
                p.getId(), p.getStatut(), p.getMontant(), p.getDevise(), p.getCanal(), p.getObjet(),
                p.getTelephoneMasque(), p.getProviderRef(), p.getCorrelationId(),
                p.getEtablissementRef(), p.getPatientRef(), p.getFactureId(),
                p.getCreatedAt(), p.getConfirmedAt());
    }
}
