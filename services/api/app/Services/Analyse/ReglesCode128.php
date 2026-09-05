<?php

namespace App\Services\Analyse;

/**
 * B5-b — L'étiquette du prélèvement : Code 128 en SVG PUR, ZÉRO DÉPENDANCE (L16).
 *
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 * POURQUOI CODE 128 ET NON UN QR, ET POURQUOI UNE CLASSE PURE (motif `ReglesCodeBarres`/mod-97 NIS)
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 *
 * §7.4 étape 3 dit « code-barres OU QR Code » : le corpus laisse le choix, les deux branches n'ont
 * pas le même coût. **Vérifié** : aucune bibliothèque de code-barres ni de QR dans ce dépôt (ni
 * `composer.json` ni `vendor/`). Un QR exige un encodage Reed-Solomon qu'on n'écrit pas à la main
 * — donc une DÉPENDANCE (§2.6, accord écrit requis). Code 128 s'écrit en quelques dizaines de
 * lignes : un alphabet de largeurs et une clé de contrôle **modulo 103**, tous deux publics et
 * déterministes — donc prouvables par vecteurs, comme le mod-97 du NIS (P6.1) ou `ReglesCodeBarres`
 * (B3-c). C'est aussi le choix juste métier : une étiquette de tube est un code-barres linéaire
 * dans tous les laboratoires réels, un QR sur un tube de 13 mm se lit mal.
 *
 * ═══ CE QUI EST PROUVABLE PAR CE PROJET, ET CE QUI NE L'EST PAS ═══
 *
 * La clé de contrôle (modulo 103) est un CALCUL, vérifié par des vecteurs qui la recalculent à la
 * main. La TABLE DES MOTIFS (largeurs de barres) est une donnée EXTERNE, publique et normalisée
 * (ISO/IEC 15417, Code Set B) : elle ne se déduit d'aucune formule, elle se reprend d'une table de
 * référence. Ce projet vérifie ce qu'il PEUT vérifier seul — chaque motif somme exactement au
 * nombre de modules qu'un symbole Code 128 doit occuper (11, ou 13 pour l'arrêt), et aucun motif
 * n'est dupliqué (un décodeur ne pourrait pas trancher) — mais **la lecture par un scanner matériel
 * réel n'a pas été éprouvée** : c'est un test du monde physique, pas du code, annoncé comme
 * limite plutôt que déguisé en garantie.
 *
 * ═══ LE CODE SET B, ET POURQUOI IL SUFFIT ICI ═══
 *
 * Code Set B couvre l'ASCII imprimable 32-126 (`valeur = ord(caractère) - 32`). L'identifiant du
 * prélèvement ({@see GenerateurIdentifiantPrelevement}) n'emploie que majuscules, chiffres et un
 * tiret — tous dans cette plage. Aucun besoin de Code Set A ou C, ni des caractères spéciaux
 * FNC1-4.
 */
final class ReglesCode128
{
    private const VALEUR_DEBUT_B = 104;

    private const VALEUR_ARRET = 106;

    private const DECALAGE_ASCII = 32;

    private const ASCII_MIN = 32;

    private const ASCII_MAX = 126;

