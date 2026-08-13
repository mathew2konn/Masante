<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Referentiel;
use App\Models\ReferentielJournal;
use App\Models\ReferentielVersion;
use App\Services\Referentiel\JournalReferentiel;
use App\Services\Referentiel\ReferentielException;
use App\Services\Referentiel\ServiceGouvernanceReferentiel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Gouvernance des référentiels nationaux — cycle de vie §10 (P6.3).
 *
 * FRONTIÈRE : aucune décision ici. L'habilitation, le quatre-yeux, l'anti-substitution, les
 * contrôles qualité, la numérotation des versions et l'audit vivent dans
 * `ServiceGouvernanceReferentiel`. Ce contrôleur valide la forme de la requête et traduit en HTTP.
 *
 * Les codes de retour reprennent ceux portés par l'exception métier : 403 (non habilité),
 * 404 (référentiel inconnu), 409 (état incompatible : proposition déjà en cours, auteur qui se
 * valide, contenu modifié depuis la relecture), 422 (contrôles qualité).
 *
 * `contenu_json` est masqué des réponses de gouvernance : l'instantané peut peser lourd (le
 * référentiel des médicaments, en P6.6, fera des milliers de lignes) et l'appelant vient
 * précisément de soumettre ce contenu. Il se relit par l'endpoint de diffusion, fait pour ça et
 * servi par le cache.
 */
class GouvernanceReferentielController extends Controller
{
    public function __construct(
        private readonly ServiceGouvernanceReferentiel $gouvernance,
        private readonly JournalReferentiel $journal,
    ) {}

    /** GET /api/v1/referentiels/{code}/versions — l'historique, sans les instantanés. */
    public function versions(Request $request, string $code): JsonResponse
    {
        return $this->reponse(function () use ($request, $code): array {
            $referentiel = $this->referentiel($code, $request);

            return [
                'referentiel' => ['code' => $referentiel->code, 'pays_code' => $referentiel->pays_code],
                // Le contenu est délibérément absent : cette liste sert à suivre le cycle de vie,
                // pas à recharger N instantanés. Une version précise se lit par son propre endpoint.
                'versions' => $referentiel->versions()->orderByDesc('numero')->get()
                    ->map(fn (ReferentielVersion $v): array => [
                        'numero'         => $v->numero,
                        'statut'         => $v->statut,
                        'motif'          => $v->motif,
                        'nb_entrees'     => $v->nb_entrees,
                        'empreinte'      => $v->empreinte,
                        'propose_par'    => $v->auteur?->only(['id', 'nom', 'prenom']),
                        'propose_le'     => $v->propose_le?->toIso8601String(),
                        'decide_par'     => $v->decideur?->only(['id', 'nom', 'prenom']),
                        'decide_le'      => $v->decide_le?->toIso8601String(),
                        'motif_decision' => $v->motif_decision,
                    ]),
            ];
        });
    }

    /**
     * GET /api/v1/referentiels/{code}/controle — l'état de qualité du contenu ACTUEL.
     *
     * Détection seule : cet appel ne corrige jamais rien et ne publie rien. Il répond à « puis-je
     * proposer ce contenu ? » avant de le proposer.
     */
    public function controle(string $code): JsonResponse
    {
        return $this->reponse(fn (): array => $this->gouvernance->controler($code));
    }

    /** POST /api/v1/referentiels/{code}/propositions — soumettre le contenu actuel à décision. */
    public function proposer(Request $request, string $code): JsonResponse
    {
        $valide = $request->validate([
            'motif' => ['required', 'string', 'min:10', 'max:500'],
            'pays'  => ['nullable', 'string', 'size:2'],
        ]);

        return $this->reponse(fn (): array => [
            'version' => $this->gouvernance->proposer(
                $code,
                $valide['pays'] ?? config('referentiels.pays_defaut'),
                $request->user(),
                $valide['motif'],
            )->makeHidden('contenu_json'),
        ], 201);
    }

    /** POST /api/v1/referentiels/{code}/publication — valider la proposition en cours. */
    public function publier(Request $request, string $code): JsonResponse
    {
        $valide = $this->valideDecision($request);

        return $this->reponse(fn (): array => [
            'version' => $this->gouvernance->publier(
                $code,
                $valide['pays'] ?? config('referentiels.pays_defaut'),
                $request->user(),
                $valide['motif'],
            )->makeHidden('contenu_json'),
        ]);
    }

    /** POST /api/v1/referentiels/{code}/rejet — refuser la proposition en cours. */
    public function rejeter(Request $request, string $code): JsonResponse
    {
        $valide = $this->valideDecision($request);

        return $this->reponse(fn (): array => [
            'version' => $this->gouvernance->rejeter(
                $code,
                $valide['pays'] ?? config('referentiels.pays_defaut'),
                $request->user(),
                $valide['motif'],
            )->makeHidden('contenu_json'),
        ]);
    }

    /**
     * GET /api/v1/referentiels-journal — le journal d'audit et l'état de la chaîne.
     *
     * `chaine.intacte` est recalculée à chaque appel : c'est la seule façon de savoir si une
     * entrée a été supprimée ou modifiée en base. Une chaîne rompue est signalée, jamais réparée —
     * réparer un audit reviendrait à effacer ce qu'il était censé prouver.
     */
    public function journal(Request $request): JsonResponse
    {
        $entrees = ReferentielJournal::query()
            ->when($request->query('code'), fn ($q, $code) => $q->where('referentiel_code', $code))
            ->orderByDesc('id')
            ->limit((int) $request->query('limite', 100))
            ->get()
            ->map(fn (ReferentielJournal $e): array => [
                'id'               => $e->id,
                'referentiel_code' => $e->referentiel_code,
                'pays_code'        => $e->pays_code,
                'version_numero'   => $e->version_numero,
                'action'           => $e->action,
                'acteur'           => $e->acteur_nom,
                'details'          => $e->details_json,
                'empreinte'        => $e->empreinte,
                'cree_le'          => $e->cree_le->toIso8601String(),
            ]);

        return response()->json([
            'chaine'  => $this->journal->verifierChaine(),
            'entrees' => $entrees,
        ]);
    }

    /** @return array{motif: string, pays: ?string} */
    private function valideDecision(Request $request): array
    {
        return $request->validate([
            // Une décision sans motif est une décision qu'on ne saura pas expliquer dans six mois.
            'motif' => ['required', 'string', 'min:10', 'max:500'],
            'pays'  => ['nullable', 'string', 'size:2'],
        ]) + ['pays' => null];
    }

    private function referentiel(string $code, Request $request): Referentiel
    {
        $pays = $request->query('pays') ?? config('referentiels.pays_defaut');

        $referentiel = Referentiel::query()->where('code', $code)->where('pays_code', $pays)->first();

        if ($referentiel === null) {
            throw new ReferentielException("Le référentiel « {$code} » n'est pas enregistré.", 404);
        }

        return $referentiel;
    }

    private function reponse(callable $action, int $succes = 200): JsonResponse
    {
        try {
            return response()->json($action(), $succes);
        } catch (ReferentielException $e) {
            return response()->json([
                'error' => [
                    'code'    => 'REFERENTIEL_REFUS',
                    'message' => $e->getMessage(),
                    'details' => $e->details,
                ],
            ], $e->statut);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'error' => ['code' => 'REFERENTIEL_INCONNU', 'message' => $e->getMessage()],
            ], 404);
        }
    }
}
