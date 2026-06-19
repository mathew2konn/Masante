<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreMembreRequest;
use App\Http\Requests\UpdateMembreRequest;
use App\Models\MembreFamille;
use App\Services\MatriculeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Module 2 / étape 2A.2 — CRUD des membres de la famille (F2.1).
 *
 * Toutes les routes sont protégées par `auth:sanctum`. L'isolation entre comptes
 * (anti-IDOR, §4.3 Sécurité) repose sur deux garde-fous complémentaires :
 *  - en lecture de liste : on ne requête QUE les membres du compte authentifié ;
 *  - en accès unitaire (show/update/destroy) : `authorize()` via MembreFamillePolicy.
 *
 * Le `matricule_ivs` est attribué par le serveur (MatriculeService) et reste caché des
 * réponses JSON (cf. modèle MembreFamille).
 */
class MembreController extends Controller
{
    public function __construct(private readonly MatriculeService $matricules)
    {
    }

    /** Liste des membres du compte authentifié (F2.1). */
    public function index(Request $request): JsonResponse
    {
        return response()->json([
            'membres' => $request->user()->membresFamille()->latest()->get(),
        ]);
    }

    /** Création d'un membre + génération automatique du matricule interne. */
    public function store(StoreMembreRequest $request): JsonResponse
    {
        $membre = new MembreFamille($request->validated());
        $membre->user_id = $request->user()->id;
        $membre->matricule_ivs = $this->matricules->generer();
        $membre->save();

        return response()->json(['membre' => $membre], 201);
    }

    /** Détail d'un membre (réservé au propriétaire). */
    public function show(Request $request, MembreFamille $membre): JsonResponse
    {
        $this->authorize('view', $membre);

        return response()->json(['membre' => $membre]);
    }

    /** Mise à jour d'un membre (réservé au propriétaire). */
    public function update(UpdateMembreRequest $request, MembreFamille $membre): JsonResponse
    {
        $this->authorize('update', $membre);

        $membre->update($request->validated());

        return response()->json(['membre' => $membre]);
    }

    /** Suppression d'un membre (réservé au propriétaire). */
    public function destroy(Request $request, MembreFamille $membre): JsonResponse
    {
        $this->authorize('delete', $membre);

        $membre->delete();

        return response()->json(['message' => 'Membre supprimé.']);
    }
}
