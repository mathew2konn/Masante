package ci.masante.payment.domain.wallet;

/** OTP incorrect, expiré ou déjà consommé (§6.4). */
public class OtpInvalideException extends RuntimeException {

    public OtpInvalideException(String message) {
        super(message);
    }
}
