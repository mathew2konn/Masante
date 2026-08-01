<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\BesoinSang;
use App\Models\DonneurSang;
use App\Models\MembreFamille;
use App\Services\DonSangService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Module 5 / 5.7 — Don de sang, côté patient (CdC FN6).
 *
 * Les CENTRES DE COLLECTE n'ont pas d'endpoint ici : ce sont des structures de l'annuaire portant un
 * service de spécialité `don_sang`. Le mobile les obtient par
 * `GET /v1/structures?specialite=don_sang&lat=&lng=` — la recherche géolocalisée du Module 3, déjà
 * triée par proximité. Créer un second annuaire aurait dupliqué la carte, les fiches et l'admin.
 *
 * Les BESOINS sont publics (« voir les groupes sanguins les plus demandés » : c'est un appel au don,
 * il n'a de sens que largement visible). Les ALERTES, elles, sont personnelles : le serveur ne
 * renvoie que les urgences auxquelles les membres donneurs de CE compte peuvent réellement répondre.
 */
class DonSangController extends Controller
{
    public function __construct(private readonly DonSangService $dons)
    {
    }

    /**
     * Les groupes les plus demandés, en cours (public). Trié : les urgences d'abord.
     * Filtrable par commune — un appel au don n'a d'intérêt que s'il est atteignable.
     */
    public function besoins(Request $request): JsonResponse
    {
        $filtres = $request->validate(['commune' => ['nullable', 'string', 'max:100']]);

        $besoins = BesoinSang::enCours()
            ->when(
                $filtres['commune'] ?? null,
                fn ($q, $commune) => $q->whereHas('structure', fn ($s) => $s->where('commune', $commune)),
            )
            ->with('structure:id,nom,commune,adresse,telephone,latitude,longitude')
            // Les urgences d'abord. `CASE` et non `FIELD()` : cette dernière est propre à MySQL, et
            // la suite de tests tourne sur SQLite (même piège que les CHECK d'enum, cf. 5.5).
            ->orderByRaw("CASE WHEN niveau = 'urgent' THEN 0 ELSE 1 END")
            ->orderByDesc('date_debut')
            ->get();

        return response()->json(['besoins' => $besoins]);
    }

    /**
     * Ce qui concerne CE compte : ses membres donneurs (avec leur état d'éligibilité) et les
     * urgences auxquelles il peut répondre. Ciblage entièrement serveur (comme FN3).
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $donneurs = $this->dons->donneursDe($user)->map(fn (DonneurSang $d) => [
            'id'                 => $d->id,
            'membre_id'          => $d->membre_id,
            'nom'                => trim(($d->membre?->prenom ?? '').' '.($d->membre?->nom ?? '')),
            'groupe_sanguin'     => $d->membre?->groupe_sanguin,
            'inscrit_at'         => $d->inscrit_at?->toIso8601String(),
            'dernier_don_at'     => $d->dernier_don_at?->toDateString(),
            'peut_donner'        => $this->dons->peutDonnerMaintenant($d),
            'jours_avant_don'    => $this->dons->joursAvantProchainDon($d),
        ]);

        return response()->json([
            'donneurs' => $donneurs->values(),
            'alertes'  => $this->dons->alertesPour($user)->values(),
            'regles'   => [
                'age_min'     => (int) config('masante.don_sang.age_min'),
                'age_max'     => (int) config('masante.don_sang.age_max'),
                'delai_jours' => (int) config('masante.don_sang.delai_jours'),
            ],
        ]);
    }

    /**
     * Inscrit un membre comme donneur volontaire. C'est un CONSENTEMENT : il rend le groupe sanguin
     * du membre (déjà au carnet) interrogeable pour l'alerter — d'où l'acte explicite, membre par
     * membre, et la Policy `update` (on n'inscrit que SES membres).
     */
    public function inscrire(MembreFamille $membre): JsonResponse
    {
        $this->authorize('update', $membre);

        $eligibilite = $this->dons->eligibilite($membre);

        if (! $eligibilite['eligible']) {
            throw ValidationException::withMessages(['membre' => $eligibilite['motif']]);
        }

        $donneur = $this->dons->inscrire($membre);

        return response()->json(['donneur' => $donneur], 201);
    }

    /** Déclare un don effectué : ouvre le délai de carence (le donneur n'est plus alerté d'ici là). */
    public function declarerDon(Request $request, MembreFamille $membre): JsonResponse
    {
        $this->authorize('update', $membre);

        $donnees = $request->validate([
            'date' => ['required', 'date', 'before_or_equal:today', 'after:'.now()->subYear()->toDateString()],
        ]);

        $donneur = $this->donneurDe($membre);

        return response()->json(['donneur' => $this->dons->declarerDon($donneur, $donnees['date'])]);
    }

    /** Retire le consentement (effet immédiat). La ligne reste : le dernier don doit rester connu. */
    public function retirer(MembreFamille $membre): JsonResponse
    {
        $this->authorize('update', $membre);

        $this->dons->retirer($this->donneurDe($membre));

        return response()->json(['retire' => true]);
    }

    /** L'inscription donneur du membre, ou 404. */
    private function donneurDe(MembreFamille $membre): DonneurSang
    {
        return DonneurSang::where('membre_id', $membre->id)->firstOrFail();
    }
}
