<?php

namespace App\Http\Controllers\Api\V1\Carnet;

/** Antécédents médicaux (F2.4) — alimentent le score de triage (impact_triage). */
class AntecedentController extends CarnetSectionController
{
    protected function relation(): string
    {
        return 'antecedents';
    }

    protected function regles(): array
    {
        return [
            'type'                => ['required', 'in:maladie_chronique,allergie,chirurgie,hospitalisation,autre'],
            'description'         => ['required', 'string', 'max:2000'],
            'date_diagnostic'     => ['nullable', 'date', 'before_or_equal:today'],
            'medecin_nom'         => ['nullable', 'string', 'max:200'],
            'structure_sanitaire' => ['nullable', 'string', 'max:200'],
            'traitement_actuel'   => ['nullable', 'string', 'max:2000'],
            // Impact borné 0-20 (la somme par membre est replafonnée à 20 par TriageService).
            'impact_triage'       => ['nullable', 'integer', 'between:0,20'],
            'added_by'            => ['nullable', 'in:patient,medecin'],
            // F2.13 — provenance de l'entrée (défaut BDD 'patient'). Distincte de added_by (auteur de saisie).
            'source'              => ['nullable', 'in:patient,medecin,structure'],
        ];
    }
}
