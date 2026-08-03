package ci.masante.payment.domain.coverage;

/**
 * Entrée du calcul de prise en charge (CDC_06 §8).
 *
 * @param montantTotal montant de l'acte, en FCFA (entier, &gt; 0)
 * @param type         nature du tiers payant
 * @param tauxCouverture pourcentage pris en charge (0 à 100)
 * @param plafond      plafond de couverture en FCFA (null = pas de plafond)
 * @param exclu        acte exclu du contrat (couverture nulle si vrai)
 */
public record RequeteCouverture(
        long montantTotal,
        TypePriseEnCharge type,
        int tauxCouverture,
        Long plafond,
        boolean exclu
) {
}
