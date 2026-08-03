package ci.masante.payment.domain.billing;

/** Ligne après calcul : HT (= quantité×PU − remise), TVA et TTC. Montants en FCFA. */
public record LigneCalculee(
        String libelle,
        int quantite,
        long prixUnitaire,
        long remise,
        int tauxTva,
        long montantHt,
        long montantTva,
        long montantTtc
) {
}
