<?php

namespace App\Support;

/**
 * États d'une ligne de commission prélevée sur une transaction patient.
 *
 * `FACTUREE` ENGAGE UNE PROMESSE VÉRIFIABLE : la commission pointe alors la facture partenaire qui
 * la porte (`facture_partenaire_id` non nul). Un statut `FACTUREE` sans cette référence rendrait
 * tout rapprochement impossible — on saurait qu'elle a été facturée sans pouvoir dire où.
 *
 * NOTE D'ÉVOLUTION, délibérément NON implémentée : si la passerelle ouvre un jour le paiement
 * fractionné, un état `PRELEVEE_A_LA_SOURCE` s'ajoutera et la commission cessera d'être portée par
 * la facture partenaire. Cette valeur n'existe pas aujourd'hui — aucun code ne la produirait, et
 * un état que rien n'atteint finit par être cru atteignable.
 */
enum StatutCommission: string
{
    /** Calculée sur un encaissement, pas encore rattachée à une facture mensuelle. */
    case CALCULEE = 'CALCULEE';

    /** Portée par une facture partenaire. Plus aucune modification n'est acceptée. */
    case FACTUREE = 'FACTUREE';

    /** Annulée (remboursement, erreur constatée avant facturation). La ligne demeure. */
    case ANNULEE = 'ANNULEE';
}
