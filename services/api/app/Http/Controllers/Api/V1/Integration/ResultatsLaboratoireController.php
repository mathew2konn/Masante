<?php

namespace App\Http\Controllers\Api\V1\Integration;

use App\Http\Controllers\Controller;
use App\Services\Integration\AuthentificationClientApi;
use App\Services\Integration\IngestionResultatsLaboratoire;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * B5-c (L10 réécrit) — Point d'entrée d'ingestion des résultats d'un automate biologique.
 *
 * Même authentification (clé + HMAC sur le corps brut, jamais Sanctum) que
 * {@see StockOfficineController} (P11.2) — c'est la troisième population d'authentification du
 * projet, ouverte à un second domaine (`resultats_laboratoire`, `ClientApi::DOMAINES`).
 */
class ResultatsLaboratoireController extends Controller
{
    /** Même borne que l'ingestion du stock (P11.2) : un envoi reste lisible et rejouable. */
    private const LIGNES_MAX = 1000;

    public function __construct(
        private readonly AuthentificationClientApi $authentification,
        private readonly IngestionResultatsLaboratoire $ingestion,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        try {
            $client = $this->authentification->authentifier($request, IngestionResultatsLaboratoire::DOMAINE);
        } catch (RuntimeException $e) {
            Log::warning('Ingestion résultats laboratoire refusée', [
                'motif' => $e->getMessage(),
                'client' => $request->header('X-MaSante-Client'),
                'ip' => $request->ip(),
            ]);

            abort(Response::HTTP_UNAUTHORIZED, 'Authentification refusée.');
        }

        $valide = $request->validate([
            'automate_id' => ['required', 'integer'],
            'resultats' => ['required', 'array', 'min:1', 'max:'.self::LIGNES_MAX],
            'resultats.*.identifiant_prelevement' => ['required', 'string', 'max:20'],
            'resultats.*.valeurs' => ['required', 'array', 'min:1'],
            'resultats.*.valeurs.*.parametre' => ['required', 'string', 'max:200'],
            'resultats.*.valeurs.*.valeur' => ['required', 'string', 'max:120'],
            'resultats.*.valeurs.*.unite' => ['nullable', 'string', 'max:40'],
            'resultats.*.valeurs.*.analyse_id' => ['nullable', 'integer'],
        ]);

        $idempotencyKey = trim((string) $request->header('Idempotency-Key', '')) ?: null;

        try {
            $resultat = $this->ingestion->ingerer(
                $client, (int) $valide['automate_id'], $valide['resultats'], $idempotencyKey,
            );
        } catch (ValidationException $e) {
            return response()->json(['message' => $e->getMessage(), 'errors' => $e->errors()], 422);
        }

        $journal = $resultat['journal'];

        return response()->json([
            'recus' => $journal->lignes_recues,
            'acceptes' => $journal->lignes_acceptees,
            'refuses' => $journal->lignes_refusees,
            'refus' => $journal->refus_json ?? [],
            'rejeu' => $resultat['rejeu'],
        ]);
    }
}