    /**
     * Les largeurs de barres/espaces pour chaque valeur 0-105 (six éléments : barre, espace,
     * barre, espace, barre, espace — chacun toujours dans cette table interne) puis 106 (l'ARRÊT,
     * sept éléments : le dernier est une barre pleine qui clôt le symbole). Table du standard
     * ISO/IEC 15417, Code Set B — reprise ici car aucune dépendance ne peut la fournir (L16).
     *
     * @var array<int, array<int, int>>
     */
    private const MOTIFS = [
        0 => [2, 1, 2, 2, 2, 2], 1 => [2, 2, 2, 1, 2, 2], 2 => [2, 2, 2, 2, 2, 1],
        3 => [1, 2, 1, 2, 2, 3], 4 => [1, 2, 1, 3, 2, 2], 5 => [1, 3, 1, 2, 2, 2],
        6 => [1, 2, 2, 2, 1, 3], 7 => [1, 2, 2, 3, 1, 2], 8 => [1, 3, 2, 2, 1, 2],
        9 => [2, 2, 1, 2, 1, 3], 10 => [2, 2, 1, 3, 1, 2], 11 => [2, 3, 1, 2, 1, 2],
        12 => [1, 1, 2, 2, 3, 2], 13 => [1, 2, 2, 1, 3, 2], 14 => [1, 2, 2, 2, 3, 1],
        15 => [1, 1, 3, 2, 2, 2], 16 => [1, 2, 3, 1, 2, 2], 17 => [1, 2, 3, 2, 2, 1],
        18 => [2, 2, 3, 2, 1, 1], 19 => [2, 2, 1, 1, 3, 2], 20 => [2, 2, 1, 2, 3, 1],
        21 => [2, 1, 3, 2, 1, 2], 22 => [2, 2, 3, 1, 1, 2], 23 => [3, 1, 2, 1, 3, 1],
        24 => [3, 1, 1, 2, 2, 2], 25 => [3, 2, 1, 1, 2, 2], 26 => [3, 2, 1, 2, 2, 1],
        27 => [3, 1, 2, 2, 1, 2], 28 => [3, 2, 2, 1, 1, 2], 29 => [3, 2, 2, 2, 1, 1],
        30 => [2, 1, 2, 1, 2, 3], 31 => [2, 1, 2, 3, 2, 1], 32 => [2, 3, 2, 1, 2, 1],
        33 => [1, 1, 1, 3, 2, 3], 34 => [1, 3, 1, 1, 2, 3], 35 => [1, 3, 1, 3, 2, 1],
        36 => [1, 1, 2, 3, 1, 3], 37 => [1, 3, 2, 1, 1, 3], 38 => [1, 3, 2, 3, 1, 1],
        39 => [2, 1, 1, 3, 1, 3], 40 => [2, 3, 1, 1, 1, 3], 41 => [2, 3, 1, 3, 1, 1],
        42 => [1, 1, 2, 1, 3, 3], 43 => [1, 1, 2, 3, 3, 1], 44 => [1, 3, 2, 1, 3, 1],
        45 => [1, 1, 3, 1, 2, 3], 46 => [1, 1, 3, 3, 2, 1], 47 => [1, 3, 3, 1, 2, 1],
        48 => [3, 1, 3, 1, 2, 1], 49 => [2, 1, 1, 3, 3, 1], 50 => [2, 3, 1, 1, 3, 1],
        51 => [2, 1, 3, 1, 1, 3], 52 => [2, 1, 3, 3, 1, 1], 53 => [2, 1, 3, 1, 3, 1],
        54 => [3, 1, 1, 1, 2, 3], 55 => [3, 1, 1, 3, 2, 1], 56 => [3, 3, 1, 1, 2, 1],
        57 => [3, 1, 2, 1, 1, 3], 58 => [3, 1, 2, 3, 1, 1], 59 => [3, 3, 2, 1, 1, 1],
        60 => [3, 1, 4, 1, 1, 1], 61 => [2, 2, 1, 4, 1, 1], 62 => [4, 3, 1, 1, 1, 1],
        63 => [1, 1, 1, 2, 2, 4], 64 => [1, 1, 1, 4, 2, 2], 65 => [1, 2, 1, 1, 2, 4],
        66 => [1, 2, 1, 4, 2, 1], 67 => [1, 4, 1, 1, 2, 2], 68 => [1, 4, 1, 2, 2, 1],
        69 => [1, 1, 2, 2, 1, 4], 70 => [1, 1, 2, 4, 1, 2], 71 => [1, 2, 2, 1, 1, 4],
        72 => [1, 2, 2, 4, 1, 1], 73 => [1, 4, 2, 1, 1, 2], 74 => [1, 4, 2, 2, 1, 1],
        75 => [2, 4, 1, 2, 1, 1], 76 => [2, 2, 1, 1, 1, 4], 77 => [4, 1, 3, 1, 1, 1],
        78 => [2, 4, 1, 1, 1, 2], 79 => [1, 3, 4, 1, 1, 1], 80 => [1, 1, 1, 2, 4, 2],
        81 => [1, 2, 1, 1, 4, 2], 82 => [1, 2, 1, 2, 4, 1], 83 => [1, 1, 4, 2, 1, 2],
        84 => [1, 2, 4, 1, 1, 2], 85 => [1, 2, 4, 2, 1, 1], 86 => [4, 1, 1, 2, 1, 2],
        87 => [4, 2, 1, 1, 1, 2], 88 => [4, 2, 1, 2, 1, 1], 89 => [2, 1, 2, 1, 4, 1],
        90 => [2, 1, 4, 1, 2, 1], 91 => [4, 1, 2, 1, 2, 1], 92 => [1, 1, 1, 1, 4, 3],
        93 => [1, 1, 1, 3, 4, 1], 94 => [1, 3, 1, 1, 4, 1], 95 => [1, 1, 4, 1, 1, 3],
        96 => [1, 1, 4, 3, 1, 1], 97 => [4, 1, 1, 1, 1, 3], 98 => [4, 1, 1, 3, 1, 1],
        99 => [1, 1, 3, 1, 4, 1], 100 => [1, 1, 4, 1, 3, 1], 101 => [3, 1, 1, 1, 4, 1],
        102 => [4, 1, 1, 1, 3, 1], 103 => [2, 1, 1, 4, 1, 2], 104 => [2, 1, 1, 2, 1, 4],
        105 => [2, 1, 1, 2, 3, 2], 106 => [2, 3, 3, 1, 1, 1, 2],
    ];

