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

        $item = $membre->{$this->relation()}()->create($request->validate($this->reglesPour(true)));

        return response()->json(['item' => $item], 201);
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
