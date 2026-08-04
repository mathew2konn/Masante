package ci.masante.payment.domain.wallet;

/** PIN wallet absent, mal formé ou incorrect (§6.4). Aucun détail sensible n'est exposé. */
public class PinInvalideException extends RuntimeException {

    public PinInvalideException(String message) {
        super(message);
    }
}
