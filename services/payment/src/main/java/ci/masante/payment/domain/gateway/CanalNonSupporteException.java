package ci.masante.payment.domain.gateway;

/** Levée quand aucune passerelle enregistrée ne prend en charge le canal demandé. */
public class CanalNonSupporteException extends RuntimeException {

    public CanalNonSupporteException(String canal) {
        super("Aucune passerelle ne prend en charge le canal : " + canal);
    }
}
