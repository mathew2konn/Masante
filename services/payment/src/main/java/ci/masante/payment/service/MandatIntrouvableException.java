package ci.masante.payment.service;

/** Levée quand un mandat référencé n'existe pas. Mappée en 404 par le contrôleur. */
public class MandatIntrouvableException extends RuntimeException {

    public MandatIntrouvableException(String ref) {
        super("Mandat introuvable : " + ref);
    }
}
