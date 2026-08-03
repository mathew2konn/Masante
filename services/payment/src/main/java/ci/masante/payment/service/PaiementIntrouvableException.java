package ci.masante.payment.service;

/** Paiement inexistant. → HTTP 404. */
public class PaiementIntrouvableException extends RuntimeException {

    public PaiementIntrouvableException(String reference) {
        super("Paiement introuvable : " + reference);
    }
}
