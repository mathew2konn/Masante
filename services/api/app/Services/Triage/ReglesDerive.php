<?php

namespace App\Services\Triage;

/**
 * P10c-3-ii lot B (F37/F38) — Le calcul de dérive, en classe PURE.
 *
 * Motif constant du projet (`ReglesReversement`, `ReglesOrientation`, `ReglesCalendrierVaccinal`,
 * `ReglesRapprochement`) : le jugement se calcule ici, sans base, sans horloge, sans configuration
 * implicite — donc il se prouve par des vecteurs plutôt que par une mise en scène.
 *
 * ═══ CE QUE PSI DIT, ET CE QU'IL NE DIT PAS ═══
 *
 * Le *Population Stability Index* compare deux distributions de la même variable : celle sur
 * laquelle le modèle a appris, et celle qu'il rencontre aujourd'hui. Il répond à « la population
 * a-t-elle changé ? » — **jamais à « pourquoi »**, et jamais à « le modèle est-il devenu mauvais ».
 * Un indice élevé est un signal d'enquête, pas un verdict, et c'est pour cela que rien ne se
 * désactive automatiquement (F39).
 *
 * ═══ LE LISSAGE N'EST PAS UN DÉTAIL COSMÉTIQUE ═══
 *
 * PSI divise et prend un logarithme : une classe absente d'un côté donnerait `ln(0)`, c'est-à-dire
 * l'infini. Sans lissage, **une seule catégorie jamais rencontrée en production ferait exploser
 * l'indice** et noierait la vraie dérive sous un chiffre ininterprétable. Le lissage remplace donc
 * un zéro par une part minuscule — ce qui revient à dire « très rare », et non « impossible ».
 *
 * ═══ LES SEUILS SONT DES DONNÉES, ET ILS SONT CONVENTIONNELS ═══
 *
 * 0,1 et 0,25 sont les repères usuels de la littérature, pas une vérité mesurée sur cette
 * population. Ils vivent dans la configuration, se déplacent sans redéploiement, et le jour où
 * l'exploitation aura de quoi les calibrer, c'est la donnée qui changera — pas le code.
 */
final class ReglesDerive
{
    /** Part attribuée à une catégorie absente — « très rare », jamais « impossible ». */
    private const LISSAGE = 0.0001;

    /**
     * L'indice de stabilité entre une distribution de RÉFÉRENCE et une distribution OBSERVÉE.
     *
     * Les deux sont des comptes par catégorie ; les catégories manquantes d'un côté sont traitées
     * comme présentes à hauteur du lissage. Rend `null` quand l'un des deux échantillons est vide :
     * **un indice calculé sur rien n'est pas nul, il n'existe pas** — et l'afficher comme « 0,00 »
     * dirait « aucune dérive » là où la vérité est « aucune mesure ».
     *
     * @param  array<string, int>  $reference
     * @param  array<string, int>  $observee
     */
    public static function psi(array $reference, array $observee): ?float
    {
        $totalRef = array_sum($reference);
        $totalObs = array_sum($observee);

        if ($totalRef === 0 || $totalObs === 0) {
            return null;
        }

        $categories = array_unique([...array_keys($reference), ...array_keys($observee)]);
        $indice = 0.0;

        foreach ($categories as $categorie) {
            $partRef = max(($reference[$categorie] ?? 0) / $totalRef, self::LISSAGE);
            $partObs = max(($observee[$categorie] ?? 0) / $totalObs, self::LISSAGE);

            $indice += ($partObs - $partRef) * log($partObs / $partRef);
        }

        return round($indice, 4);
    }

    /**
     * Le niveau que ce projet retient : `stable`, `leger`, `fort`.
     *
     * Trois niveaux et non deux, parce que « ça bouge un peu » et « ça a changé » n'appellent pas
     * la même conduite : le premier se surveille, le second se regarde tout de suite.
     *
     * @param  array{leger: float, fort: float}  $seuils
     */
    public static function niveau(?float $psi, array $seuils): ?string
    {
        if ($psi === null) {
            return null;
        }

        return match (true) {
            $psi >= $seuils['fort'] => 'fort',
            $psi >= $seuils['leger'] => 'leger',
            default => 'stable',
        };
    }

    /**
     * La dérive de PERFORMANCE : l'écart entre le rappel mesuré au test et celui observé en
     * production, sur la classe `sous_triage`.
     *
     * ═══ POURQUOI CET ÉCART SE LIT DANS UN SEUL SENS ═══
     *
     * Un rappel qui MONTE en production n'est pas une dérive à signaler : c'est une bonne
     * nouvelle, ou un hasard d'échantillon. Ce qui doit alerter, c'est la CHUTE — le modèle
     * laisse passer des sous-triages qu'il attrapait au test. Signaler symétriquement noierait le
     * seul cas dangereux sous des alertes sans conséquence.
     *
     * Rend `null` si l'une des deux mesures manque : on ne compare pas à ce qu'on n'a pas.
     */
    public static function chuteDeRappel(?float $auTest, ?float $enProduction): ?float
    {
        if ($auTest === null || $enProduction === null) {
            return null;
        }

        return round(max(0.0, $auTest - $enProduction), 4);
    }
}
