<?php

namespace App\Http\Controllers\Api\V1\Carnet;

use App\Models\MembreFamille;
use App\Services\Vaccin\ServiceLienVaccination;
use Illuminate\Database\Eloquent\Model;

/**
 * Carnet de vaccination (F2.7).
 *
 * ═══ P6.8b — DEUX CHAMPS ONT DISPARU DES RÈGLES, ET C'EST TOUT L'INCRÉMENT ═══
 *
 * `statut` et `obligatoire` étaient les DEUX SEULES colonnes de cette table que le client
 * déclarait librement — et exactement les deux que lisait `FicheVitaleService` pour composer le
 * bloc « Vaccinations essentielles » montré à un secouriste SANS authentification, sous une icône
 * de bouclier coché.
 *
 *  - `statut` est désormais **calculé** ({@see App\Models\Vaccination::statut}). Demander à
 *    quelqu'un de déclarer ce que le serveur sait produirait deux réponses à une seule question.
 *  - `obligatoire` est un **fait de politique nationale** attaché à une dose précise (§8). Le faire
 *    cocher par l'utilisateur revenait à lui faire décrire la politique vaccinale de son pays. Il se
 *    lit désormais dans le calendrier quand la ligne y est rattachée, et vaut `false` sinon.
 *
 * **Les colonnes ne sont pas supprimées** (ADR-024, additif) : les lignes existantes conservent la
 * valeur qu'elles portent — leur en réécrire une serait un mensonge d'archive (précédent L2). Elles
 * ne sont simplement plus alimentées par le client, et `statut` n'est plus consulté.
 */
class VaccinationController extends CarnetSectionController
{
    public function __construct(private readonly ServiceLienVaccination $lien) {}

    protected function relation(): string
    {
        return 'vaccinations';
    }

    protected function regles(): array
    {
        return [
            // Reste requis et libre : le lien au référentiel est FACULTATIF, donc une ligne peut
            // n'être qu'un nom recopié d'un carnet papier. Aligné sur le référentiel dès qu'un
            // lien existe (`ServiceLienVaccination`).
            'vaccin_nom'          => ['required', 'string', 'max:200'],
            'date_administration' => ['nullable', 'date'],
            'date_rappel'         => ['nullable', 'date'],
            'centre_vaccination'  => ['nullable', 'string', 'max:200'],
            'numero_lot'          => ['nullable', 'string', 'max:100'],
            'medecin_nom'         => ['nullable', 'string', 'max:200'],
            // P6.8b — le lien au référentiel national. L'existence est vérifiée par le service et
            // non par `exists:`, pour que le message nomme le vaccin ou la dose introuvable au lieu
            // d'un « champ invalide » (précédent P6.6b).
            'vaccin_id'           => ['nullable', 'integer'],
            'numero_dose'         => ['nullable', 'integer', 'min:1', 'max:255'],
        ];
    }

    /**
     * Résolution serveur du lien, sur les TROIS chemins d'écriture.
     *
     * @param  array<string, mixed>  $valide
     * @return array<string, mixed>
     */
    public function preparerDonnees(array $valide, ?MembreFamille $membre = null): array
    {
        return $this->lien->resoudre($valide, $membre);
    }

    /**
     * Un vaccin retiré du référentiel est SIGNALÉ, jamais refusé — la vaccination a eu lieu.
     *
     * @return array<int, array<string, mixed>>
     */
    public function avertissements(Model $item): array
    {
        return $this->lien->avertissements($item->vaccin_code);
    }
}
