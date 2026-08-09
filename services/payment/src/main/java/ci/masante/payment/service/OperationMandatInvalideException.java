package ci.masante.payment.service;

/** Levée quand une opération de mandat est invalide (validation ou état). Mappée en 409/422 par le contrôleur. */
public class OperationMandatInvalideException extends RuntimeException {

    public OperationMandatInvalideException(String message) {
        super(message);
    }
}
