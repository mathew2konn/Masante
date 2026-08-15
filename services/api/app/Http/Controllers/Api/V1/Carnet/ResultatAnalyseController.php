<?php

namespace App\Http\Controllers\Api\V1\Carnet;

use App\Models\MembreFamille;
use App\Services\Analyse\ServiceLienAnalyse;
use App\Services\Analyse\ServiceLienResultat;

/**
 * Résultats d'analyses (F2.6) — `resultats_json` chiffré en base.
 *
 * P6.7a — chaque ligne de résultat peut désormais DÉSIGNER une entrée du catalogue national
 * (`analyse_id`). Le lien reste facultatif : un patient qui recopie un compte rendu papier n'a pas
 * de liste sous les yeux, et le catalogue est incomplet. Mais quand il est fourni, le code national,
 * le libellé et **l'unité** sont relus au catalogue et figés
 * ({@see App\Services\Analyse\ServiceLienAnalyse}).
 *
 * `medecin_prescripteur` et `laboratoire` sont des déclarations sur des TIERS : celui qui consigne
 * un résultat n'est pas forcément celui qui l'a prescrit, et l'analyse vient souvent d'un
 * laboratoire externe. On ne les devine donc PAS — P6.7a l'a fait un temps pour le prescripteur et
 * écrivait alors un nom faux. On les fait vérifier quand elles sont faites, par un lien facultatif
 * au référentiel ({@see App\Services\Analyse\ServiceLienResultat}).
 */
class ResultatAnalyseController extends CarnetSectionController
{
    public function __construct(
        private readonly ServiceLienAnalyse $lien,
        private readonly ServiceLienResultat $liensResultat,
    ) {
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
            // P6.7b — liens vers des TIERS : le prescripteur et le laboratoire qui a realise
            // l'analyse. Facultatifs (un compte rendu papier ne se choisit pas dans une liste),
            // mais verifies et figes quand ils sont fournis. L'existence est controlee par le
            // service, pour que le message nomme ce qui est introuvable.
            'medecin_prescripteur_id' => ['nullable', 'integer'],
            'laboratoire_id'          => ['nullable', 'integer'],
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
    public function preparerDonnees(array $valide, ?MembreFamille $membre = null): array
    {
        if (isset($valide['resultats_json']) && is_array($valide['resultats_json'])) {
            $valide['resultats_json'] = $this->lien->resoudre($valide['resultats_json']);
        }

        return $this->liensResultat->resoudre($valide);
    }
}
