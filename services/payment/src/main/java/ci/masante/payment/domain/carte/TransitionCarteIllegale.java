package ci.masante.payment.domain.carte;

/** Transition carte non autorisée par {@link MachineEtatsCarte}. → HTTP 409. */
public class TransitionCarteIllegale extends RuntimeException {

    public TransitionCarteIllegale(StatutCarte actuel, EvenementCarte evenement) {
        super("Transition carte illégale : " + actuel + " × " + evenement);
    }
}
