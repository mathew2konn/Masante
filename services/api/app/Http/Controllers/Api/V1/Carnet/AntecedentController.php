<?php

namespace App\Http\Controllers\Api\V1\Carnet;

use App\Models\MembreFamille;
use App\Services\Maladie\ServiceLienMaladie;
use Illuminate\Database\Eloquent\Model;

/**
 * Antécédents médicaux (F2.4) — alimentent le score de triage (impact_triage).
 *
 * ═══ P6.8c — LE LIEN AU RÉFÉRENTIEL DES MALADIES ═══
 *
 * `description` portait la maladie chronique en texte libre chiffré, et c'est cette chaîne que
 * `FicheVitaleService` montre à un secouriste SANS authentification. « diabète », « Diabète type 2 »
 * et « DT2 » sont trois chaînes qu'aucune requête ne rapproche.
 *
 * Ce qui change : un lien FACULTATIF vers le référentiel national. Ce qui NE change PAS, et qui est
 * l'essentiel :
 *
 *  - **`description` n'est jamais réécrite.** Le lien s'ajoute À CÔTÉ des mots du patient. C'est la
 *    leçon de P6.7a, dont la réécriture du prescripteur inscrivait le nom du MAUVAIS médecin — et
 *    *une affirmation fausse portée par le système est plus difficile à contester qu'une saisie
 *    humaine non vérifiée*.
 *  - **Le serveur ne devine jamais.** Aucun rapprochement automatique du texte libre vers un code :
 *    ce serait un diagnostic posé par une machine (CDC_00 §4). Le lien est déclaré par l'humain qui
 *    saisit, comme le prescripteur et le laboratoire en P6.7b.
 */
class AntecedentController extends CarnetSectionController
{
    public function __construct(private readonly ServiceLienMaladie $lien) {}

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
            // P6.8c — le lien au référentiel national. L'existence est vérifiée par le service et
            // non par `exists:`, pour que le message nomme la maladie introuvable au lieu d'un
            // « champ invalide » (précédent P6.6b). `maladie_code` et `maladie_libelle` ne sont PAS
            // déclarés ici : ils sont posés par le serveur, et le service les efface en second
            // rideau (leçon de la mutation de P6.6b, où un vecteur ne testait que le validateur).
            'maladie_id'          => ['nullable', 'integer'],
        ];
    }

    /**
     * Résolution serveur du lien, sur les TROIS chemins d'écriture — et sur le `PUT`.
     *
     * @param  array<string, mixed>  $valide
     * @return array<string, mixed>
     */
    public function preparerDonnees(array $valide, ?MembreFamille $membre = null): array
    {
        return $this->lien->resoudreAntecedent($valide);
    }

    /**
     * Une maladie retirée du référentiel est SIGNALÉE, jamais refusée — l'antécédent est réel.
     *
     * @return array<int, array<string, mixed>>
     */
    public function avertissements(Model $item): array
    {
        return $this->lien->avertissements($item->maladie_code);
    }
}
