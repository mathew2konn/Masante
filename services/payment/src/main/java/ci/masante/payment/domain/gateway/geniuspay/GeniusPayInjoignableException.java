package ci.masante.payment.domain.gateway.geniuspay;

/**
 * Le prestataire n'a pas répondu : délai dépassé, connexion refusée, coupure.
 *
 * <p>C'est la seule exception qui débouche sur {@code INITIEE_INCERTAINE}, et c'est <b>tout son
 * intérêt</b> : elle dit « nous ne savons pas », là où {@link GeniusPayException} dit « le
 * prestataire a refusé ». Confondre les deux ferait soit rejouer un appel peut-être déjà passé, soit
 * abandonner une transaction peut-être créée.</p>
 */
public class GeniusPayInjoignableException extends RuntimeException {

    public GeniusPayInjoignableException(String message, Throwable cause) {
        super(message, cause);
    }
}
