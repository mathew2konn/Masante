<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Signalement;
use App\Models\StructureSanitaire;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Module 3 / étape 3A.2 — Signalements citoyens (F3.10).
 *
 * Création ANONYME possible (pas d'auth requise ; le `user_id` est renseigné si un token est
 * présent). Lecture publique limitée aux signalements VALIDÉS et rendus publics (historique).
 * La modération (validation/rejet) relève du portail admin (Module 4).
 */
class SignalementController extends Controller
{
    /** Historique public des signalements validés et publics d'une structure. */
    public function index(StructureSanitaire $structure): JsonResponse
    {
        $signalements = $structure->signalements()
            ->where('statut', 'valide')
            ->where('visible_publiquement', true)
            ->latest()
            ->get(['id', 'type', 'description', 'created_at']);

        return response()->json(['signalements' => $signalements]);
    }

    /** Dépose un signalement (anonyme ou rattaché au compte si authentifié). */
    public function store(Request $request, StructureSanitaire $structure): JsonResponse
    {
        $data = $request->validate([
            'type'        => ['required', 'in:structure_fermee,hors_service,pot_de_vin,mauvais_traitement,autre'],
            'description' => ['required', 'string', 'max:2000'],
        ]);

        $signalement = Signalement::create([
            'type'                 => $data['type'],
            'structure_id'         => $structure->id,
            'user_id'              => $request->user('sanctum')?->id, // null si anonyme (route publique)
            'description'          => $data['description'],
            'statut'               => 'en_attente',
            'visible_publiquement' => false,
        ]);

        return response()->json([
            'message' => 'Signalement enregistré. Il sera examiné par la modération.',
            'signalement' => $signalement->only(['id', 'type', 'statut', 'created_at']),
        ], 201);
    }
}
