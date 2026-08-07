package ci.masante.payment.service;

/** Aucune transaction carte pour ce paiement (→ 404 mappé en Phase 6). */
public class CarteTransactionIntrouvableException extends RuntimeException {

    public CarteTransactionIntrouvableException(String reference) {
        super("Transaction carte introuvable : " + reference);
    }
}
