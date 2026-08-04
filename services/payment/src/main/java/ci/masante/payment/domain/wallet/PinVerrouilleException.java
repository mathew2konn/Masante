package ci.masante.payment.domain.wallet;

import java.time.Instant;

/** PIN wallet verrouillé temporairement après trop d'échecs (§6.4). */
public class PinVerrouilleException extends RuntimeException {

    public PinVerrouilleException(Instant jusqua) {
        super("PIN verrouillé après trop d'échecs. Réessayez après " + jusqua + ".");
    }
}
