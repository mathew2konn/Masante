package ci.masante.payment.domain.mandat;

/** Levée quand une {@link ActionMandat} n'est pas applicable à un {@link MandatStatut} donné. */
public class TransitionMandatIllegale extends RuntimeException {

    public TransitionMandatIllegale(MandatStatut statut, ActionMandat action) {
        super("Action " + action + " illégale depuis l'état " + statut);
    }
}
