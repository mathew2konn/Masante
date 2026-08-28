package ci.masante.payment.domain.gateway.geniuspay;

import java.math.BigDecimal;

/**
 * Le prestataire a renvoyé un montant à décimale non nulle, par exemple {@code 10000.50}.
 *
 * <p>Le XOF n'a pas de sous-unité : ce n'est pas un arrondi à faire, c'est une <b>anomalie
 * bloquante</b>. Arrondir reviendrait à décider qu'un demi-franc n'existe pas alors que le
 * prestataire vient d'affirmer le contraire — et l'écart se retrouverait dans le reçu du partenaire.</p>
 */
public class MontantNonEntierException extends RuntimeException {

    public MontantNonEntierException(BigDecimal valeur) {
        super("Montant à décimale non nulle reçu du prestataire : " + valeur.toPlainString()
              + " — le XOF n'a pas de sous-unité, l'événement n'est pas traité.");
    }
}
