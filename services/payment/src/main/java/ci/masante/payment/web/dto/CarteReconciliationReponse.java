package ci.masante.payment.web.dto;

import ci.masante.payment.domain.model.CarteReconciliation;

import java.time.Instant;
import java.time.LocalDate;
import java.util.UUID;

/**
 * Vue d'un rapport de réconciliation carte ↔ PSP (§6.3). Le détail des écarts est transmis tel quel (JSON
 * déjà masqué : références et catégories, aucune donnée sensible).
 */
public record CarteReconciliationReponse(
        UUID id,
        LocalDate dateRapport,
        String psp,
        int nbTransactionsPsp,
        int nbTransactionsLocales,
        long montantPsp,
        long montantLocal,
        int nbEcarts,
        String ecarts,
        Instant genereLe
) {
    public static CarteReconciliationReponse de(CarteReconciliation r) {
        return new CarteReconciliationReponse(r.getId(), r.getDateRapport(), r.getPsp(),
                r.getNbTransactionsPsp(), r.getNbTransactionsLocales(), r.getMontantPsp(),
                r.getMontantLocal(), r.getNbEcarts(), r.getEcarts(), r.getGenereLe());
    }
}
