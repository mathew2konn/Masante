<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\MembreFamille;
use App\Services\FicheVitaleService;
use Illuminate\Http\JsonResponse;

/**
 * Module 5 / 5.1 — Fiche vitale d'urgence d'un membre (CdC FN2).
 *
 * Le TITULAIRE (ou un délégué actif) récupère la fiche vitale pour la mettre en cache chiffré sur
 * son téléphone. C'est ce cache local que l'application affichera ensuite SANS connexion et SANS
 * authentification, pour qu'un secouriste puisse agir sur un patient inconscient (§5.5.1 FN2).
 *
 * L'endpoint, lui, reste protégé : seul le propriétaire du carnet constitue le cache. Aucun
 * `no-store` ici — au contraire, la fiche est faite pour être conservée hors ligne.
 */
class FicheVitaleController extends Controller
{
    public function __construct(private readonly FicheVitaleService $fiches)
    {
    }

    public function show(MembreFamille $membre): JsonResponse
    {
        $this->authorize('view', $membre);

        return response()->json(['fiche_vitale' => $this->fiches->pour($membre)]);
    }
}
