package ci.masante.payment.web.dto;

import ci.masante.payment.domain.model.LigneReversement;

import java.time.Instant;
import java.util.UUID;

/** Ligne d'un relevé de reversement (snapshot d'une pièce imputée). */
public record LigneReversementReponse(UUID id, String type, UUID factureId, UUID remboursementId,
                                      String pieceReference, Instant pieceDateeA, long montantRegleImpute,
                                      long montantCommissionLigne, long montantRembourseImpute,
                                      long montantNetLigne, boolean actif) {

    public static LigneReversementReponse de(LigneReversement l) {
        return new LigneReversementReponse(l.getId(), l.getTypeLigne().name(), l.getFactureId(),
                l.getRemboursementId(), l.getPieceReference(), l.getPieceDateeA(), l.getMontantRegleImpute(),
                l.getMontantCommissionLigne(), l.getMontantRembourseImpute(), l.getMontantNetLigne(),
                l.isReleveActif());
    }
}
