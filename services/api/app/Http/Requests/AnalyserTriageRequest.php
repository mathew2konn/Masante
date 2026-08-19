<?php

namespace App\Http\Requests;

use App\Models\Symptome;
use App\Services\Triage\ServiceSymptomesTriage;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validation de la requête d'analyse de triage (F1.1/F1.2/F1.3).
 * Toute entrée client est considérée hostile jusqu'à validation (§8 Sécurité).
 *
 * ═══ P10a — LA VALIDATION AUSSI DOIT LIRE LA VERSION PUBLIÉE ═══
 *
 * Elle disait `exists:symptomes,id` : la TABLE. Basculer le seul service aurait donc laissé passer
 * un symptôme présent en base mais **absent de la version en vigueur** — accepté par la validation,
 * puis ignoré en silence par le calcul. Le citoyen l'aurait coché, son score n'en aurait pas tenu
 * compte, et rien ne le lui aurait dit.
 *
 * C'est mot pour mot le constat **C1 de L1+L2** : *deux lectures contournaient le service*, la
 * seconde étant la validation. Le défaut n'a pas été trouvé par relecture ici non plus — il est
 * ressorti en cherchant tous les lecteurs restants de la table.
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
            // Symptômes sélectionnés : 1 à 20, devant appartenir à la VERSION EN VIGUEUR.
            'symptomes'      => ['required', 'array', 'min:1', 'max:20'],
            'symptomes.*'    => ['integer', 'distinct', Rule::in($this->idsEnVigueur())],

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

    /**
     * Les identifiants proposables, c'est-à-dire ceux des symptômes ACTIFS de la version publiée.
     *
     * Le refus bruyant du service s'applique donc dès la validation : sans version en vigueur, la
     * requête répond 503 avant même d'examiner les entrées. C'est voulu — répondre 422 « symptôme
     * invalide » laisserait croire à une erreur de saisie alors que c'est la publication qui manque.
     *
     * @return array<int, int>
     */
    private function idsEnVigueur(): array
    {
        return app(ServiceSymptomesTriage::class)
            ->actifs()
            ->map(fn (Symptome $s): int => (int) $s->id)
            ->all();
    }

    public function messages(): array
    {
        return [
            'symptomes.required' => 'Veuillez sélectionner au moins un symptôme.',
            'symptomes.max'      => 'Vous ne pouvez pas sélectionner plus de 20 symptômes.',
            'symptomes.*.in'     => 'Un des symptômes sélectionnés ne fait pas partie de la version '
                .'en vigueur du référentiel de triage.',
        ];
    }
}
