<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\StructureSanitaire;
use App\Services\StructureService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Module 3 / étape 3A.1 — Annuaire géolocalisé des structures sanitaires (F3.1→F3.5, F3.8).
 *
 * Endpoints PUBLICS (lecture seule) : trouver une structure / un hôpital ne nécessite aucune
 * formalité d'identité (doc Identification §accès léger). Aucune donnée médicale ici.
 */
class StructureController extends Controller
{
    public function __construct(private readonly StructureService $structures)
    {
    }

    /** Liste filtrée + géolocalisée des structures (F3.1/F3.2/F3.3). */
    public function index(Request $request): JsonResponse
    {
        $filtres = $request->validate([
            'type'       => ['nullable', 'in:chu,chr,clinique_privee,cabinet,pharmacie,laboratoire,centre_sante'],
            'commune'    => ['nullable', 'string', 'max:100'],
            'specialite' => ['nullable', 'string', 'max:100'],
            'statut'     => ['nullable', 'in:disponible,complet,disponible_apres_14h,ferme,inconnu'],
            'tarif_max'  => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'q'          => ['nullable', 'string', 'max:200'],
            'lat'        => ['nullable', 'numeric', 'between:-90,90', 'required_with:lng'],
            'lng'        => ['nullable', 'numeric', 'between:-180,180', 'required_with:lat'],
            'rayon_km'   => ['nullable', 'numeric', 'min:0', 'max:200'],
        ]);

        return response()->json([
            'structures' => $this->structures->rechercher($filtres),
        ]);
    }

    /** Fiche détaillée d'une structure (F3.5). */
    public function show(StructureSanitaire $structure): JsonResponse
    {
        return response()->json([
            'structure' => $this->structures->fiche($structure),
        ]);
    }

    /** Pharmacies de garde du jour, par proximité (F3.8). */
    public function pharmaciesGarde(Request $request): JsonResponse
    {
        $filtres = $request->validate([
            'lat'      => ['nullable', 'numeric', 'between:-90,90', 'required_with:lng'],
            'lng'      => ['nullable', 'numeric', 'between:-180,180', 'required_with:lat'],
            'rayon_km' => ['nullable', 'numeric', 'min:0', 'max:200'],
        ]);

        return response()->json([
            'pharmacies' => $this->structures->pharmaciesDeGarde($filtres),
        ]);
    }
}
