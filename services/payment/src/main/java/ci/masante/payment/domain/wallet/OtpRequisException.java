package ci.masante.payment.domain.wallet;

/** Une opération au-delà du seuil exige un OTP, absent de la requête (§6.4). */
public class OtpRequisException extends RuntimeException {

    public OtpRequisException(long seuil) {
        super("OTP requis pour toute opération de plus de " + seuil + " FCFA.");
    }
}
