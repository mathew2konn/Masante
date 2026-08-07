package ci.masante.payment.domain.carte;

/** Aucun adaptateur carte ne déclare ce PSP. → HTTP 400. */
public class PasserelleInconnue extends RuntimeException {

    public PasserelleInconnue(String psp) {
        super("Passerelle carte inconnue : " + psp);
    }
}
