package ci.masante.payment.domain.wallet;

/** Solde insuffisant pour un débit/transfert (CDC_06 §6). Aucun découvert autorisé. → 409. */
public class SoldeInsuffisantException extends RuntimeException {

    public SoldeInsuffisantException(long solde, long montant) {
        super("Solde insuffisant : disponible " + solde + ", demandé " + montant + ".");
    }
}
