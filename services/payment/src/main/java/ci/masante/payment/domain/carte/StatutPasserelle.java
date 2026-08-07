package ci.masante.payment.domain.carte;

/**
 * Statut AUTORITATIF d'une transaction lu auprès de la passerelle ({@code recupererStatut}) — la vérité,
 * jamais le client (décision §1.2). Porte les métadonnées non sensibles quand la transaction se résout
 * (NTID/marque/last4/expiration), ou un {@code codeRefus} en cas de refus. Aucune donnée PAN/CVV.
 */
public record StatutPasserelle(IssuePasserelle issue, String ntid, String marque, String last4,
                               Integer expMois, Integer expAnnee, String codeRefus) {

    public static StatutPasserelle enAttente() {
        return new StatutPasserelle(IssuePasserelle.EN_ATTENTE, null, null, null, null, null, null);
    }
}
