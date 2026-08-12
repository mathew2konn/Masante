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

    /**
     * Détail d'un membre — propriétaire, ou délégué en lecture depuis l'incrément A.
     *
     * `est_proprietaire` (D2) : le modèle CACHE `user_id` (anti-fuite), si bien que le client ne
     * peut pas savoir s'il regarde son carnet ou celui d'un proche. Il affichait donc à tout le
     * monde les actions de gouvernance — journal d'accès brut, gestion des délégués — qui
     * renvoient un 403 à un délégué. Le serveur répond désormais à la question au lieu de laisser
     * le client la deviner.
     *
     * AJOUT PUREMENT ADDITIF au contrat du Module 2 : rien n'est retiré ni renommé, le cache
     * hors-ligne (P2) continue de lire les mêmes champs.
     */
    public function show(Request $request, MembreFamille $membre): JsonResponse
    {
        $this->authorize('view', $membre);

        return response()->json([
            'membre'           => $membre,
            'est_proprietaire' => $membre->user_id === $request->user()->id,
        ]);
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

    /**
     * Historique des accès au dossier d'un membre (droit d'accès patient, §10.3 Sécurité ;
     * loi 2013-450). Le patient voit qui a consulté le dossier, quand et comment.
     */
    public function acces(Request $request, MembreFamille $membre): JsonResponse
    {
        $this->authorize('viewAcces', $membre);

        return response()->json([
            'acces' => $membre->accesDossier()->latest('created_at')->get(),
        ]);
    }
}
