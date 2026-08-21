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
            'symptomes' => ['required', 'array', 'min:1', 'max:20'],
            'symptomes.*' => ['integer', 'distinct', Rule::in($this->idsEnVigueur())],

            // ═══ P10b-3-i — `symptome_id` DISPARAÎT DE LA RÉPONSE ═══
            //
            // Une question n'appartient plus à un symptôme : elle appartient à la version du
            // protocole `TRIAGE-QUESTIONNAIRE`, et sa clé y est unique. Garder `symptome_id`
            // laisserait croire qu'une même clé peut avoir deux sens selon le symptôme — ce que la
            // contrainte `uq_protocole_question_cle` rend précisément impossible.
            //
            // La FORME seulement est validée ici. Le fond — type, plage d'une échelle,
            // appartenance aux réponses possibles — est confronté à l'instantané publié par
            // {@see \App\Services\Triage\ServiceQuestionnaire::normaliser()}, seul endroit qui
            // connaisse la version en vigueur.
            'reponses' => ['sometimes', 'array', 'max:100'],
            'reponses.*.cle' => ['required_with:reponses', 'string', 'max:60'],
            'reponses.*.valeur' => ['present'],

            // ═══ P10c-1 — LES CONSTANTES CLINIQUES DU §5.2 ═══
            //
            // La FORME seulement, comme pour les réponses. Le fond — type appartenant à la version
            // publiée, bornes de plausibilité, précision — est confronté à l'instantané par
            // {@see \App\Services\Triage\ServiceConstantesTriage::normaliser()}, seul endroit qui
            // connaisse la version en vigueur.
            //
            // NI `origine` NI `mesure_id` NE SONT ACCEPTÉS DU CLIENT. C'est le serveur qui
            // reconnaît une valeur reprise du carnet, en la comparant à ce qu'il a lui-même
            // proposé. Laisser le client déclarer sa propre provenance rejouerait la faute
            // refermée quatre fois : `source` d'une contribution (P7-C), `obligatoire` d'une
            // vaccination (P6.8b), `provenance` d'une couverture (P6.8d), `medecin_nom` d'une
            // ordonnance (P6.5a).
            'constantes' => ['sometimes', 'array', 'max:20'],
            'constantes.*.type_mesure' => ['required_with:constantes', 'string', 'max:40'],
            'constantes.*.valeur' => ['present'],

            // Contexte patient (en attendant le membre du Module 2).
            'membre_id' => ['nullable', 'integer', 'min:1'],
            'patient_nom' => ['nullable', 'string', 'max:200'],
            'patient_age' => ['nullable', 'integer', 'min:0', 'max:120'],
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
            'symptomes.max' => 'Vous ne pouvez pas sélectionner plus de 20 symptômes.',
            'symptomes.*.in' => 'Un des symptômes sélectionnés ne fait pas partie de la version '
                .'en vigueur du référentiel de triage.',
        ];
    }
}
