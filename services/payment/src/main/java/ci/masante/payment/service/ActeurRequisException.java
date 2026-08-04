package ci.masante.payment.service;

/** Acte de création monétaire sans acteur identifié (bonus, gestion de campagne). → 401. */
public class ActeurRequisException extends RuntimeException {

    public ActeurRequisException() {
        super("Acteur requis : l'identité de l'auteur doit être fournie par la passerelle authentifiée.");
    }
}
