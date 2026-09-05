<?php

namespace App\Support;

/**
 * États d'une commande de médicaments — miroir PHP de `CommandeStatut` (`@masante/shared`), B3-d.
 *
 * Le §9.5 est littéral : « le pharmacien valide » AVANT que la vente soit autorisée — l'état
 * initial n'est donc pas `ACCEPTEE`. Un seul état terminal de succès (`REMISE`) : le mode
 * (retrait ou livraison) est déjà porté par la commande, une valeur dérivable n'est jamais
 * stockée (principe tenu depuis P5.3a).
 */
enum StatutCommande: string
{
    /** Créée par le patient, l'officine ne l'a pas encore vue. */
    case EN_ATTENTE = 'en_attente';

    /** L'officine s'engage à préparer. */
    case ACCEPTEE = 'acceptee';

    /** Refusée par l'officine — motif obligatoire. */
    case REFUSEE = 'refusee';

    /** Disponible au retrait, ou prête à partir en livraison. */
    case PRETE = 'prete';

    /** Remise au patient — état terminal. */
    case REMISE = 'remise';

    /** Annulée par le patient, tant que rien n'est remis. */
    case ANNULEE = 'annulee';

    /** Libellé destiné au patient et au pharmacien (mêmes mots des deux côtés). */
    public function libelle(): string
    {
        return match ($this) {
            self::EN_ATTENTE => 'En attente',
            self::ACCEPTEE => 'Acceptée',
            self::REFUSEE => 'Refusée',
            self::PRETE => 'Prête',
            self::REMISE => 'Remise',
            self::ANNULEE => 'Annulée',
        };
    }

    /** La couleur Bootstrap du badge, portail comme mobile (présentation seule, aucune règle). */
    public function couleur(): string
    {
        return match ($this) {
            self::EN_ATTENTE => 'secondary',
            self::ACCEPTEE => 'info',
            self::REFUSEE => 'danger',
            self::PRETE => 'warning',
            self::REMISE => 'success',
            self::ANNULEE => 'dark',
        };
    }
}
