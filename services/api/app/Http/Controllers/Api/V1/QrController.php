<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\MembreFamille;
use App\Services\QrTokenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Module 2 / étape 2A.3 — Génération des QR Code dynamiques côté patient (Sécurité §5).
 *
 * Le titulaire génère, pour l'un de SES membres, un token à usage unique valable 10 minutes.
 * B3 (voie 3) : un DÉLÉGUÉ actif peut aussi générer le QR d'un membre délégué (contrôle via Policy) ;
 * la génération est alors tracée sur le token et notifiée au titulaire (stub).
 *
 * La consommation (scan) est une action d'agent : son endpoint sera ajouté au Module 3,
 * une fois les `agents_garde` introduits. La logique de consommation existe déjà dans
 * QrTokenService (validerEtConsommer) et est couverte par les tests.
 */
class QrController extends Controller
{
    public function __construct(private readonly QrTokenService $qr)
    {
    }

    /** Génère un QR dynamique pour un membre : par son propriétaire ou par un délégué actif. */
    public function generer(MembreFamille $membre, Request $request): JsonResponse
    {
        $this->authorize('generateQr', $membre);

        // Générateur non-propriétaire = délégué (la Policy a déjà validé la délégation active).
        $delegueId = $membre->user_id === $request->user()->id ? null : $request->user()->id;

        if ($delegueId !== null) {
            // Traçabilité (Note_Continuite chap. 4.2) : notification au titulaire — stub (push au M3).
            Log::info('QR généré par un délégué', [
                'delegue_id'   => $delegueId,
                'membre_id'    => $membre->id,
                'titulaire_id' => $membre->user_id,
            ]);
        }

        return response()->json($this->qr->generer($membre, $delegueId), 201);
    }
}
