<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validation de la requête d'analyse de triage (F1.1/F1.2/F1.3).
 * Toute entrée client est considérée hostile jusqu'à validation (§8 Sécurité).
 */
class AnalyserTriageRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Endpoint public pour l'instant (auth téléphone+OTP non encore branchée).
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Symptômes sélectionnés : 1 à 20, devant exister et être actifs.
            'symptomes'      => ['required', 'array', 'min:1', 'max:20'],
            'symptomes.*'    => ['integer', 'distinct', 'exists:symptomes,id'],

            // Réponses au questionnaire complémentaire (optionnelles).
            'reponses'                 => ['sometimes', 'array', 'max:100'],
            'reponses.*.symptome_id'   => ['required_with:reponses', 'integer'],
            'reponses.*.cle'           => ['required_with:reponses', 'string', 'max:100'],
            'reponses.*.valeur'        => ['present'],

            // Contexte patient (en attendant le membre du Module 2).
            'membre_id'    => ['nullable', 'integer', 'min:1'],
            'patient_nom'  => ['nullable', 'string', 'max:200'],
            'patient_age'  => ['nullable', 'integer', 'min:0', 'max:120'],
            'patient_sexe' => ['nullable', 'in:M,F'],
        ];
    }

    public function messages(): array
    {
        return [
            'symptomes.required' => 'Veuillez sélectionner au moins un symptôme.',
            'symptomes.max'      => 'Vous ne pouvez pas sélectionner plus de 20 symptômes.',
            'symptomes.*.exists' => 'Un des symptômes sélectionnés est invalide.',
        ];
    }
}
