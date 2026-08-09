package ci.masante.payment.domain.carte;

/**
 * Issue SCELLÉE d'un débit récurrent MIT (CDC_06 §5.4). Comme pour le 3DS (§1.2), le résultat est décidé
 * par la PASSERELLE, jamais par l'appelant. {@code refPasserelle} identifie l'opération côté PSP.
 *
 * @param reussi        true si le débit MIT est autorisé + capturé par la passerelle.
 * @param refPasserelle référence de l'opération côté PSP (jamais nulle).
 * @param codeRefus     code de refus technique (null si {@code reussi}).
 */
public record ResultatDebitRecurrent(boolean reussi, String refPasserelle, String codeRefus) {

    public static ResultatDebitRecurrent reussi(String refPasserelle) {
        return new ResultatDebitRecurrent(true, refPasserelle, null);
    }

    public static ResultatDebitRecurrent refuse(String refPasserelle, String codeRefus) {
        return new ResultatDebitRecurrent(false, refPasserelle, codeRefus);
    }
}
