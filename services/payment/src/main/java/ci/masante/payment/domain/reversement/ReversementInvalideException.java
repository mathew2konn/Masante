package ci.masante.payment.domain.reversement;

/** Entrée de calcul de reversement invalide (taux hors bornes, report positif, montant négatif). */
public class ReversementInvalideException extends RuntimeException {
    public ReversementInvalideException(String message) {
        super(message);
    }
}
