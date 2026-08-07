package ci.masante.payment.service;

/** Carte absente du vault de l'utilisateur (→ 404 mappé en Phase 6). Le contrôle de propriété est implicite. */
public class CarteIntrouvableException extends RuntimeException {

    public CarteIntrouvableException(String reference) {
        super("Carte introuvable : " + reference);
    }
}
