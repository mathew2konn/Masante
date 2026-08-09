package ci.masante.payment.web.dto;

import ci.masante.payment.domain.model.MandatEcheance;

import java.time.Instant;
import java.time.LocalDate;
import java.util.UUID;

/** Vue d'une échéance de mandat (§5.4). */
public record MandatEcheanceReponse(
        UUID id,
        int numeroSequence,
        LocalDate datePrevue,
        long montant,
        String devise,
        String statut,
        Instant preavisLe,
        Instant executeLe,
        UUID paiementId,
        UUID carteTransactionId,
        String codeRefus
) {
    public static MandatEcheanceReponse de(MandatEcheance e) {
        return new MandatEcheanceReponse(e.getId(), e.getNumeroSequence(), e.getDatePrevue(), e.getMontant(),
                e.getDevise(), e.getStatut().name(), e.getPreavisLe(), e.getExecuteLe(),
                e.getPaiementId(), e.getCarteTransactionId(), e.getCodeRefus());
    }
}
