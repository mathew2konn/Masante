<?php

namespace App\Http\Controllers\Api\V1\Portail;

use App\Http\Controllers\Controller;
use App\Models\Medecin;
use App\Models\RendezVous;
use App\Services\RendezVousValidationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * API staff du portail Next.js — validation des RDV (Module 4 / 4.4). Mêmes règles et transitions
 * que le portail Blade (via {@see RendezVousValidationService}) : aucune logique métier ici, le
 * contrôleur ne fait qu'exposer en JSON. Auth Sanctum + `permission:rdv.validate` (routes).
 */
class RendezVousController extends Controller
{
    public function __construct(private readonly RendezVousValidationService $rdvs)
    {
    }

    /**
     * Garde de permission (guard-agnostique : `can()` passe par la Gate spatie et vérifie les
     * permissions du compte quelle que soit la façon dont il s'est authentifié — ici Sanctum).
     */
    private function autoriser(Request $request): void
    {
        abort_unless(
            $request->user()->can('rdv.validate'),
            403,
            'Action réservée à la validation des rendez-vous.',
        );
    }

    /** File d'attente des RDV du périmètre, filtrable par statut (paginée). */
    public function index(Request $request): JsonResponse
    {
        $this->autoriser($request);
        $statut = (string) $request->query('statut', 'en_attente');
        if (! in_array($statut, RendezVousValidationService::STATUTS, true)) {
            $statut = 'en_attente';
        }

        $rdvs = RendezVous::whereIn('service_id', $this->rdvs->serviceIds($request->user()))
            ->where('statut', $statut)
            ->with(['membre', 'service', 'medecin', 'triage'])
            ->orderBy('date_souhaitee')
            ->paginate(15);

        return response()->json($rdvs);
    }

    /** Détail d'un RDV du périmètre + médecins réservables du service (attribution éventuelle). */
    public function show(Request $request, RendezVous $rdv): JsonResponse
    {
        $this->autoriser($request);
        $this->rdvs->assertPerimetre($request->user(), $rdv);
        $rdv->load(['membre', 'service', 'medecin', 'triage', 'structure']);

        $medecins = Medecin::where('service_id', $rdv->service_id)
            ->where('actif', true)
            ->orderBy('nom')
            ->get();

        return response()->json(['rendez_vous' => $rdv, 'medecins' => $medecins]);
    }

    /** Confirme un RDV en attente : date définitive + médecin optionnel + message. */
    public function confirmer(Request $request, RendezVous $rdv): JsonResponse
    {
        $this->autoriser($request);
        $this->rdvs->assertPerimetre($request->user(), $rdv);
        $data = $request->validate(RendezVousValidationService::reglesConfirmer($rdv));

        return response()->json(['rendez_vous' => $this->rdvs->confirmer($rdv, $data)]);
    }

    /** Refuse un RDV en attente : motif obligatoire (communiqué au patient). */
    public function refuser(Request $request, RendezVous $rdv): JsonResponse
    {
        $this->autoriser($request);
        $this->rdvs->assertPerimetre($request->user(), $rdv);
        $data = $request->validate(RendezVousValidationService::reglesRefuser());

        return response()->json(['rendez_vous' => $this->rdvs->refuser($rdv, $data)]);
    }
}
