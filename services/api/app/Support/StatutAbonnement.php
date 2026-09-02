<?php

namespace App\Support;

/**
 * États du contrat d'abonnement d'un établissement (facturation partenaire).
 *
 * Stockés en `VARCHAR` dans `abonnements_structure.statut`, jamais en ENUM SQL : un ENUM natif se
 * migre mal et fige le vocabulaire dans le moteur plutôt que dans le code qui le lit.
 *
 * CE N'EST PAS DE LA LOGIQUE MÉTIER : c'est un vocabulaire d'états. Les transitions — qui suspend,
 * quand, et à quelles conditions — vivent dans le service d'imputation et de recouvrement, hors de
 * ce lot (voir docs/REGLES_RECOUVREMENT_PARTENAIRE.md).
 */
enum StatutAbonnement: string
{
    /** Période d'essai en cours : 30 jours pour tous (R2 amendée le 26/08/2026). */
    case ESSAI = 'ESSAI';

    /** Abonnement payant en cours. */
    case ACTIF = 'ACTIF';

    /**
     * Suspendu — c'est l'état de la bascule au Palier 0 pour solde impayé.
     *
     * Il ne dépublie RIEN : l'établissement reste visible des patients, ses tarifs restent
     * affichés, le module urgence reste joignable. Il perd les fonctions du Palier 1.
     * Voir `MotifSuspension` et la spécification de recouvrement.
     */
    case SUSPENDU = 'SUSPENDU';

    /** Contrat terminé. Les factures émises restent dues : résilier n'efface pas une dette. */
    case RESILIE = 'RESILIE';
}
