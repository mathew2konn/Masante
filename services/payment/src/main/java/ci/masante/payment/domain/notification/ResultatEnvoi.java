package ci.masante.payment.domain.notification;

/**
 * Issue d'un envoi. Comme les passerelles de paiement, le résultat est décidé par l'ENVOYEUR (l'adaptateur),
 * jamais par l'appelant. {@code canal} = canal réellement utilisé (SMS/PUSH/…) ; {@code detail} = motif si échec.
 */
public record ResultatEnvoi(boolean reussi, String canal, String detail) {

    public static ResultatEnvoi reussi(String canal) {
        return new ResultatEnvoi(true, canal, null);
    }

    public static ResultatEnvoi echoue(String detail) {
        return new ResultatEnvoi(false, null, detail);
    }
}
