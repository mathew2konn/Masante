package ci.masante.payment.domain.carte;

/** Résultat d'une capture auprès de la passerelle. {@code codeRefus} renseigné si {@code !reussi}. */
public record ResultatCapture(boolean reussi, String codeRefus) {

    public static ResultatCapture ok() {
        return new ResultatCapture(true, null);
    }
}
