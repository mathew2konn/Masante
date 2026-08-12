<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AppareilPush;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Enregistrement des téléphones pour le push (incrément D1).
 *
 * LE JETON EST RÉAFFECTÉ, JAMAIS EMPILÉ. Expo réattribue un jeton quand une application est
 * réinstallée ou un téléphone revendu. Si l'on se contentait de créer une ligne par appel, deux
 * comptes finiraient par pointer le même appareil — et le nouveau propriétaire recevrait les
 * notifications de santé de l'ancien. `updateOrCreate` sur `jeton_expo` (colonne UNIQUE) fait que
 * le dernier compte à s'enregistrer devient le seul destinataire. C'est une garde de
 * confidentialité, pas une commodité.
 *
 * Le jeton est opaque : le serveur le recopie, ne l'interprète jamais. Sa forme est vérifiée à
 * l'entrée pour écarter une saisie manifestement fausse, pas pour en tirer une information.
 */
class AppareilPushController extends Controller
{
    /** POST /api/v1/appareils-push */
    public function store(Request $request): JsonResponse
    {
        $valide = $request->validate([
            'jeton'      => ['required', 'string', 'max:255', 'regex:/^ExponentPushToken\[[A-Za-z0-9_\-]+\]$/'],
            'plateforme' => ['nullable', 'string', 'in:ios,android'],
        ], [
            'jeton.regex' => 'Jeton de notification invalide.',
        ]);

        $appareil = AppareilPush::updateOrCreate(
            ['jeton_expo' => $valide['jeton']],
            [
                'user_id'    => $request->user()->id,
                'plateforme' => $valide['plateforme'] ?? null,
                'vu_le'      => now(),
                // Un jeton révoqué qui se réenregistre redevient actif : l'application vient d'être
                // réinstallée, ou l'utilisateur s'est reconnecté.
                'revoque_le' => null,
            ],
        );

        return response()->json(['appareil_id' => $appareil->id], 201);
    }

    /**
     * DELETE /api/v1/appareils-push — à la déconnexion.
     *
     * On révoque, on ne supprime pas : `notification_envois` référence l'appareil, et l'historique
     * des envois doit survivre à une déconnexion. Répondre 200 même si le jeton est inconnu — un
     * 404 apprendrait à un tiers qu'un jeton donné est enregistré ailleurs.
     */
    public function destroy(Request $request): JsonResponse
    {
        $valide = $request->validate(['jeton' => ['required', 'string', 'max:255']]);

        AppareilPush::where('jeton_expo', $valide['jeton'])
            ->where('user_id', $request->user()->id)
            ->whereNull('revoque_le')
            ->update(['revoque_le' => now()]);

        return response()->json(['message' => 'Appareil retiré.']);
    }
}
