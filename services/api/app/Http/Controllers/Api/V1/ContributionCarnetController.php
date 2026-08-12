<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Contribution;
use App\Models\MembreFamille;
use App\Services\ContributionCarnetService;
use App\Support\RegistreSectionsCarnet;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Carnet familial partagé / C — contributions au brouillon.
 *
 * FRONTIÈRE : tout le jugement (qui peut contribuer, qui peut décider, ce que devient une
 * contribution validée) est dans `ContributionCarnetService`. Ici, on traduit en HTTP.
 */
class ContributionCarnetController extends Controller
{
    public function __construct(private readonly ContributionCarnetService $service) {}

    /**
     * GET /api/v1/membres/{membre}/contributions — ce qui a été proposé sur ce carnet.
     *
     * Gardé par la Policy `view` : qui peut lire le carnet voit ses brouillons. C'est voulu —
     * un fait médical non validé reste un fait médical, et le cacher au soignant suivant serait
     * dangereux (plan G1 §3).
     */
    public function index(Request $request, MembreFamille $membre): JsonResponse
    {
        $this->authorize('view', $membre);

        return response()->json([
            'contributions' => $this->service->pourLeCarnet($request->user(), $membre),
            'sections_ouvertes' => RegistreSectionsCarnet::ouvertesAuxContributions(),
        ]);
    }

    /**
     * POST /api/v1/membres/{membre}/contributions — déposer une proposition.
     *
     * Pas de `authorize()` : la condition n'est pas « je peux lire » mais « j'ai le droit de
     * contribuer », plus étroite, vérifiée par le service.
     */
    public function store(Request $request, MembreFamille $membre): JsonResponse
    {
        $valide = $request->validate([
            'section' => ['required', 'string', 'max:40'],
            'donnees' => ['required', 'array'],
        ]);

        try {
            $contribution = $this->service->deposer(
                $request->user(),
                $membre,
                $valide['section'],
                $valide['donnees'],
            );
        } catch (\RuntimeException $e) {
            return response()->json([
                'error' => ['code' => 'CONTRIBUTION_IMPOSSIBLE', 'message' => $e->getMessage()],
            ], 403);
        }

        return response()->json(['contribution' => $contribution], 201);
    }

    /** GET /api/v1/contributions — la file du responsable : ce qu'on lui demande d'arbitrer. */
    public function enAttente(Request $request): JsonResponse
    {
        return response()->json([
            'contributions' => $this->service->enAttentePour($request->user()),
        ]);
    }

    /** POST /api/v1/contributions/{contribution}/valider */
    public function valider(Request $request, Contribution $contribution): JsonResponse
    {
        return $this->decider(
            fn () => $this->service->valider($request->user(), $contribution)
        );
    }

    /** POST /api/v1/contributions/{contribution}/rejeter */
    public function rejeter(Request $request, Contribution $contribution): JsonResponse
    {
        $valide = $request->validate([
            'motif' => ['sometimes', 'nullable', 'string', 'max:500'],
        ]);

        return $this->decider(
            fn () => $this->service->rejeter($request->user(), $contribution, $valide['motif'] ?? null)
        );
    }

    /** Traduit un refus du service en 409 — l'état, pas l'authentification, fait obstacle. */
    private function decider(callable $action): JsonResponse
    {
        try {
            return response()->json(['contribution' => $action()]);
        } catch (\RuntimeException $e) {
            return response()->json([
                'error' => ['code' => 'DECISION_IMPOSSIBLE', 'message' => $e->getMessage()],
            ], 409);
        }
    }
}
