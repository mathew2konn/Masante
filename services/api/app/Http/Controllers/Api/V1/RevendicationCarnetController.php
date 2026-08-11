<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Delegation;
use App\Models\MembreFamille;
use App\Services\RevendicationCarnetService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Carnet familial partagé / B — reconnaître un carnet comme le sien.
 *
 * FRONTIÈRE : aucune règle ici. C'est le service qui décide si un carnet est revendicable et qui
 * opère le transfert ; le contrôleur traduit en HTTP.
 */
class RevendicationCarnetController extends Controller
{
    public function __construct(private readonly RevendicationCarnetService $service) {}

    /**
     * GET /api/v1/membres/revendicables
     *
     * Interrogé par le mobile AVANT l'écran de complétion de P6.1 : c'est l'instant qui précède la
     * création d'un doublon, et le seul où la question « est-ce le vôtre ? » a du sens.
     */
    public function index(Request $request): JsonResponse
    {
        $revendicables = $this->service->revendicables($request->user())
            ->map(fn (Delegation $d) => [
                'delegation_id' => $d->id,
                'propose_par'   => [
                    'nom'    => $d->titulaire?->nom,
                    'prenom' => $d->titulaire?->prenom,
                ],
                'membre' => $d->membre,
            ])
            ->values();

        return response()->json(['revendicables' => $revendicables]);
    }

    /**
     * POST /api/v1/membres/{membre}/revendiquer
     *
     * Pas de Policy `view` ici : l'autorisation n'est PAS « je peux lire ce carnet » mais « ce
     * carnet m'a été reconnu comme le mien ». C'est une condition plus étroite, vérifiée par le
     * service — un délégué en lecture simple ne peut rien revendiquer.
     */
    public function store(Request $request, MembreFamille $membre): JsonResponse
    {
        try {
            $membre = $this->service->revendiquer($request->user(), $membre);
        } catch (\RuntimeException $e) {
            return response()->json([
                'error' => [
                    'code'    => 'REVENDICATION_IMPOSSIBLE',
                    'message' => $e->getMessage(),
                ],
            ], 409);
        }

        return response()->json(['membre' => $membre]);
    }
}
