package ci.masante.payment.domain.billing;

import ci.masante.payment.domain.coverage.TypePriseEnCharge;

/**
 * Paramètres de prise en charge d'une facture (sans montant : il vaut le TTC calculé). Le moteur de
 * facturation les combine avec le TTC pour appeler le moteur CNAM/assurance (P5.1).
 */
public record ParametresPriseEnCharge(
        TypePriseEnCharge type,
        int tauxCouverture,
        Long plafond,
        boolean exclu
) {
}
