<?php

namespace App\Http\Controllers\Api\V1\Carnet;

/** Rappels médicaments / RDV / vaccins / contrôles (F2.7) — CRUD seul, push différé. */
class RappelController extends CarnetSectionController
{
    protected function relation(): string
    {
        return 'rappels';
    }

    protected function regles(): array
    {
        return [
            'type'       => ['required', 'in:medicament,rendez_vous,vaccin,controle'],
            'titre'      => ['required', 'string', 'max:200'],
            'contenu'    => ['required', 'string', 'max:2000'],
            'frequence'  => ['required', 'in:unique,quotidien,hebdomadaire,mensuel'],
            'heure'      => ['required', 'date_format:H:i'],
            'date_debut' => ['required', 'date'],
            'date_fin'   => ['nullable', 'date', 'after_or_equal:date_debut'],
            'actif'      => ['nullable', 'boolean'],
        ];
    }
}
