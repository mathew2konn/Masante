<?php

namespace App\Http\Controllers\Api\V1\Integration;

use App\Http\Controllers\Controller;
use App\Services\Integration\AuthentificationClientApi;
use App\Services\Integration\IngestionStockOfficine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * P11.2 — Point d'entrée d'ingestion du stock d'une officine (CDC_11 §7.7, ADR-030).
 *
 * « Si la pharmacie possède déjà un logiciel (caisse, stock, ERP), ce logiciel envoie
 * automatiquement stock, prix, disponibilité. **Le pharmacien n'a rien à ressaisir.** »
 *
 * Authentifié par **clé de client + signature HMAC**, jamais par Sanctum : un logiciel n'a pas de
 * session, et un jeton de citoyen n'a rien à faire ici. C'est la troisième population
 * d'authentification du projet, et elle est tenue séparée des deux autres (ADR-030 : « trois
 * populations d'auth, jamais étirées en une »).
 */
class StockOfficineController extends Controller
{
    /** Un envoi reste lisible et rejouable : au-delà, le partenaire découpe. */
    private const LIGNES_MAX = 1000;

    public function __construct(
        private readonly AuthentificationClientApi $authentification,
        private readonly IngestionStockOfficine $ingestion,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        try {
            $client = $this->authentification->authentifier($request, IngestionStockOfficine::DOMAINE);
        } catch (RuntimeException $e) {
            // Le motif précis est JOURNALISÉ, jamais renvoyé : un attaquant ne doit rien
            // apprendre de la raison exacte du refus (même règle que le principal signé).
            Log::warning('Ingestion refusée', [
                'motif' => $e->getMessage(),
                'client' => $request->header('X-MaSante-Client'),
                'ip' => $request->ip(),
            ]);

            abort(Response::HTTP_UNAUTHORIZED, 'Authentification refusée.');
        }

        $valide = $request->validate([
            'lignes' => ['required', 'array', 'min:1', 'max:'.self::LIGNES_MAX],
            'lignes.*.reference' => ['required', 'string', 'max:120'],
            'lignes.*.code_masante' => ['nullable', 'string', 'max:20'],
            'lignes.*.quantite' => ['nullable', 'integer'],
            'lignes.*.prix_cfa' => ['nullable', 'integer'],
            'lignes.*.disponible' => ['nullable', 'boolean'],
        ]);

        $idempotencyKey = trim((string) $request->header('Idempotency-Key', '')) ?: null;

        $resultat = $this->ingestion->ingerer($client, $valide['lignes'], $idempotencyKey);
        $journal = $resultat['journal'];

        // 200 et non 207 : l'envoi a bien été traité, et le rapport dit ligne par ligne ce qui
        // ne l'a pas été. Un 207 ferait croire à une erreur de transport là où il s'agit de
        // données que le partenaire doit corriger chez lui.
        return response()->json([
            'recues' => $journal->lignes_recues,
            'acceptees' => $journal->lignes_acceptees,
            'refusees' => $journal->lignes_refusees,
            // Toujours présent, même vide : une clé absente et un tableau vide ne se ressemblent
            // pas côté partenaire (précédent des permissions de P11.0).
            'refus' => $journal->refus_json ?? [],
            'rejeu' => $resultat['rejeu'],
        ]);
    }
}
