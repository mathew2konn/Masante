<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\MembreFamille;
use App\Services\QrTokenService;
use Illuminate\Http\JsonResponse;

/**
 * Module 2 / étape 2A.3 — Génération des QR Code dynamiques côté patient (Sécurité §5).
 *
 * Le patient génère, pour l'un de SES membres (contrôle d'appartenance via Policy), un token
 * à usage unique valable 10 minutes. La réponse contient le contenu opaque à encoder dans le
 * QR (jamais le matricule) et le délai d'expiration pour afficher un compte à rebours.
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

    /** Génère un QR dynamique pour un membre du compte authentifié. */
    public function generer(MembreFamille $membre): JsonResponse
    {
        $this->authorize('generateQr', $membre);

        return response()->json($this->qr->generer($membre), 201);
    }
}
