package ci.masante.payment.domain.wallet;

/** Une limite de montant (opération/jour/mois, §6.4) est dépassée. */
public class LimiteDepasseeException extends RuntimeException {

    public LimiteDepasseeException(String portee, long tentative, long plafond) {
        super("Limite " + portee + " dépassée : " + tentative + " > plafond " + plafond + " FCFA.");
    }
}
