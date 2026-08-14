<?php

namespace App\Http\Controllers\Api\V1\Carnet;

use App\Services\Analyse\ServiceLienAnalyse;

/**
 * Résultats d'analyses (F2.6) — `resultats_json` chiffré en base.
 *
 * P6.7a — chaque ligne de résultat peut désormais DÉSIGNER une entrée du catalogue national
 * (`analyse_id`). Le lien reste facultatif : un patient qui recopie un compte rendu papier n'a pas
 * de liste sous les yeux, et le catalogue est incomplet. Mais quand il est fourni, le code national,
 * le libellé et **l'unité** sont relus au catalogue et figés
 * ({@see App\Services\Analyse\ServiceLienAnalyse}).
 *
 * `medecin_prescripteur` reste saisi par le patient — mais il est RÉÉCRIT quand c'est un soignant
 * qui consigne le résultat ({@see App\Services\EcritureSoignantService}). P6.5 avait refermé cette
 * porte sur `ordonnances.medecin_nom` ; celle-ci portait un autre nom de colonne et était restée
 * ouverte.
 */
class ResultatAnalyseController extends CarnetSectionController
{
    public function __construct(private readonly ServiceLienAnalyse $lien)
    {
    }

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
            // P6.7a — la structure d'une ligne de résultat, là où il n'y en avait AUCUNE.
            'resultats_json.*.parametre'  => ['required', 'string', 'max:200'],
            'resultats_json.*.valeur'     => ['required', 'string', 'max:120'],
            'resultats_json.*.unite'      => ['nullable', 'string', 'max:40'],
            // Le lien au catalogue. L'existence est vérifiée par le service et non par `exists:`,
            // pour que le message nomme l'analyse introuvable au lieu d'un « champ invalide ».
            'resultats_json.*.analyse_id' => ['nullable', 'integer'],
            'fichier_url'          => ['nullable', 'url', 'max:500'],
            'added_by'             => ['nullable', 'in:patient,medecin'],
            // F2.13 — provenance de l'entrée (défaut BDD 'patient'). Distincte de added_by (auteur de saisie).
            'source'               => ['nullable', 'in:patient,medecin,structure'],
        ];
    }

    /**
     * Résolution serveur du lien au catalogue, sur les TROIS chemins d'écriture.
     *
     * @param  array<string, mixed>  $valide
     * @return array<string, mixed>
     */
    public function preparerDonnees(array $valide): array
    {
        if (isset($valide['resultats_json']) && is_array($valide['resultats_json'])) {
            $valide['resultats_json'] = $this->lien->resoudre($valide['resultats_json']);
        }

        return $valide;
    }
}
