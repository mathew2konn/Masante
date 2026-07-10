<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AlerteSos;
use App\Models\MembreFamille;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

/**
 * Module 5 / 5.2 — Journalisation des alertes SOS (CdC FN1).
 *
 * L'ALERTE ELLE-MÊME NE PASSE PAS PAR ICI. L'appel au SAMU 185 et le SMS au contact d'urgence
 * partent du téléphone (liens `tel:` / `sms:`) : ils fonctionnent sans données mobiles, ce qui est
 * précisément le cas visé par FN1 (« fonctionne offline via SMS si pas de data »). Le projet n'a
 * d'ailleurs aucune passerelle SMS.
 *
 * Cet endpoint est un ENREGISTREMENT A POSTERIORI, best-effort : le mobile l'appelle après avoir
 * lancé l'alerte, et ignore son échec. Il ne doit donc jamais être sur le chemin critique.
 *
 * Le `membre_id` est validé comme appartenant à l'appelant : on ne journalise pas une alerte au
 * nom du membre d'un autre (anti-IDOR).
 */
class AlerteSosController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $userId = $request->user()->id;

        $data = $request->validate([
            'membre_id' => [
                'nullable', 'integer',
                Rule::exists('membres_famille', 'id')->where('user_id', $userId),
            ],
            // Position facultative : GPS refusé, indisponible ou en intérieur. Un SOS sans
            // position vaut mieux qu'un SOS refusé.
            'latitude'         => ['nullable', 'numeric', 'between:-90,90', 'required_with:longitude'],
            'longitude'        => ['nullable', 'numeric', 'between:-180,180', 'required_with:latitude'],
            'precision_metres' => ['nullable', 'integer', 'min:0', 'max:65535'],
            'canal'            => ['required', Rule::in(['appel', 'sms', 'appel_sms'])],
            'contact_prevenu_nom' => ['nullable', 'string', 'max:200'],
            'contact_prevenu_tel' => ['nullable', 'string', 'max:20'],
        ]);

        $alerte = AlerteSos::create([...$data, 'user_id' => $userId]);

        // Trace applicative : en production, c'est ici que partirait la notification au CHU de
        // garde le plus proche (hors périmètre : ni Firebase ni intégration SAMU dans ce projet).
        Log::warning('Alerte SOS déclenchée', [
            'alerte_id' => $alerte->id,
            'user_id'   => $userId,
            'membre_id' => $alerte->membre_id,
            'position'  => $alerte->aUnePosition() ? "{$alerte->latitude},{$alerte->longitude}" : 'inconnue',
        ]);

        return response()->json([
            'message' => 'Alerte enregistrée.',
            'alerte'  => $alerte->only(['id', 'canal', 'declenchee_le']),
        ], 201);
    }

    /** Historique des alertes du compte (transparence : le patient voit ce qui a été enregistré). */
    public function index(Request $request): JsonResponse
    {
        $alertes = AlerteSos::where('user_id', $request->user()->id)
            ->with('membre:id,nom,prenom')
            ->orderByDesc('declenchee_le')
            ->limit(50)
            ->get();

        return response()->json(['alertes' => $alertes]);
    }
}
