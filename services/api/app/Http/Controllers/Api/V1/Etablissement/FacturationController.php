<?php

namespace App\Http\Controllers\Api\V1\Etablissement;

use App\Http\Controllers\Controller;
use App\Models\CommissionTransaction;
use App\Models\FacturePartenaire;
use App\Models\User;
use App\Services\CommissionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Facturation partenaire, côté établissement (lot 8) — LECTURE SEULE. Aucune logique de calcul ni
 * d'imputation ici (lots 1-3) : ce contrôleur lit, appelle un service existant, sérialise.
 *
 * Aucune route ne porte `{structure}` : le périmètre est TOUJOURS celui de l'utilisateur
 * authentifié (`structure_id`), jamais un identifiant reçu du client — sauf `GET /factures/{facture}`,
 * où l'identifiant de la RESSOURCE elle-même est dans l'URL, vérifié explicitement (anti-IDOR, 404
 * et jamais 403 : un 403 confirmerait à un établissement qu'une facture existe chez un autre).
 */
class FacturationController extends Controller
{
    private const PAR_PAGE = 15;

    public function __construct(private readonly CommissionService $commissions)
    {
    }

    public function tableauBord(Request $request): JsonResponse
    {
        return response()->json($this->commissions->tableauDeBord($this->structureId($request)));
    }

    public function transactions(Request $request): JsonResponse
    {
        return response()->json(
            CommissionTransaction::where('structure_sanitaire_id', $this->structureId($request))
                ->orderByDesc('date_transaction')
                ->paginate(self::PAR_PAGE)
        );
    }

    public function factures(Request $request): JsonResponse
    {
        return response()->json(
            FacturePartenaire::where('structure_sanitaire_id', $this->structureId($request))
                ->orderByDesc('date_emission')
                ->paginate(self::PAR_PAGE)
        );
    }

    public function facture(Request $request, FacturePartenaire $facture): JsonResponse
    {
        abort_unless($facture->structure_sanitaire_id === $this->structureId($request), 404);

        $facture->load('reglements');

        return response()->json($facture);
    }

    /** Périmètre de lecture : structure de l'utilisateur authentifié, jamais un id fourni par lui. */
    private function structureId(Request $request): int
    {
        /** @var User $user */
        $user = $request->user();

        abort_unless(
            $user->hasRole('gestionnaire_etablissement') && $user->structure_id !== null,
            403,
            'Ce compte n\'est rattaché à aucun établissement.'
        );

        return $user->structure_id;
    }
}
