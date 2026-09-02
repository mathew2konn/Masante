<?php

namespace App\Support;

/**
 * Par quel canal un partenaire a réglé MaSanté (`reglements_facture_partenaire.moyen`).
 *
 * ⚠️ NE PAS CONFONDRE AVEC LE PAIEMENT PATIENT. Ce sont deux flux d'argent opposés : ici le
 * partenaire paie MaSanté, et il le fait par le canal qu'il veut — y compris un virement bancaire
 * ou des espèces au guichet. Ce flux ne passe PAS par la passerelle de paiement, qui ne connaît
 * que les règlements patient.
 *
 * `ESPECES` est donc une valeur légitime, et non un trou dans la traçabilité : la ligne de
 * règlement porte la date, le montant et un commentaire, ce qui est précisément ce qui manquerait
 * si l'encaissement était noté en incrémentant un compteur.
 */
enum MoyenReglement: string
{
    case WAVE = 'WAVE';
    case ORANGE_MONEY = 'ORANGE_MONEY';
    case MTN_MONEY = 'MTN_MONEY';
    case MOOV_MONEY = 'MOOV_MONEY';

    /** Virement bancaire — `reference_externe` porte la référence de l'ordre. */
    case VIREMENT = 'VIREMENT';

    /** Espèces encaissées par MaSanté. Aucune référence externe n'existe. */
    case ESPECES = 'ESPECES';

    /** Canal non prévu : le commentaire de la ligne porte le détail. */
    case AUTRE = 'AUTRE';
}
