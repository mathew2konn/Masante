package ci.masante.payment.domain.wallet;

/**
 * Vérification renforcée (OTP) exigée avant de poursuivre l'opération (palier CHALLENGE de la
 * détection de fraude, §6.4). Message <b>générique</b> : aucun détail de risque ne fuit au client.
 */
public class ChallengeRequisException extends RuntimeException {

    public ChallengeRequisException() {
        super("Vérification renforcée requise : obtenez un OTP puis réessayez.");
    }
}
