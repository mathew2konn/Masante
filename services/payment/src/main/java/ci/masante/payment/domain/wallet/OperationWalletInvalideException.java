package ci.masante.payment.domain.wallet;

/** Paramètre d'opération wallet invalide (montant ≤ 0, devises différentes, wallet identique…). → 400. */
public class OperationWalletInvalideException extends RuntimeException {

    public OperationWalletInvalideException(String message) {
        super(message);
    }
}
