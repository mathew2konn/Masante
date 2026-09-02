<?php

namespace App\Support;

/**
 * Pourquoi un abonnement a été suspendu (`abonnements_structure.motif_suspension`).
 *
 * POURQUOI LE MOTIF EST UNE COLONNE, ET NON UNE DÉDUCTION. Une suspension pour impayé et une
 * suspension demandée par le partenaire produisent le même statut mais n'appellent ni la même
 * relance, ni la même réactivation, ni le même discours. Les distinguer après coup en regardant
 * s'il reste une facture ouverte serait une reconstitution — donc une source d'erreur le jour où
 * la facture a été réglée entre-temps.
 */
enum MotifSuspension: string
{
    /** Solde impayé 30 jours après l'échéance. Bascule au Palier 0, réversible au règlement. */
    case IMPAYE = 'IMPAYE';

    /** Le partenaire a demandé la suspension. Aucune dette n'est en cause. */
    case DEMANDE_PARTENAIRE = 'DEMANDE_PARTENAIRE';

    /** Décision administrative ou cas non prévu : le commentaire libre porte le détail. */
    case AUTRE = 'AUTRE';
}
