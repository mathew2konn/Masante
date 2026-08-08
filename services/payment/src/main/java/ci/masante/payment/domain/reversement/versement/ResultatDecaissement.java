package ci.masante.payment.domain.reversement.versement;

/**
 * Issue d'un versement telle que rapportée par la passerelle (jamais par l'appelant). {@code frais} =
 * frais rapportés par la passerelle (DONNÉE, XOF entier) — 0 si aucun. {@code motif} renseigné en cas
 * d'échec.
 */
public record ResultatDecaissement(Issue issue, String referencePasserelle, long frais, String motif) {

    public enum Issue { EXECUTE, ECHOUE }

    public static ResultatDecaissement execute(String referencePasserelle, long frais) {
        return new ResultatDecaissement(Issue.EXECUTE, referencePasserelle, frais, null);
    }

    public static ResultatDecaissement echoue(String referencePasserelle, String motif) {
        return new ResultatDecaissement(Issue.ECHOUE, referencePasserelle, 0L, motif);
    }

    public boolean estExecute() {
        return issue == Issue.EXECUTE;
    }
}
