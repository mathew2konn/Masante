package ci.masante.payment.service;

/** Facture inexistante. → HTTP 404. */
public class FactureIntrouvableException extends RuntimeException {

    public FactureIntrouvableException(String reference) {
        super("Facture introuvable : " + reference);
    }
}
