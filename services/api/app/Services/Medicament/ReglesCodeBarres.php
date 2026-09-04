<?php

namespace App\Services\Medicament;

/**
 * B3-c — La forme d'un code-barres GTIN (CDC_11 §7.6), en CLASSE PURE.
 *
 * Motif constant du projet (`ReglesReversement`, `ReglesOrientation`, `ReglesCalendrierVaccinal`,
 * `ReglesDerive`) : le jugement se calcule ici, sans base, sans session, sans configuration
 * implicite — il se prouve donc par des vecteurs plutôt que par une mise en scène.
 *
 * ═══ CE QUE CETTE CLASSE PROUVE, ET CE QU'ELLE NE PROUVE PAS (E5) ═══
 *
 * Un falsificateur RECOPIE un code-barres. `estGtin()` répond à « ce code a-t-il la forme d'un
 * GTIN valide, avec une clé de contrôle cohérente ? » — jamais à « cette boîte est authentique ».
 * Un GTIN parfaitement formé peut n'avoir jamais été attribué à un vrai produit (même limite que
 * `GenerateurCodeMedicament::formeValide()` pour le code national).
 *
 * ═══ LA CLÉ DE CONTRÔLE EST *LA* RÈGLE GS1, PAS PLUS STRICTE QU'ELLE ═══
 *
 * GTIN-8, GTIN-12, GTIN-13 et GTIN-14 partagent le MÊME calcul dès qu'on compte depuis la droite :
 * poids 3 sur le chiffre adjacent à la clé, poids 1 sur le suivant, en alternance. Un garde-fou plus
 * strict que sa propre règle serait un défaut (précédent P6.8c, sur la collation d'un déclencheur).
 */
final class ReglesCodeBarres
{
    /** Les seules longueurs qu'un GTIN peut prendre. */
    private const LONGUEURS_VALIDES = [8, 12, 13, 14];

    /**
     * Une saisie « nettoyée » : un lecteur de code-barres de comptoir tape le code puis un retour
     * chariot (E6), et une saisie manuelle peut introduire des espaces, des espaces insécables
     * (copier-coller depuis un document) ou des tirets de présentation.
     */
    public static function normaliser(string $saisie): string
    {
        return trim(str_replace(["\u{00A0}", ' ', '-', "\t"], '', $saisie));
    }

    /**
     * La saisie a-t-elle la forme d'un GTIN valide : uniquement des chiffres, une longueur admise,
     * et une clé de contrôle cohérente avec le reste des chiffres ?
     */
    public static function estGtin(string $saisie): bool
    {
        $code = self::normaliser($saisie);

        if ($code === '' || preg_match('/^\d+$/', $code) !== 1) {
            return false;
        }

        if (! in_array(strlen($code), self::LONGUEURS_VALIDES, true)) {
            return false;
        }

        $sansCle = substr($code, 0, -1);
        $cleAnnoncee = (int) substr($code, -1);

        return self::cleDeControle($sansCle) === $cleAnnoncee;
    }

    /**
     * La clé de contrôle GS1 (mod 10) pour une suite de chiffres SANS la clé elle-même.
     *
     * Poids 3 sur le chiffre immédiatement à gauche de la clé, poids 1 sur le suivant, en
     * alternance en remontant vers la gauche — d'où l'inversion de la chaîne avant de parcourir.
     */
    public static function cleDeControle(string $chiffresSansCle): int
    {
        $inverse = strrev($chiffresSansCle);
        $somme = 0;

        foreach (str_split($inverse) as $position => $chiffre) {
            $poids = $position % 2 === 0 ? 3 : 1;
            $somme += ((int) $chiffre) * $poids;
        }

        return (10 - ($somme % 10)) % 10;
    }
}
