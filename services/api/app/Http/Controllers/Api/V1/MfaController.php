<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\MfaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * P1 (Identité) — Gestion du second facteur par l'utilisateur connecté (auth:sanctum).
 *
 * Enrôlement → confirmation → (plus tard) exigence à la connexion gouvernée par config('mfa.enforce').
 * Aucune règle métier ici : le contrôleur délègue toute décision au MfaService (frontière CDC_01 §0.1).
 */
class MfaController extends Controller
{
    public function __construct(private readonly MfaService $mfa)
    {
    }

    /** Démarre l'enrôlement TOTP : renvoie le secret + l'URI otpauth:// (QR affiché par le client). */
    public function enroll(Request $request): JsonResponse
    {
        return response()->json($this->mfa->enroll($request->user()), 201);
    }

    /** Confirme l'enrôlement avec le premier code de l'application d'authentification. */
    public function confirm(Request $request): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'string']]);
        $this->mfa->confirm($request->user(), $data['code']);

        return response()->json(['message' => 'Second facteur activé.']);
    }

    /** Désactive le(s) facteur(s) du compte (retour à l'authentification simple). */
    public function destroy(Request $request): JsonResponse
    {
        $request->user()->mfaFacteurs()->delete();

        return response()->json(['message' => 'Second facteur désactivé.']);
    }

    /** État MFA du compte (le front l'AFFICHE ; il ne le déduit ni ne le calcule). */
    public function status(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'enforce' => (bool) config('mfa.enforce'),
            'oblige_pour_ce_compte' => $user->hasAnyRole((array) config('mfa.roles_obligatoires')),
            'facteur_confirme' => $this->mfa->facteurConfirme($user) !== null,
            'doit_configurer' => $this->mfa->doitConfigurer($user),
        ]);
    }
}
