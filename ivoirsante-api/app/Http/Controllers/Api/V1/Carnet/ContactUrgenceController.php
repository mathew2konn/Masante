<?php

namespace App\Http\Controllers\Api\V1\Carnet;

/**
 * Contacts d'urgence par membre (F2.11). CRUD générique : l'invariant « un seul contact principal
 * par membre » est garanti au niveau du modèle (ContactUrgence::booted), quel que soit le point
 * d'entrée. Téléphone au format CI (+225 + 10 chiffres), comme l'inscription.
 */
class ContactUrgenceController extends CarnetSectionController
{
    protected function relation(): string
    {
        return 'contactsUrgence';
    }

    protected function regles(): array
    {
        return [
            'nom'                  => ['required', 'string', 'max:200'],
            'lien_parente'         => ['nullable', 'string', 'max:100'],
            'telephone'            => ['required', 'string', 'regex:/^\+225[0-9]{10}$/'],
            'telephone_secondaire' => ['nullable', 'string', 'regex:/^\+225[0-9]{10}$/'],
            'email'                => ['nullable', 'email', 'max:150'],
            'est_principal'        => ['nullable', 'boolean'],
        ];
    }
}
