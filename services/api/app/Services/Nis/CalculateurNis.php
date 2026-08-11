<?php

namespace App\Services\Nis;

/**
 * Calcul et vérification de la clé de contrôle du NIS — ISO 7064 MOD 97-10 (CDC_09 §3.3/§3.4).
 *
 * AUTORITÉ : cette implémentation fait foi. Le jumeau TypeScript (`@masante/shared/nis`) ne sert
 * qu'au confort de saisie (CDC_09 §3.4 impose la double validation client + serveur). Les deux
 * consomment le MÊME fichier de vecteurs `packages/shared/src/nis/vecteurs.json` : toute
 * divergence casse la suite de tests (ADR-021 §5).
 *
 * Classe PURE : aucune dépendance à Eloquent, à la base ni au framework. Testable isolément
 * (CDC_03 §1 — le code métier ne dépend jamais de Laravel).
 *
 * Format : PPP + AA + CCCCCCCC + KK (15 caractères)
 *   PPP       préfixe pays alphabétique (CIS = Côte d'Ivoire Santé)
 *   AA        année sur 2 chiffres
 *   CCCCCCCC  compteur national sur 8 chiffres
 *   KK        clé de contrôle, domaine 02..98
 *
 * Propriétés prouvées (voir vecteurs.json) : 100 % des erreurs d'un chiffre, 100 % des
 * transpositions de chiffres voisins, 100 % des erreurs portant sur la clé elle-même.
 */
final class CalculateurNis
{
    public const LONGUEUR = 15;

    public const PREFIXE_CI = 'CIS';

    public const MOTIF_LONGUEUR = 'LONGUEUR_INVALIDE';

    public const MOTIF_FORMAT = 'FORMAT_INVALIDE';

    public const MOTIF_CLE = 'CLE_INVALIDE';

    /**
     * Calcule la clé de contrôle.
     *
     * Les lettres du préfixe sont converties A=10 … Z=35 : deux pays partageant le même couple
     * année + compteur obtiennent des clés DIFFÉRENTES (exigence multi-pays, CDC_09 §1.2).
     *
     * Le nombre formé dépasse largement PHP_INT_MAX : le modulo est donc appliqué chiffre par
     * chiffre, ce qui est mathématiquement équivalent à un modulo sur l'entier complet et
     * n'exige aucune extension (ni bcmath, ni gmp — §2.6 : aucune dépendance nouvelle).
     */
    public function calculerCle(string $prefixe, string $annee, string $compteur): string
    {
        $converti = '';
        foreach (str_split(strtoupper($prefixe)) as $caractere) {
            $converti .= ctype_alpha($caractere)
                ? (string) (ord($caractere) - 55)
                : $caractere;
        }

        $reste = 0;
        foreach (str_split($converti.$annee.$compteur) as $chiffre) {
            $reste = ($reste * 10 + (int) $chiffre) % 97;
        }

        return str_pad((string) (98 - $reste), 2, '0', STR_PAD_LEFT);
    }

    /**
     * Assemble un NIS complet à partir de ses segments (la clé est calculée, jamais fournie).
     */
    public function composer(string $prefixe, int $annee, int $compteur): string
    {
        $aa = str_pad((string) $annee, 2, '0', STR_PAD_LEFT);
        $cc = str_pad((string) $compteur, 8, '0', STR_PAD_LEFT);

        return strtoupper($prefixe).$aa.$cc.$this->calculerCle($prefixe, $aa, $cc);
    }

    /**
     * Vérifie qu'un NIS est bien formé et que sa clé est correcte.
     *
     * Ne dit RIEN de son existence en base : c'est volontaire (anti-énumération — un endpoint
     * qui confirmerait l'existence serait un oracle permettant de balayer la population).
     *
     * @return array{valide: bool, motif?: string, segments?: array{prefixe: string, annee: string, compteur: string, cle: string}}
     */
    public function verifier(string $valeur): array
    {
        $v = strtoupper(trim($valeur));

        if (mb_strlen($v) !== self::LONGUEUR) {
            return ['valide' => false, 'motif' => self::MOTIF_LONGUEUR];
        }

        if (preg_match('/^[A-Z]{3}\d{12}$/', $v) !== 1) {
            return ['valide' => false, 'motif' => self::MOTIF_FORMAT];
        }

        $segments = [
            'prefixe'  => substr($v, 0, 3),
            'annee'    => substr($v, 3, 2),
            'compteur' => substr($v, 5, 8),
            'cle'      => substr($v, 13, 2),
        ];

        $attendue = $this->calculerCle($segments['prefixe'], $segments['annee'], $segments['compteur']);

        if (! hash_equals($attendue, $segments['cle'])) {
            return ['valide' => false, 'motif' => self::MOTIF_CLE];
        }

        return ['valide' => true, 'segments' => $segments];
    }

    /** Raccourci booléen. */
    public function estValide(string $valeur): bool
    {
        return $this->verifier($valeur)['valide'];
    }
}
