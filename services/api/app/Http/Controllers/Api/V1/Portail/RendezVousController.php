<?php

namespace App\Http\Controllers\Api\V1\Portail;

use App\Http\Controllers\Controller;
use App\Models\Medecin;
use App\Models\RendezVous;
use App\Services\RecuRdvService;
use App\Services\ReferentService;
use App\Services\RendezVousValidationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * API staff du portail Next.js — validation des RDV (Module 4 / 4.4). Mêmes règles et transitions
 * que le portail Blade (via {@see RendezVousValidationService}) : aucune logique métier ici, le
 * contrôleur ne fait qu'exposer en JSON. Auth Sanctum, permission vérifiée en service (guard
 * Sanctum — le middleware `permission:` spatie viserait le guard `web`, piège P4).
 *
 * B1-a — la lecture (`index`/`show`) reste ouverte aux DEUX permissions (`rdv.prevalider` OU
 * `rdv.validate`) : l'accueil et le médecin doivent tous deux voir la file. Les actions
 * d'écriture (`previsalider`/`confirmer`/`refuser`) délèguent leur propre autorisation au
 * service — c'est lui qui distingue l'étape 1 de l'étape 2, pas ce contrôleur.
 */
class RendezVousController extends Controller
{
    public function __construct(private readonly RendezVousValidationService $rdvs) {}

    /** Lecture : accessible à qui peut traiter un RDV, à N'IMPORTE quelle étape. */
    private function autoriser(Request $request): void
    {
        abort_unless(
            $request->user()->can('rdv.prevalider') || $request->user()->can('rdv.validate'),
            403,
            'Action réservée au traitement des rendez-vous.',
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

    /**
     * Détail d'un RDV du périmètre + médecins réservables du service (attribution éventuelle).
     *
     * B1-b — fiche enrichie : référent (D6, {@see ReferentService}, aucun nouveau mécanisme) et
     * aperçu du tarif avec sa source (D7, `RecuRdvService::tarifPour()` — la MÊME méthode que
     * `RecuRdvService::payer()`, sans effet de bord, jamais une seconde façon de calculer le même
     * montant).
     */
    public function show(Request $request, RendezVous $rdv, ReferentService $referents, RecuRdvService $recus): JsonResponse
    {
        $this->autoriser($request);
        $this->rdvs->assertPerimetre($request->user(), $rdv);
        $rdv->load(['membre', 'service', 'medecin', 'triage', 'structure']);

        $medecins = Medecin::where('service_id', $rdv->service_id)
            ->where('actif', true)
            ->orderBy('nom')
            ->get();

        $referent = $rdv->membre !== null ? $referents->actif($rdv->membre) : null;
        $tarif = $recus->tarifPour($rdv);

        return response()->json([
            'rendez_vous' => $rdv,
            'medecins' => $medecins,
            'referent' => $referent,
            'tarif' => $tarif[0] ?? null,
            'tarif_source' => $tarif[1] ?? null,
        ]);
    }

    /** Étape 1 (accueil) — pré-valide un RDV en attente. */
    public function previsalider(Request $request, RendezVous $rdv): JsonResponse
    {
        $this->autoriser($request);
        $this->rdvs->assertPerimetre($request->user(), $rdv);
        $data = $request->validate(['message_agent' => ['nullable', 'string', 'max:1000']]);

        return response()->json(['rendez_vous' => $this->rdvs->previsalider($request->user(), $rdv, $data)]);
    }

    /** Étape 2 (médecin) — confirme un RDV pré-validé : date définitive + médecin optionnel + message. */
    public function confirmer(Request $request, RendezVous $rdv): JsonResponse
    {
        $this->autoriser($request);
        $this->rdvs->assertPerimetre($request->user(), $rdv);
        $data = $request->validate(RendezVousValidationService::reglesConfirmer($rdv));

        return response()->json(['rendez_vous' => $this->rdvs->confirmer($request->user(), $rdv, $data)]);
    }

    /** Refuse un RDV en attente ou pré-validé : motif obligatoire (communiqué au patient). */
    public function refuser(Request $request, RendezVous $rdv): JsonResponse
    {
        $this->autoriser($request);
        $this->rdvs->assertPerimetre($request->user(), $rdv);
        $data = $request->validate(RendezVousValidationService::reglesRefuser());

        return response()->json(['rendez_vous' => $this->rdvs->refuser($request->user(), $rdv, $data)]);
    }

    /** B1-d (D10) — clôt le rendez-vous (`confirme → honore`). Voir {@see RendezVousValidationService::terminer()}. */
    public function terminer(Request $request, RendezVous $rdv): JsonResponse
    {
        $this->autoriser($request);
        $this->rdvs->assertPerimetre($request->user(), $rdv);

        return response()->json(['rendez_vous' => $this->rdvs->terminer($request->user(), $rdv)]);
    }
}
