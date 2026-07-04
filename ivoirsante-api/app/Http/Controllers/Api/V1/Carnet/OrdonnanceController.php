<?php

namespace App\Http\Controllers\Api\V1\Carnet;

/** Ordonnances médicales (F2.5) — `medicaments_json` chiffré en base. */
class OrdonnanceController extends CarnetSectionController
{
    protected function relation(): string
    {
        return 'ordonnances';
    }

    protected function regles(): array
    {
        return [
            'triage_id'              => ['nullable', 'integer', 'exists:triages,id'],
            'medecin_nom'            => ['required', 'string', 'max:200'],
            'structure_sanitaire'    => ['required', 'string', 'max:200'],
            'date_prescription'      => ['required', 'date'],
            'medicaments_json'       => ['required', 'array', 'min:1'],
            'medicaments_json.*.nom' => ['required', 'string', 'max:200'],
            'photo_url'              => ['nullable', 'url', 'max:500'],
            'pdf_url'                => ['nullable', 'url', 'max:500'],
            'added_by'               => ['nullable', 'in:patient,medecin'],
            // F2.13 — provenance de l'entrée (défaut BDD 'patient'). Distincte de added_by (auteur de saisie).
            'source'                 => ['nullable', 'in:patient,medecin,structure'],
        ];
    }
}
