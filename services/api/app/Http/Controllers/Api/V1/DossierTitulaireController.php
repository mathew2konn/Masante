<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreDossierTitulaireRequest;
use App\Models\MembreFamille;
use App\Services\MatriculeService;
use App\Services\Nis\AttributeurNis;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * P6.1 — Dossier de santé du titulaire du compte (CDC_09 §3, ADR-021 §2.1).
 *
 * POURQUOI UN CONTRÔLEUR DÉDIÉ : `MembreController` appartient à P2, validé G5. On n'y touche
 * pas. La création du dossier titulaire est un chemin distinct, avec ses propres règles :
 * identité reprise du compte, hors quota, NIS attribué dans la foulée.
 *
 * POURQUOI PAS À L'INSCRIPTION (variante (c) d'ADR-021) : `membres_famille` exige
 * `date_naissance` et `sexe`, que `RegisterRequest` ne collecte pas. Les demander à
 * l'inscription reviendrait à modifier P1 (validé G5) et à alourdir le tunnel en zone à faible
 * connectivité. Le dossier est donc créé à la complétion du profil, au premier accès au Carnet.
 */
class DossierTitulaireController extends Controller
{
    public function __construct(
        private readonly MatriculeService $matricules,
        private readonly AttributeurNis $nis,
    ) {}

    /**
     * GET /api/v1/membres/titulaire — état du dossier titulaire du compte.
     *
     * C'est le BACKEND qui répond « ce dossier existe ou non » : le mobile ne le déduit jamais
     * (règle de frontière, CDC_01 §0.1).
     */
    public function show(Request $request): JsonResponse
    {
        $dossier = $this->dossierDe($request);

        return response()->json([
            'existe'  => $dossier !== null,
            'membre'  => $dossier,
        ]);
    }

    /**
     * POST /api/v1/membres/titulaire — crée le dossier et attribue le NIS.
     *
     * Idempotent par l'état : si le dossier existe déjà, 409 plutôt qu'un doublon silencieux.
     * L'unicité est de toute façon garantie en base (index UNIQUE + CHECK, ADR-021 §2.2) ; ce
     * contrôle produit simplement une erreur lisible plutôt qu'une violation de contrainte.
     */
    public function store(StoreDossierTitulaireRequest $request): JsonResponse
    {
        if ($this->dossierDe($request) !== null) {
            return response()->json([
                'error' => [
                    'code'    => 'DOSSIER_TITULAIRE_EXISTANT',
                    'message' => 'Votre dossier de santé personnel existe déjà.',
                ],
            ], 409);
        }

        $utilisateur = $request->user();
        $valide      = $request->validated();

        $membre = DB::transaction(function () use ($utilisateur, $valide) {
            $membre = new MembreFamille;

            // Identité reprise du COMPTE, jamais du client (cf. StoreDossierTitulaireRequest).
            $membre->user_id        = $utilisateur->id;
            $membre->nom            = $utilisateur->nom;
            $membre->prenom         = $utilisateur->prenom ?? '';
            $membre->date_naissance = $valide['date_naissance'];
            $membre->sexe           = $valide['sexe'];
            $membre->groupe_sanguin = $valide['groupe_sanguin'] ?? null;
            $membre->matricule_ivs  = $this->matricules->generer();
            $membre->est_titulaire  = true;
            $membre->pays_code      = 'CI';
            $membre->save();

            $this->nis->attribuer($membre, AttributeurNis::MOTIF_CREATION, $utilisateur->id);

            return $membre->fresh();
        });

        return response()->json(['membre' => $membre], 201);
    }

    /** Le dossier titulaire du compte authentifié, ou null. */
    private function dossierDe(Request $request): ?MembreFamille
    {
        return MembreFamille::where('user_id', $request->user()->id)
            ->where('est_titulaire', true)
            ->first();
    }
}
