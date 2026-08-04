package ci.masante.payment.domain.reward;

/** Entrée invalide pour un cashback (base négative, etc.). → 400. */
public class CashbackInvalideException extends RuntimeException {

    public CashbackInvalideException(String message) {
        super(message);
    }
}
