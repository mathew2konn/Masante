<?php

namespace App\Support;

/**
 * États d'une facture adressée au patient par l'établissement.
 *
 * `PRISE_EN_CHARGE_TOTALE` est distinct de `PAYEE`, et la distinction compte : dans le premier
 * cas la couverture (CMU, assurance) éteint la totalité et le patient ne doit rien ; dans le
 * second il a réglé son reste à charge. Les confondre ferait disparaître des statistiques la part
 * réellement prise en charge — exactement ce qu'un régime de couverture a besoin de mesurer.
 *
 * `EXPIREE` concerne une facture AVANT_ACTE non réglée dans le délai : l'acte n'a pas eu lieu.
 * Ce n'est pas une dette, et elle ne doit jamais être relancée comme telle.
 */
enum StatutFacturePatient: string
{
    /** Émise, reste à charge dû. */
    case A_REGLER = 'A_REGLER';

    /** Reste à charge réglé. Plus aucune modification n'est acceptée. */
    case PAYEE = 'PAYEE';

    /** La couverture éteint la totalité : le patient ne doit rien. */
    case PRISE_EN_CHARGE_TOTALE = 'PRISE_EN_CHARGE_TOTALE';

    /** Annulée par l'établissement. Aucune suppression : la trace demeure. */
    case ANNULEE = 'ANNULEE';

    /** Délai dépassé sur un règlement attendu avant l'acte. */
    case EXPIREE = 'EXPIREE';
}
