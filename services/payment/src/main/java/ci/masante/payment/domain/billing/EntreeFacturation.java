package ci.masante.payment.domain.billing;

import java.util.List;

/**
 * Entrée du calcul d'une facture (CDC_06 §7). {@code priseEnCharge} est optionnelle (null = aucune) ;
 * appliquée sur le TTC via le moteur CNAM/assurance déjà livré (P5.1).
 */
public record EntreeFacturation(
        String etablissementRef,
        String patientRef,
        Integer exercice,
        String devise,
        List<LigneEntree> lignes,
        long remiseGlobale,
        ParametresPriseEnCharge priseEnCharge
) {
}
