<?php

namespace App\Support;

/**
 * États d'une facture mensuelle adressée à un établissement.
 *
 * ═══ CES ÉTATS DÉCRIVENT UN SOLDE UNIQUE, PAS DEUX LIGNES ═══
 *
 * `montant_abonnement` et `montant_commissions` sont de la ventilation comptable. Il n'existe
 * aucun état « abonnement réglé, commission en attente » — parce qu'il n'existe aucun moyen de
 * régler l'un sans l'autre (décision D-E3, « les deux ou rien »). `PARTIELLEMENT_REGLEE` dit qu'il
 * reste un solde, jamais qu'une des deux natures serait éteinte.
 *
 * `IMPAYEE` n'est pas un état d'échec définitif : un règlement ultérieur la fait passer à `PAYEE`
 * et rouvre les fonctions du Palier 1 sans ressaisie.
 */
enum StatutFacturePartenaire: string
{
    /** En préparation, pas encore opposable : les montants peuvent encore bouger. */
    case BROUILLON = 'BROUILLON';

    /** Émise : les montants sont FIGÉS. Seuls `montant_regle`, le statut et la date de paiement bougent. */
    case EMISE = 'EMISE';

    /** Au moins un règlement imputé, solde encore positif. */
    case PARTIELLEMENT_REGLEE = 'PARTIELLEMENT_REGLEE';

    /** Solde à zéro. Plus aucune modification n'est acceptée. */
    case PAYEE = 'PAYEE';

    /** Échue depuis 30 jours avec un solde positif — déclenche la bascule au Palier 0. */
    case IMPAYEE = 'IMPAYEE';
}
