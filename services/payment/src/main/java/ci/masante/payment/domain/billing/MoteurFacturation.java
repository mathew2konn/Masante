package ci.masante.payment.domain.billing;

import ci.masante.payment.domain.coverage.MoteurPriseEnCharge;
import ci.masante.payment.domain.coverage.RequeteCouverture;
import ci.masante.payment.domain.coverage.ResultatCouverture;

import java.math.BigDecimal;
import java.math.RoundingMode;
import java.util.ArrayList;
import java.util.List;

/**
 * Moteur de calcul d'une facture (CDC_06 §7.2/§7.3).
 *
 * <p><b>Frontière</b> : tarification (somme des lignes), TVA, remises, application de la prise en
 * charge et <b>reste à payer</b> sont de la logique métier financière → uniquement ici. Classe pure
 * (sans Spring), testable en unitaire. Le taux de TVA est une <b>donnée</b> par ligne, jamais codé.</p>
 *
 * <pre>
 * ligne HT  = quantité × PU − remise
 * ligne TVA = arrondi(HT × tauxTva%)          (HALF_UP, au FCFA)
 * TTC       = ΣHT + ΣTVA − remise globale
 * couvert   = moteur CNAM/assurance appliqué au TTC (P5.1)
 * reste     = TTC − couvert
 * </pre>
 */
public final class MoteurFacturation {

    private MoteurFacturation() {
    }

    public static ResultatFacturation calculer(EntreeFacturation e) {
        if (e.lignes() == null || e.lignes().isEmpty()) {
            throw new FacturationInvalideException("Une facture doit comporter au moins une ligne.");
        }
        if (e.remiseGlobale() < 0) {
            throw new FacturationInvalideException("La remise globale ne peut pas être négative.");
        }

        List<LigneCalculee> lignes = new ArrayList<>(e.lignes().size());
        long sousTotalHt = 0;
        long totalTva = 0;
        long totalRemisesLignes = 0;

        for (LigneEntree l : e.lignes()) {
            valider(l);
            long base = Math.multiplyExact((long) l.quantite(), l.prixUnitaire());
            if (l.remise() > base) {
                throw new FacturationInvalideException(
                        "La remise d'une ligne ne peut pas dépasser son montant (" + l.libelle() + ").");
            }
            long ht = base - l.remise();
            long tva = pourcentage(ht, l.tauxTva());
            long ttc = ht + tva;

            lignes.add(new LigneCalculee(l.libelle(), l.quantite(), l.prixUnitaire(),
                    l.remise(), l.tauxTva(), ht, tva, ttc));
            sousTotalHt += ht;
            totalTva += tva;
            totalRemisesLignes += l.remise();
        }

        long ttcAvantRemiseGlobale = sousTotalHt + totalTva;
        if (e.remiseGlobale() > ttcAvantRemiseGlobale) {
            throw new FacturationInvalideException("La remise globale dépasse le total de la facture.");
        }
        long montantTtc = ttcAvantRemiseGlobale - e.remiseGlobale();
        long totalRemises = totalRemisesLignes + e.remiseGlobale();

        long montantCouvert = 0;
        long resteAPayer = montantTtc;
        ParametresPriseEnCharge pec = e.priseEnCharge();
        // La couverture ne s'applique que sur un TTC strictement positif (le moteur l'exige).
        if (pec != null && montantTtc > 0) {
            ResultatCouverture rc = MoteurPriseEnCharge.calculer(new RequeteCouverture(
                    montantTtc, pec.type(), pec.tauxCouverture(), pec.plafond(), pec.exclu()));
            montantCouvert = rc.montantCouvert();
            resteAPayer = rc.resteACharge();
        }

        return new ResultatFacturation(
                sousTotalHt, totalRemises, totalTva, montantTtc,
                pec == null ? null : pec.type(),
                pec == null ? null : pec.tauxCouverture(),
                montantCouvert, resteAPayer, lignes);
    }

    private static void valider(LigneEntree l) {
        if (l.libelle() == null || l.libelle().isBlank()) {
            throw new FacturationInvalideException("Chaque ligne doit avoir un libellé.");
        }
        if (l.quantite() <= 0) {
            throw new FacturationInvalideException("La quantité doit être strictement positive (" + l.libelle() + ").");
        }
        if (l.prixUnitaire() < 0) {
            throw new FacturationInvalideException("Le prix unitaire ne peut pas être négatif (" + l.libelle() + ").");
        }
        if (l.remise() < 0) {
            throw new FacturationInvalideException("La remise ne peut pas être négative (" + l.libelle() + ").");
        }
        if (l.tauxTva() < 0 || l.tauxTva() > 100) {
            throw new FacturationInvalideException("Le taux de TVA doit être entre 0 et 100 (" + l.libelle() + ").");
        }
    }

    /** Pourcentage entier arrondi au FCFA (HALF_UP). */
    private static long pourcentage(long montant, int taux) {
        return BigDecimal.valueOf(montant)
                .multiply(BigDecimal.valueOf(taux))
                .divide(BigDecimal.valueOf(100), 0, RoundingMode.HALF_UP)
                .longValueExact();
    }
}
