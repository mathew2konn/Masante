package ci.masante.payment.service;

/** Portefeuille inexistant. → HTTP 404. */
public class WalletIntrouvableException extends RuntimeException {

    public WalletIntrouvableException(String reference) {
        super("Portefeuille introuvable : " + reference);
    }
}
