package ci.masante.payment.domain.reward;

/** Un plafond (budget campagne, par wallet, par wallet/jour) est atteint. → 422. */
public class PlafondRecompenseException extends RuntimeException {

    public PlafondRecompenseException(String portee) {
        super("Plafond de cashback atteint : " + portee + ".");
    }
}