    /**
     * Les valeurs Code Set B d'un identifiant, START B en tête. Chaque caractère doit être un
     * ASCII imprimable 32-126 — l'identifiant de {@see GenerateurIdentifiantPrelevement} l'est
     * toujours, mais la garde est là pour ne jamais produire un symbole silencieusement faux.
     *
     * @return array<int, int>
     */
    public static function valeurs(string $identifiant): array
    {
        if ($identifiant === '') {
            throw new \InvalidArgumentException('Un identifiant vide ne peut pas être encodé.');
        }

        $valeurs = [self::VALEUR_DEBUT_B];

        foreach (str_split($identifiant) as $caractere) {
            $ord = ord($caractere);

            if ($ord < self::ASCII_MIN || $ord > self::ASCII_MAX) {
                throw new \InvalidArgumentException(
                    "Caractère hors Code Set B : « {$caractere} »."
                );
            }

            $valeurs[] = $ord - self::DECALAGE_ASCII;
        }

        return $valeurs;
    }

    /**
     * La clé de contrôle modulo 103 : `(valeur de START + Σ(position × valeur)) mod 103`, la
     * position du premier caractère de données valant 1. C'est *la* règle Code 128, pas plus
     * stricte qu'elle (précédent P6.8c sur la collation d'un déclencheur).
     *
     * @param  array<int, int>  $valeurs  telles que rendues par {@see valeurs()} (START B inclus)
     */
    public static function cleDeControle(array $valeurs): int
    {
        $somme = $valeurs[0];

        foreach (array_slice($valeurs, 1) as $position => $valeur) {
            $somme += ($position + 1) * $valeur;
        }

        return $somme % 103;
    }

    /**
     * La séquence complète de valeurs à dessiner : START B, les données, la clé, l'ARRÊT.
     *
     * @return array<int, int>
     */
    public static function sequenceComplete(string $identifiant): array
    {
        $valeurs = self::valeurs($identifiant);

        return [...$valeurs, self::cleDeControle($valeurs), self::VALEUR_ARRET];
    }

    /** Le motif (largeurs) d'une valeur de symbole. */
    public static function motif(int $valeur): array
    {
        if (! isset(self::MOTIFS[$valeur])) {
            throw new \InvalidArgumentException("Valeur de symbole inconnue : {$valeur}.");
        }

        return self::MOTIFS[$valeur];
    }

    /**
     * Le SVG imprimable de l'étiquette (L16). Barre = noir, espace = blanc, en modules de largeur
     * fixe — aucune dépendance de rendu, juste des rectangles.
     */
    public static function svg(string $identifiant, int $largeurModule = 2, int $hauteur = 60): string
    {
        $sequence = self::sequenceComplete($identifiant);
        $margeModules = 10; // zone de silence — sans elle un scanner ne trouve pas le début du code

        $x = $margeModules * $largeurModule;
        $barres = [];

        foreach ($sequence as $valeur) {
            foreach (self::motif($valeur) as $position => $largeur) {
                $estBarre = $position % 2 === 0;
                $largeurPx = $largeur * $largeurModule;

                if ($estBarre) {
                    $barres[] = sprintf(
                        '<rect x="%d" y="0" width="%d" height="%d" fill="#000"/>',
                        $x,
                        $largeurPx,
                        $hauteur,
                    );
                }

                $x += $largeurPx;
            }
        }

        $largeurTotale = $x + $margeModules * $largeurModule;

        return sprintf(
            '<svg xmlns="http://www.w3.org/2000/svg" width="%d" height="%d" '
                .'viewBox="0 0 %d %d">%s</svg>',
            $largeurTotale,
            $hauteur,
            $largeurTotale,
            $hauteur,
            implode('', $barres),
        );
    }
}
