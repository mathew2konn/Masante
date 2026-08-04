package ci.masante.payment.domain.reward;

/** Campagne inexistante, inactive ou hors période. → 409. */
public class CampagneInvalideException extends RuntimeException {

    public CampagneInvalideException(String message) {
        super(message);
    }
}
