package ci.masante.payment.domain.billing;

/** Données de facture invalides (aucune ligne, remise &gt; montant, quantité ≤ 0…). → HTTP 400. */
public class FacturationInvalideException extends RuntimeException {

    public FacturationInvalideException(String message) {
        super(message);
    }
}
