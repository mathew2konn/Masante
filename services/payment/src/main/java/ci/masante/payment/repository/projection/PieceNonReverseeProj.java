package ci.masante.payment.repository.projection;

import java.time.Instant;

/**
 * Pièce due (facture PAYEE ou remboursement REUSSI) imputable mais absente de tout relevé actif —
 * candidate à l'écart PIECE_NON_REVERSEE (rapprochement A→B, P5.5c). La règle pure re-tranche selon le
 * délai de grâce et le montant.
 */
public interface PieceNonReverseeProj {
    String getReference();

    String getTypePiece();

    Instant getDateeA();

    long getMontant();
}
