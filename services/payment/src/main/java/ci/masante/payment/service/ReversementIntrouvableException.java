package ci.masante.payment.service;

/** Relevé de reversement introuvable → 404. */
public class ReversementIntrouvableException extends RuntimeException {
    public ReversementIntrouvableException(String id) {
        super("Relevé de reversement introuvable : " + id);
    }
}
