package ci.masante.payment.service;

/**
 * Le paiement en ligne n'est pas ouvert pour ce montant.
 *
 * <p>Ce n'est <b>pas une erreur</b> : le paiement sur place reste la voie normale, et le message le
 * dit au patient plutôt que de lui opposer un échec technique qu'il ne peut pas comprendre.</p>
 */
public class PaiementEnLigneIndisponibleException extends RuntimeException {

    public PaiementEnLigneIndisponibleException(String message) {
        super(message);
    }
}
