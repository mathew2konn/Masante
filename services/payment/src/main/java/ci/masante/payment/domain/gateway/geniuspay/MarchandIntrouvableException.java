package ci.masante.payment.domain.gateway.geniuspay;

/**
 * Aucun identifiant marchand actif pour cet établissement.
 *
 * <p>Le refus est <b>explicite</b> et non un repli : sous montage A, un établissement sans compte
 * marchand ne peut pas encaisser en ligne. Basculer silencieusement sur un compte de secours ferait
 * arriver l'argent d'un partenaire sur le compte d'un autre.</p>
 */
public class MarchandIntrouvableException extends RuntimeException {

    public MarchandIntrouvableException(String etablissementRef) {
        super("Aucun identifiant marchand GeniusPay actif pour l'établissement " + etablissementRef + ".");
    }
}
