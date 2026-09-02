<?php

namespace App\Http\Controllers\Api\V1\Backoffice;

use App\Http\Controllers\Controller;
use App\Models\FacturePartenaire;
use App\Services\RecouvrementPartenaireService;
use App\Support\MoyenReglement;
use DateTimeImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Facturation partenaire, côté back-office MaSanté (lot 8). Seule route : enregistrer un règlement
 * reçu — JAMAIS accessible à l'établissement lui-même (interdiction n°2 du lot 8, risque de fraude
 * direct sur son propre recouvrement).
 *
 * `{facture}` dans l'URL sert à retrouver la STRUCTURE concernée (l'agent back-office regarde une
 * facture précise pour déclencher l'action) — il ne cible pas cette facture pour l'imputation :
 * `RecouvrementPartenaireService::enregistrerReglement()` impute toujours la plus ancienne facture
 * ouverte d'abord (lot 1), jamais celle que désignerait un formulaire. Ce contrôleur ne contient
 * aucune logique d'imputation propre.
 */
class FacturationController extends Controller
{
    public function __construct(private readonly RecouvrementPartenaireService $recouvrement)
    {
    }

    public function enregistrerReglement(Request $request, FacturePartenaire $facture): JsonResponse
    {
        abort_unless($request->user()->can('recouvrement.manage'), 403);

        $donnees = $request->validate([
            'montant' => ['required', 'integer', 'min:1'],
            'moyen' => ['required', 'string', Rule::in(array_column(MoyenReglement::cases(), 'value'))],
            'reference_externe' => ['nullable', 'string'],
            'date_reglement' => ['nullable', 'date'],
        ]);

        $dateReglement = isset($donnees['date_reglement'])
            ? new DateTimeImmutable($donnees['date_reglement'])
            : new DateTimeImmutable();

        $resultat = $this->recouvrement->enregistrerReglement(
            $facture->structure_sanitaire_id,
            $donnees['montant'],
            $donnees['moyen'],
            $donnees['reference_externe'] ?? null,
            $dateReglement,
        );

        return response()->json($resultat, 201);
    }
}
