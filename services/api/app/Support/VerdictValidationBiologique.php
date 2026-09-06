<?php

namespace App\Support;

/**
 * Le verdict d'un biologiste sur un résultat en attente (B5-c, L7, CDC_09 §7.4 étape 7).
 *
 * Deux valeurs, jamais un troisième « en attente » : une ligne de `validations_biologiques`
 * n'existe QU'UNE FOIS le verdict rendu (motif M4/M5 du plan) — « en attente » se lit par
 * l'ABSENCE de ligne, pas par une valeur qui la porterait (précédent B3-b/`Commande` : une valeur
 * qui se déduit de l'absence n'est jamais stockée comme valeur possible).
 */
enum VerdictValidationBiologique: string
{
    case VALIDE = 'valide';
    case REJETE = 'rejete';

    public function libelle(): string
    {
        return match ($this) {
            self::VALIDE => 'Validé',
            self::REJETE => 'Rejeté',
        };
    }
}
