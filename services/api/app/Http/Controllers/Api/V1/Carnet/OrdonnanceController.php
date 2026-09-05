<?php

namespace App\Http\Controllers\Api\V1\Carnet;

use App\Models\MembreFamille;
use App\Models\Ordonnance;
use App\Services\Medicament\ServiceLienMedicament;
use Illuminate\Database\Eloquent\Model;

/**
 * Ordonnances médicales (F2.5) — `medicaments_json` chiffré en base.
 *
 * P6.6b — chaque ligne peut désormais DÉSIGNER un produit du référentiel national (`medicament_id`).
 * Le lien reste facultatif : un patient qui recopie une ordonnance papier n'a pas de liste sous les
 * yeux, et le référentiel est incomplet. Mais quand il est fourni, le code national, la DCI et le
 * dosage sont relus au référentiel et **figés** — jamais crus du client
 * ({@see App\Services\Medicament\ServiceLienMedicament}).
 */
class OrdonnanceController extends CarnetSectionController
{
    public function __construct(private readonly ServiceLienMedicament $lien)
    {
    }

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
            // Le lien au référentiel national (§6.1). `nullable` : le nom libre suffit toujours.
            // L'existence est vérifiée par le service, pas par `exists:` — pour que le message
            // nomme le produit introuvable au lieu d'un « champ invalide » qui n'aide personne.
            'medicaments_json.*.medicament_id' => ['nullable', 'integer'],
            'medicaments_json.*.posologie'     => ['nullable', 'string', 'max:200'],
            'photo_url'              => ['nullable', 'url', 'max:500'],
            'pdf_url'                => ['nullable', 'url', 'max:500'],
            'added_by'               => ['nullable', 'in:patient,medecin'],
            // B5-a (L4/K5/K11) — `source` retirée : voir le commentaire identique
            // d'`AntecedentController`, même défaut, même correction.
        ];
    }

    /**
     * Résolution serveur du lien au référentiel, sur les TROIS chemins d'écriture.
     *
     * @param  array<string, mixed>  $valide
     * @return array<string, mixed>
     */
    public function preparerDonnees(array $valide, ?MembreFamille $membre = null): array
    {
        if (isset($valide['medicaments_json']) && is_array($valide['medicaments_json'])) {
            $valide['medicaments_json'] = $this->lien->resoudre($valide['medicaments_json']);
        }

        return $valide;
    }

    /**
     * Ce que le prescripteur doit savoir — sans que rien ne soit refusé.
     *
     * @param  Ordonnance  $item
     * @return array<int, array<string, mixed>>
     */
    public function avertissements(Model $item): array
    {
        return $this->lien->avertissements($item->medicaments_json ?? []);
    }
}
