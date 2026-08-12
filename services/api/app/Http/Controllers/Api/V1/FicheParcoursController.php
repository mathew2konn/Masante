<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\MembreFamille;
use App\Services\ServiceFicheParcours;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Fiche de parcours d'un carnet (carnet familial partagé, incrément D2).
 *
 * FRONTIÈRE : ce contrôleur ne fait que traduire du HTTP. Le regroupement des lignes d'audit en
 * visites, la résolution des identités, la distinction entre lien certain et rapprochement possible
 * vivent dans {@see ServiceFicheParcours}.
 *
 * ANTI-IDOR : la garde est `viewParcours`, capacité introduite par D2 — toute la famille consulte
 * (propriétaire, délégués en lecture, second responsable désigné), mais la DÉCISION sur une
 * contribution reste aux seuls responsables, dans `ContributionCarnetService`. Le journal d'accès
 * BRUT (`viewAcces`), lui, reste propriétaire-seul : il porte l'adresse IP et l'intégralité des
 * lectures familiales.
 */
class FicheParcoursController extends Controller
{
    public function __construct(private readonly ServiceFicheParcours $service) {}

    /**
     * GET /api/v1/membres/{membre}/parcours — ce qui s'est passé sur ce carnet.
     *
     * `depuis` permet de remonter plus loin que la fenêtre par défaut (une donnée de configuration,
     * jamais une constante enfouie). Une date future n'est pas une erreur du client mais n'a aucun
     * sens : elle est ignorée au profit de la fenêtre normale.
     */
    public function show(Request $request, MembreFamille $membre): JsonResponse
    {
        $this->authorize('viewParcours', $membre);

        $valide = $request->validate([
            'depuis' => ['sometimes', 'date'],
        ]);

        $depuis = isset($valide['depuis']) ? Carbon::parse($valide['depuis']) : null;

        if ($depuis !== null && $depuis->isFuture()) {
            $depuis = null;
        }

        return response()->json($this->service->pour($membre, $depuis));
    }
}
