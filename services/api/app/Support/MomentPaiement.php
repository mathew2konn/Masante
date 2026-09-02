<?php

namespace App\Support;

/**
 * Quand le patient règle, par rapport à l'acte (`factures_patient.moment_paiement`).
 *
 * La distinction n'est pas cosmétique : elle décide de ce qu'on relance. Une facture AVANT_ACTE
 * non réglée signifie que l'acte n'a pas eu lieu ; une facture APRES_ACTE non réglée signifie que
 * le soin a été délivré et reste dû. Confondre les deux ferait relancer un patient pour un acte
 * qu'il n'a jamais reçu.
 */
enum MomentPaiement: string
{
    /** Règlement exigé avant la prestation (consultation programmée, examen). */
    case AVANT_ACTE = 'AVANT_ACTE';

    /** Règlement après la prestation (urgence, hospitalisation, acte non prévu). */
    case APRES_ACTE = 'APRES_ACTE';
}
