package ci.masante.payment.domain.coverage;

/** Entrée de calcul de prise en charge invalide (taux hors bornes, montant négatif…). */
public class CouvertureInvalideException extends RuntimeException {

    public CouvertureInvalideException(String message) {
        super(message);
    }
}
