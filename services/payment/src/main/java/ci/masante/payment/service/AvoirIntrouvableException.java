package ci.masante.payment.service;

/** Avoir inexistant. → HTTP 404. */
public class AvoirIntrouvableException extends RuntimeException {

    public AvoirIntrouvableException(String reference) {
        super("Avoir introuvable : " + reference);
    }
}
