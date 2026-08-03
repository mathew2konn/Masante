package ci.masante.payment.domain.coverage;

import java.math.BigDecimal;
import java.math.RoundingMode;

/**
 * Moteur de calcul de la prise en charge CNAM / assurance (CDC_06 §8).
 *
 * <p><b>Frontière (CDC_01 §0.1)</b> : ce calcul — couverture, ticket modérateur, reste à charge —
 * est de la logique métier financière ; il vit <b>uniquement</b> ici (backend). Aucun front ne le
 * reproduit. Classe pure (sans Spring) → testable en unitaire sur les vecteurs imposés :</p>
 * <ul>
 *   <li>consultation 20 000 @ 70 % → CNAM 14 000, patient 6 000 (§8.1)</li>
 *   <li>hospitalisation 250 000 @ 80 % → assurance 200 000, patient 50 000 (§8.2)</li>
 * </ul>
 */
public final class MoteurPriseEnCharge {

    private MoteurPriseEnCharge() {
    }

    public static ResultatCouverture calculer(RequeteCouverture r) {
        valider(r);

        if (r.exclu()) {
            // Acte exclu du contrat : rien n'est couvert, le patient paie tout (§8.2 exclusions).
            return new ResultatCouverture(
                    r.montantTotal(), r.type(), 0, 0L, r.montantTotal(), r.montantTotal(), false, true);
        }

        long brut = BigDecimal.valueOf(r.montantTotal())
                .multiply(BigDecimal.valueOf(r.tauxCouverture()))
                .divide(BigDecimal.valueOf(100), 0, RoundingMode.HALF_UP)
                .longValueExact();

        boolean plafondApplique = r.plafond() != null && brut > r.plafond();
        long montantCouvert = plafondApplique ? r.plafond() : brut;

        long resteACharge = r.montantTotal() - montantCouvert;

        return new ResultatCouverture(
                r.montantTotal(),
                r.type(),
                r.tauxCouverture(),
                montantCouvert,
                resteACharge,   // ticket modérateur = reste à charge (pas de franchise en P5.1)
                resteACharge,
                plafondApplique,
                false);
    }

    private static void valider(RequeteCouverture r) {
        if (r.montantTotal() <= 0) {
            throw new CouvertureInvalideException("Le montant total doit être strictement positif.");
        }
        if (r.tauxCouverture() < 0 || r.tauxCouverture() > 100) {
            throw new CouvertureInvalideException("Le taux de couverture doit être compris entre 0 et 100.");
        }
        if (r.plafond() != null && r.plafond() < 0) {
            throw new CouvertureInvalideException("Le plafond ne peut pas être négatif.");
        }
        if (r.type() == null) {
            throw new CouvertureInvalideException("Le type de prise en charge est obligatoire.");
        }
    }
}
