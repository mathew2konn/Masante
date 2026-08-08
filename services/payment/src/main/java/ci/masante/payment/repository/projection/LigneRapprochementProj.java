package ci.masante.payment.repository.projection;

/**
 * Ligne de relevé active confrontée à sa pièce côté facturation (rapprochement B→A, P5.5c). La pièce
 * peut être absente (LEFT JOIN) → {@code pieceStatut}/{@code pieceEtab}/{@code montantCourant} nuls.
 * La règle pure décide REVERSEMENT_SANS_PIECE (orphelin/incohérence) ou MONTANT_REVERSE_DIVERGENT.
 */
public interface LigneRapprochementProj {
    String getReference();

    long getMontantImpute();

    /** Statut COURANT de la pièce, ou {@code null} si introuvable. */
    String getPieceStatut();

    /** Établissement COURANT de la pièce, ou {@code null} si introuvable. */
    String getPieceEtab();

    String getReleveEtab();

    /** Montant COURANT de la pièce (source A), ou {@code null} si introuvable. */
    Long getMontantCourant();
}
