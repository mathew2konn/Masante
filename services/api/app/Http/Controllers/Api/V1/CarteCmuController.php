<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\MembreFamille;
use App\Services\CarteCmuService;
use Illuminate\Http\JsonResponse;

/**
 * F2.3 — Carte CMU numérique d'un membre (couche de présentation).
 *
 * Réservé au propriétaire (Policy `view`, anti-IDOR §4.3). Ne renvoie JAMAIS le numéro CMU
 * complet (masqué) ; le code de présentation est gated par le palier « vérifié » (stub dev).
 */
class CarteCmuController extends Controller
{
    public function __construct(private readonly CarteCmuService $cartes)
    {
    }

    public function show(MembreFamille $membre): JsonResponse
    {
        $this->authorize('view', $membre);

        return response()->json(['carte' => $this->cartes->pour($membre)]);
    }
}
