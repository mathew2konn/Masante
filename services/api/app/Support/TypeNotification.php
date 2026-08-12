<?php

namespace App\Support;

/**
 * Types de notification en application (carnet familial partagé, incrément D1).
 *
 * Miroir PHP de `TypeNotification` dans `@masante/shared` : toute valeur ajoutée ici doit l'être
 * là-bas. Le mobile ne s'en sert que pour choisir une icône et un écran de destination — il ne
 * déduit jamais un type, il l'affiche.
 *
 * RAPPEL DE LA RÈGLE POSÉE AU G1 : le titre et le corps produits ici ne contiennent AUCUN contenu
 * médical. « Aya a proposé un ajout au carnet de Koffi Eli », jamais « fièvre 39 ». Ces textes
 * atterrissent sur des écrans verrouillés et transitent par un service tiers.
 */
enum TypeNotification: string
{
    case CONTRIBUTION_DEPOSEE = 'CONTRIBUTION_DEPOSEE';
    case CONTRIBUTION_VALIDEE = 'CONTRIBUTION_VALIDEE';
    case CONTRIBUTION_REJETEE = 'CONTRIBUTION_REJETEE';
    case DELEGATION_RECUE     = 'DELEGATION_RECUE';
    case RESPONSABLE_DESIGNE  = 'RESPONSABLE_DESIGNE';
    case DOSSIER_CONSULTE     = 'DOSSIER_CONSULTE';

    /**
     * Le titre court affiché en tête de la notification (et sur la bannière push).
     */
    public function titre(): string
    {
        return match ($this) {
            self::CONTRIBUTION_DEPOSEE => 'Un ajout attend votre validation',
            self::CONTRIBUTION_VALIDEE => 'Ajout validé',
            self::CONTRIBUTION_REJETEE => 'Ajout refusé',
            self::DELEGATION_RECUE     => 'Un carnet vous a été partagé',
            self::RESPONSABLE_DESIGNE  => 'Vous êtes responsable de famille',
            self::DOSSIER_CONSULTE     => 'Dossier consulté',
        };
    }

}
