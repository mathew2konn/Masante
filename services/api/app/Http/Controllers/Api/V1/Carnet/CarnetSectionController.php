<?php

namespace App\Http\Controllers\Api\V1\Carnet;

use App\Http\Controllers\Controller;
use App\Models\MembreFamille;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Module 2 / étape 2A.4 — Base CRUD des sections du carnet (antécédents, vaccinations,
 * ordonnances, résultats, rappels). Factorise le comportement commun ; chaque section ne
 * déclare que sa relation et ses règles de validation.
 *
 * Sécurité (anti-IDOR, §4.3) en deux temps :
 *  1. on autorise l'action sur le MEMBRE parent via MembreFamillePolicy (appartenance) ;
 *  2. tout élément est systématiquement requêté À TRAVERS la relation du membre, donc un id
 *     appartenant à un autre membre renvoie 404 (jamais d'accès transversal).
 */
abstract class CarnetSectionController extends Controller
{
    /** Nom de la relation HasMany sur MembreFamille (ex. 'antecedents'). */
    abstract protected function relation(): string;

    /**
     * Règles de validation à la création (valeurs = tableaux de règles).
     *
     * @return array<string, array<int, mixed>>
     */
    abstract protected function regles(): array;

    public function index(MembreFamille $membre): JsonResponse
    {
        $this->authorize('view', $membre);

        return response()->json(['items' => $membre->{$this->relation()}()->latest()->get()]);
    }

    public function store(Request $request, MembreFamille $membre): JsonResponse
    {
        $this->authorize('update', $membre);

        $valide = $this->preparerDonnees($request->validate($this->reglesPour(true)));
        $item   = $membre->{$this->relation()}()->create($valide);

        return response()->json(array_filter([
            'item'          => $item,
            'avertissements' => $this->avertissements($item) ?: null,
        ], static fn ($v) => $v !== null), 201);
    }

    public function show(MembreFamille $membre, int $id): JsonResponse
    {
        $this->authorize('view', $membre);

        return response()->json(['item' => $this->trouver($membre, $id)]);
    }

    public function update(Request $request, MembreFamille $membre, int $id): JsonResponse
    {
        $this->authorize('update', $membre);

        $item = $this->trouver($membre, $id);
        $item->update($request->validate($this->reglesPour(false)));

        return response()->json(['item' => $item]);
    }

    public function destroy(MembreFamille $membre, int $id): JsonResponse
    {
        $this->authorize('update', $membre);

        $this->trouver($membre, $id)->delete();

        return response()->json(['message' => 'Élément supprimé.']);
    }

    /**
     * Nom de la relation, exposé au dépôt de contributions (incrément C).
     *
     * Accesseurs publics plutôt que passage de `relation()`/`regles()` en public : ces deux-là
     * sont abstraites, les rendre publiques obligerait à modifier les onze sous-classes — toutes
     * validées G5. Un délégateur ici ne touche personne.
     */
    public function nomRelation(): string
    {
        return $this->relation();
    }

    /**
     * Règles de validation de la section, pour valider une contribution AU DÉPÔT.
     *
     * @return array<string, array<int, mixed>>
     */
    public function reglesDeCreation(): array
    {
        return $this->regles();
    }

    /**
     * Dérivations SERVEUR appliquées aux données validées, juste avant l'écriture.
     *
     * Identité par défaut : les onze sous-classes n'ont rien à changer. Ce point d'accroche existe
     * parce qu'une section peut avoir des valeurs que le serveur SAIT et n'a pas à croire du client
     * — c'est le cas des ordonnances depuis P6.6b, où le code national et la DCI d'un médicament
     * sont repris du référentiel et jamais de la requête.
     *
     * Appelé par les TROIS chemins d'écriture (le patient ici, le délégué via
     * {@see App\Services\ContributionCarnetService}, le soignant via
     * {@see App\Services\EcritureSoignantService}) : une garantie qui ne vaudrait que sur l'un des
     * trois n'en serait pas une.
     *
     * @param  array<string, mixed>  $valide
     * @return array<string, mixed>
     */
    public function preparerDonnees(array $valide): array
    {
        return $valide;
    }

    /**
     * Avertissements NON BLOQUANTS joints à la réponse de création.
     *
     * Aucun par défaut. Un avertissement dit quelque chose que le prescripteur doit savoir ; il ne
     * refuse rien — refuser serait une décision médicale prise par une machine (CDC_00 §4).
     *
     * @return array<int, array<string, mixed>>
     */
    public function avertissements(Model $item): array
    {
        return [];
    }

    /** Récupère un élément SCOPÉ au membre (anti-IDOR) ou échoue en 404. */
    private function trouver(MembreFamille $membre, int $id): Model
    {
        return $membre->{$this->relation()}()->findOrFail($id);
    }

    /**
     * Règles selon le contexte : à la mise à jour, tous les champs deviennent optionnels
     * (`sometimes`) pour permettre des modifications partielles.
     *
     * @return array<string, array<int, mixed>>
     */
    protected function reglesPour(bool $creation): array
    {
        $regles = $this->regles();

        if ($creation) {
            return $regles;
        }

        return array_map(
            fn (array $r) => array_values(array_unique(array_merge(['sometimes'], $r))),
            $regles
        );
    }
}
