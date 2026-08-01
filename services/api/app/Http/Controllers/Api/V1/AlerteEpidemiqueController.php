<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AlerteEpidemique;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Module 5 / 5.4 — Alertes épidémiques côté patient (CdC FN3).
 *
 * L'utilisateur ne reçoit que les alertes EN VIGUEUR de SA commune de résidence (`users.commune`),
 * plus les alertes nationales — FN3 : « alerte uniquement les utilisateurs de la commune concernée ».
 * Le compte sans commune renseignée ne voit que les alertes nationales.
 *
 * Pas de push ici (ni Firebase ni FCM dans le projet) : le mobile interroge cet endpoint et affiche
 * une bannière. La notification poussée reste à brancher au module Notifications.
 */
class AlerteEpidemiqueController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $commune = $request->user()->commune;

        // Les plus graves d'abord. `CASE` plutôt que `FIELD()` (propre à MySQL) : portable SQLite.
        $alertes = AlerteEpidemique::enVigueur()
            ->pourCommune($commune)
            ->orderByRaw("CASE niveau_alerte WHEN 'alerte' THEN 0 WHEN 'vigilance' THEN 1 ELSE 2 END")
            ->orderByDesc('date_debut')
            ->get([
                'id', 'commune', 'titre', 'description', 'maladie',
                'niveau_alerte', 'source', 'date_debut', 'date_fin',
            ]);

        return response()->json([
            'commune' => $commune,
            'alertes' => $alertes,
        ]);
    }
}
