<?php

namespace App\Http\Controllers\Api\V1\Carnet;

/** Carnet de vaccination (F2.7). */
class VaccinationController extends CarnetSectionController
{
    protected function relation(): string
    {
        return 'vaccinations';
    }

    protected function regles(): array
    {
        return [
            'vaccin_nom'          => ['required', 'string', 'max:200'],
            'obligatoire'         => ['nullable', 'boolean'],
            'date_administration' => ['nullable', 'date'],
            'date_rappel'         => ['nullable', 'date'],
            'statut'              => ['required', 'in:fait,a_faire,en_retard'],
            'centre_vaccination'  => ['nullable', 'string', 'max:200'],
            'numero_lot'          => ['nullable', 'string', 'max:100'],
            'medecin_nom'         => ['nullable', 'string', 'max:200'],
        ];
    }
}
