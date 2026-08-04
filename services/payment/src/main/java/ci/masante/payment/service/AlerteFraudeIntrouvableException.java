package ci.masante.payment.service;

/** Alerte de fraude inexistante. */
public class AlerteFraudeIntrouvableException extends RuntimeException {

    public AlerteFraudeIntrouvableException(String id) {
        super("Alerte de fraude introuvable : " + id);
    }
}
