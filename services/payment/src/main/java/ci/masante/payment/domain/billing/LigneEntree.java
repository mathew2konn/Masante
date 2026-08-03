package ci.masante.payment.domain.billing;

/**
 * Ligne de facture en entrée (fournie par le portail établissement). Montants en FCFA.
 * Le taux de TVA est une DONNÉE (jamais codée en dur — interdit CDC_00 §4).
 */
public record LigneEntree(
        String libelle,
        int quantite,
        long prixUnitaire,
        long remise,
        int tauxTva
) {
}
