package ci.masante.payment.domain.statemachine;

import ci.masante.payment.domain.model.PaiementStatut;

/** Levée dès qu'une transition non autorisée par la machine à états est tentée (CDC_06 §4.2). */
public class TransitionInvalideException extends RuntimeException {

    public TransitionInvalideException(PaiementStatut de, PaiementStatut vers) {
        super("Transition interdite : " + de + " → " + vers);
    }
}
