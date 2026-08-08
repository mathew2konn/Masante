package ci.masante.payment.web.dto;

import ci.masante.payment.domain.model.ReversementReconciliation;

import java.time.Instant;
import java.time.LocalDate;
import java.util.UUID;

/**
 * Vue d'un rapport de rapprochement « factures ↔ reversements » (P5.5c, §11). Le détail des écarts est
 * transmis tel quel (JSON de références et catégories, aucune donnée sensible : ni MSISDN, ni IBAN).
 */
public record ReversementReconciliationReponse(
        UUID id,
        LocalDate dateRapport,
        Instant cutOffT,
        int graceJours,
        Instant graceCutOff,
        int nbPiecesExaminees,
        int nbLignesExaminees,
        String statut,
        int nbEcarts,
        String ecarts,
        Instant genereLe
) {
    public static ReversementReconciliationReponse de(ReversementReconciliation r) {
        return new ReversementReconciliationReponse(r.getId(), r.getDateRapport(), r.getCutOffT(),
                r.getGraceJours(), r.getGraceCutOff(), r.getNbPiecesExaminees(), r.getNbLignesExaminees(),
                r.getStatut().name(), r.getNbEcarts(), r.getEcarts(), r.getGenereLe());
    }
}
