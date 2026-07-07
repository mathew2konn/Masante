<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreDelegationRequest;
use App\Models\Delegation;
use App\Models\MembreFamille;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Phase B / B3 — Délégation d'accès (voie 3, Note_Continuite chap. 4).
 *
 * Le titulaire invite un délégué (par téléphone) sur l'un de SES membres ; le délégué accepte
 * depuis son app ; le titulaire (ou le délégué) peut révoquer/refuser. Le droit se limite à la
 * génération du QR (portée par MembreFamillePolicy::generateQr) — jamais la lecture/écriture du dossier.
 */
class DelegationController extends Controller
{
    /** Liste des délégations accordées (comme titulaire) et reçues (comme délégué), actives/en attente. */
    public function index(Request $request): JsonResponse
    {
        $uid = $request->user()->id;

        $accordees = Delegation::where('titulaire_user_id', $uid)
            ->whereNull('revoquee_at')
            ->with(['membre:id,prenom,nom', 'delegue:id,prenom,nom,telephone'])
            ->latest()
            ->get();

        $recues = Delegation::where('delegue_user_id', $uid)
            ->whereNull('revoquee_at')
            ->with(['membre:id,prenom,nom', 'titulaire:id,prenom,nom,telephone'])
            ->latest()
            ->get();

        return response()->json(['accordees' => $accordees, 'recues' => $recues]);
    }

    /** Invite un délégué (par téléphone) sur un membre du titulaire. */
    public function store(StoreDelegationRequest $request, MembreFamille $membre): JsonResponse
    {
        $this->authorize('update', $membre); // le membre appartient au titulaire (anti-IDOR).
        $titulaire = $request->user();

        // Gate « titulaire vérifié » (chap. 4.2) — dormant tant que le flag est faux (dev).
        if (config('masante.delegation.exiger_titulaire_verifie') && ! $titulaire->compteEstVerifie()) {
            abort(403, 'Un compte vérifié (CMU/CNI) est requis pour déléguer l\'accès.');
        }

        $delegue = User::where('telephone', $request->validated()['telephone'])->first();
        abort_if($delegue === null, 422, 'Aucun compte MaSanté associé à ce numéro.');
        abort_if(! $delegue->telephoneEstVerifie(), 422, 'Le compte associé à ce numéro n\'est pas encore vérifié.');
        abort_if($delegue->id === $titulaire->id, 422, 'Vous ne pouvez pas vous déléguer un de vos propres membres.');

        $existante = Delegation::where('delegue_user_id', $delegue->id)
            ->where('membre_id', $membre->id)
            ->first();

        if ($existante && $existante->revoquee_at === null) {
            abort(422, $existante->acceptee_at === null
                ? 'Une invitation est déjà en attente pour ce proche.'
                : 'Ce proche est déjà délégué pour ce membre.');
        }

        // Réutilise une éventuelle ligne révoquée (contrainte UNIQUE délégué+membre) en la réinitialisant.
        $delegation = Delegation::updateOrCreate(
            ['delegue_user_id' => $delegue->id, 'membre_id' => $membre->id],
            [
                'titulaire_user_id' => $titulaire->id,
                'droits'            => 'qr_generation',
                'invitee_at'        => now(),
                'acceptee_at'       => null,
                'revoquee_at'       => null,
            ],
        );

        Log::info('Invitation de délégation envoyée', [
            'titulaire_id' => $titulaire->id,
            'delegue_id'   => $delegue->id,
            'membre_id'    => $membre->id,
        ]); // stub notification au délégué (push au M3).

        return response()->json([
            'delegation' => $delegation->load(['membre:id,prenom,nom', 'delegue:id,prenom,nom,telephone']),
        ], 201);
    }

    /** Le délégué accepte l'invitation (depuis son app). */
    public function accepter(Request $request, Delegation $delegation): JsonResponse
    {
        abort_if($delegation->delegue_user_id !== $request->user()->id, 403, 'Action non autorisée.');
        abort_if($delegation->revoquee_at !== null, 422, 'Cette délégation a été révoquée.');

        if ($delegation->acceptee_at === null) {
            $delegation->update(['acceptee_at' => now()]);
        }

        return response()->json([
            'delegation' => $delegation->load(['membre:id,prenom,nom', 'titulaire:id,prenom,nom,telephone']),
        ]);
    }

    /** Révoque (titulaire) ou refuse (délégué) la délégation — effet immédiat. */
    public function destroy(Request $request, Delegation $delegation): JsonResponse
    {
        $uid = $request->user()->id;
        abort_unless(
            $delegation->titulaire_user_id === $uid || $delegation->delegue_user_id === $uid,
            403,
            'Action non autorisée.',
        );

        if ($delegation->revoquee_at === null) {
            $delegation->update(['revoquee_at' => now()]);
        }

        return response()->json(['message' => 'Délégation révoquée.']);
    }
}
