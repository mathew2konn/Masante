package ci.masante.payment.web.dto;

import ci.masante.payment.domain.model.CampagneCashback;

import java.time.Instant;
import java.util.UUID;

/** Vue d'une campagne de cashback. */
public record CampagneReponse(
        UUID id,
        String code,
        String libelle,
        String typeOperationSource,
        int tauxBps,
        long plafondParOperation,
        long plafondParWallet,
        long plafondParWalletParJour,
        Long budgetTotal,
        Instant dateDebut,
        Instant dateFin,
        boolean actif,
        String creePar,
        Instant createdAt
) {
    public static CampagneReponse de(CampagneCashback c) {
        return new CampagneReponse(c.getId(), c.getCode(), c.getLibelle(), c.getTypeOperationSource(),
                c.getTauxBps(), c.getPlafondParOperation(), c.getPlafondParWallet(),
                c.getPlafondParWalletParJour(), c.getBudgetTotal(), c.getDateDebut(), c.getDateFin(),
                c.isActif(), c.getCreePar(), c.getCreatedAt());
    }
}
