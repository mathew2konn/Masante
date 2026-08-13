<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Referentiel;
use App\Services\Referentiel\DiffusionReferentiel;
use App\Services\Referentiel\ReferentielException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Diffusion en lecture des référentiels nationaux (CDC_09 §10, P6.3).
 *
 * PUBLIC EN LECTURE, comme `/symptomes` et `/medicaments` le sont déjà : un référentiel national
 * ne contient aucune donnée personnelle, et §10 demande qu'il soit « exposé en lecture à tous les
 * services ». Un référentiel que l'on doit s'authentifier pour lire n'est pas un socle
 * d'interopérabilité.
 *
 * FRONTIÈRE : ce contrôleur ne calcule rien. La version en vigueur, le contenu, l'empreinte, la
 * mise en cache — tout vient du service. Il traduit en HTTP, rien de plus.
 */
class ReferentielController extends Controller
{
    public function __construct(private readonly DiffusionReferentiel $diffusion) {}

    /** GET /api/v1/referentiels — le registre : ce qui est gouverné, et dans quelle version. */
    public function index(): JsonResponse
    {
        $referentiels = Referentiel::query()
            ->orderBy('pays_code')->orderBy('code')
            ->get()
            ->map(fn (Referentiel $r): array => [
                'code'             => $r->code,
                'pays_code'        => $r->pays_code,
                'libelle'          => $r->libelle,
                'role_responsable' => $r->role_responsable,
                'version'          => $r->version_publiee_numero,
                'publiee_le'       => $r->publiee_le?->toIso8601String(),
            ]);

        return response()->json(['referentiels' => $referentiels]);
    }

    /** GET /api/v1/referentiels/{code} — le contenu en vigueur, servi par le cache versionné. */
    public function show(Request $request, string $code): JsonResponse
    {
        return $this->reponse(fn (): array => $this->diffusion->lire($code, $request->query('pays')));
    }

    /**
     * GET /api/v1/referentiels/{code}/versions/{numero} — l'instantané d'une version passée.
     *
     * C'est le chemin qui rend une décision explicable a posteriori : « ce triage s'est appuyé sur
     * symptomes_triage v7 » n'a de valeur que si v7 reste lisible telle qu'elle était.
     */
    public function version(Request $request, string $code, int $numero): JsonResponse
    {
        return $this->reponse(
            fn (): array => $this->diffusion->lireVersion($code, $numero, $request->query('pays'))
        );
    }

    /** Traduit un refus métier en réponse HTTP, en conservant le code porté par l'exception. */
    private function reponse(callable $action): JsonResponse
    {
        try {
            return response()->json($action());
        } catch (ReferentielException $e) {
            return response()->json([
                'error' => ['code' => 'REFERENTIEL_INDISPONIBLE', 'message' => $e->getMessage()],
            ], $e->statut);
        } catch (\InvalidArgumentException $e) {
            // Code hors de la liste blanche `RegistreReferentiels` : 404, jamais 500.
            return response()->json([
                'error' => ['code' => 'REFERENTIEL_INCONNU', 'message' => $e->getMessage()],
            ], 404);
        }
    }
}
