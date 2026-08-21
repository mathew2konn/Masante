<?php

namespace App\Http\Requests;

use App\Models\Symptome;
use App\Services\Triage\ServiceQuestionnaire;
use App\Services\Triage\ServiceSymptomesTriage;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * P10b-3-i — Un tour de questionnaire adaptatif (CDC_08 §4.3b).
 *
 * Mêmes entrées que l'analyse, moins ce qui n'a pas encore de sens à ce stade (le nom du patient
 * n'entre dans aucune règle). Le client envoie ce qu'il sait, le serveur répond ce qu'il reste à
 * demander.
 *
 * ═══ LA VALIDATION DES RÉPONSES N'EST PAS ICI, ET C'EST DÉLIBÉRÉ ═══
 *
 * Une règle `Rule::in(...)` sur les clés ferait de cette classe un second endroit connaissant la
 * version publiée du questionnaire. Or le contrôle de fond — type, plage d'échelle, appartenance
 * aux réponses possibles — vit dans {@see ServiceQuestionnaire::normaliser()},
 * qui a l'instantané sous les yeux.
 *
 * Le dédoublement compte : un vecteur qui passe par HTTP prouverait le validateur et non la garde.
 * Ici la garde est unique, et un import qui appellerait le service directement serait soumis
 * exactement au même contrôle.
 */
class QuestionsTriageRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Endpoint public, comme `analyser` : un triage anonyme reste possible (Module 1).
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'symptomes' => ['required', 'array', 'min:1', 'max:20'],
            'symptomes.*' => ['integer', 'distinct', Rule::in($this->idsEnVigueur())],

            // Les réponses déjà obtenues aux tours précédents. La FORME seulement — le fond est
            // confronté à la version publiée par le service.
            'reponses' => ['sometimes', 'array', 'max:100'],
            'reponses.*.cle' => ['required_with:reponses', 'string', 'max:60'],
            'reponses.*.valeur' => ['present'],

            // P10c-1 — Les constantes sont connues dès la sélection des symptômes, donc envoyées
            // ici aussi. Une règle de questionnaire peut ainsi réagir à une fièvre (« 39,5 —
            // depuis quand ? »), ce qui est l'adaptativité du §4.3b. Les passer à un seul des deux
            // endpoints rejouerait le constat Z1 : un fait inconnu lève, donc une telle règle
            // ferait tomber `POST /triage/questions` et pas `POST /triage/analyser`.
            'constantes' => ['sometimes', 'array', 'max:20'],
            'constantes.*.type_mesure' => ['required_with:constantes', 'string', 'max:40'],
            'constantes.*.valeur' => ['present'],

            'patient_age' => ['nullable', 'integer', 'min:0', 'max:120'],
            'patient_sexe' => ['nullable', 'in:M,F'],
        ];
    }

    /**
     * Les symptômes proposables — ceux de la version publiée (motif P10a).
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
            'symptomes.*.in' => 'Un des symptômes sélectionnés ne fait pas partie de la version '
                .'en vigueur du référentiel de triage.',
        ];
    }
}
