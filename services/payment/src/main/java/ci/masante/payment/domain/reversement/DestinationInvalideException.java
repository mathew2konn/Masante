package ci.masante.payment.domain.reversement;

/** Destination de reversement invalide (format MSISDN / IBAN, type incohérent). */
public class DestinationInvalideException extends RuntimeException {
    public DestinationInvalideException(String message) {
        super(message);
    }
}
