<?php

namespace App\Http\Controllers\Api\V1\Carnet;

use App\Models\MembreFamille;
use App\Services\Analyse\ServiceLienAnalyse;

/**
 * Demandes d'examens (B5-a, CDC_11 §8.1) — `analyses_json` chiffré en base.
 *
 * Analogue exact d'{@see OrdonnanceController} (décision L1 du plan G1) : chaque ligne peut
 * désigner une entrée du catalogue national (`analyse_id`). Le lien reste facultatif — le
 * catalogue est un jeu de démonstration de huit analyses (P6.7a), et refuser de prescrire un
 * examen absent de notre liste serait une décision médicale prise par une machine (CDC_00 §4) —
 * mais quand il est fourni, le code national et l'unité sont relus au catalogue et **figés**
 * ({@see ServiceLienAnalyse}, P6.7a). `libelle`, les mots du prescripteur, n'est JAMAIS réécrit
 * (leçon P6.7b).
 *
 * L4/K5/K11 — `source` N'APPARAÎT PAS dans les règles ci-dessous, dès l'origine : un client ne
 * déclare pas la provenance de ce qu'il écrit (quatrième application d'une règle déjà tenue pour
 * `source` P7-C, `obligatoire` P6.8b, `provenance` P6.8d et `origine` P10c-1).
 */
class DemandeAnalyseController extends CarnetSectionController
{
    public function __construct(private readonly ServiceLienAnalyse $lien) {}

    protected function relation(): string
    {
        return 'demandesAnalyses';
    }

    protected function regles(): array
    {
        return [
            // Repris tel quel par le patient qui recopie une demande papier ; réécrits par le
            // serveur quand un soignant écrit depuis une consultation (`EcritureSoignantService`,
            // qui les vérifie par leur nom LITTÉRAL — patron `AntecedentController`/
            // `OrdonnanceController`, mêmes noms de colonnes).
            'medecin_nom' => ['required', 'string', 'max:200'],
            'structure_sanitaire' => ['required', 'string', 'max:200'],
            'date_demande' => ['required', 'date'],
            'renseignements_cliniques' => ['nullable', 'string', 'max:2000'],
            'analyses_json' => ['required', 'array', 'min:1'],
            'analyses_json.*.libelle' => ['required', 'string', 'max:200'],
            // Le lien au catalogue (L2). `nullable` : le libellé libre suffit toujours. L'existence
            // est vérifiée par le service, pas par `exists:` — pour que le message nomme l'examen
            // introuvable au lieu d'un « champ invalide » qui n'aide personne.
            'analyses_json.*.analyse_id' => ['nullable', 'integer'],
            'analyses_json.*.conditions_prelevement' => ['nullable', 'string', 'max:500'],
            'added_by' => ['nullable', 'in:patient,medecin'],
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
        if (isset($valide['analyses_json']) && is_array($valide['analyses_json'])) {
            $valide['analyses_json'] = $this->lien->resoudre($valide['analyses_json'], 'analyses_json');
        }

        return $valide;
    }
}
