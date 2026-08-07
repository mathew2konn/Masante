package ci.masante.payment.domain.reversement;

import ci.masante.payment.domain.model.TypeLigneReversement;

import java.time.Instant;
import java.util.UUID;

/**
 * Ligne calculée du relevé (snapshot pièce par pièce). Résultat PUR : le service la persiste telle
 * quelle. {@code montantNetLigne = montantRegleImpute − montantCommissionLigne − montantRembourseImpute}.
 */
public record LigneCalculeeReversement(
        TypeLigneReversement type,
        UUID factureId,
        UUID remboursementId,
        String pieceReference,
        Instant pieceDateeA,
        long montantRegleImpute,
        long montantCommissionLigne,
        long montantRembourseImpute,
        long montantNetLigne) {

    public static LigneCalculeeReversement facture(UUID factureId, String numero, Instant soldeeA,
                                                   long montantRegle, long commission) {
        return new LigneCalculeeReversement(TypeLigneReversement.FACTURE, factureId, null, numero, soldeeA,
                montantRegle, commission, 0L, montantRegle - commission);
    }

    public static LigneCalculeeReversement remboursement(UUID remboursementId, String reference,
                                                         Instant creeLe, long montant) {
        return new LigneCalculeeReversement(TypeLigneReversement.REMBOURSEMENT, null, remboursementId, reference,
                creeLe, 0L, 0L, montant, -montant);
    }
}
