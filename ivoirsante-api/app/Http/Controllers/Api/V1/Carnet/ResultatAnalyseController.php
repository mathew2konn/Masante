<?php

namespace App\Http\Controllers\Api\V1\Carnet;

/** Résultats d'analyses (F2.6) — `resultats_json` chiffré en base. */
class ResultatAnalyseController extends CarnetSectionController
{
    protected function relation(): string
    {
        return 'resultatsAnalyses';
    }

    protected function regles(): array
    {
        return [
            'type_analyse'         => ['required', 'in:biologique,radiologique,cardiologique,autre'],
            'intitule'             => ['required', 'string', 'max:200'],
            'date_analyse'         => ['required', 'date'],
            'laboratoire'          => ['nullable', 'string', 'max:200'],
            'medecin_prescripteur' => ['nullable', 'string', 'max:200'],
            'resultats_json'       => ['nullable', 'array'],
            'fichier_url'          => ['nullable', 'url', 'max:500'],
            'added_by'             => ['nullable', 'in:patient,medecin'],
        ];
    }
}
