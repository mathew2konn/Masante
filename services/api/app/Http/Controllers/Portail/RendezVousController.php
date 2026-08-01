<?php

namespace App\Http\Controllers\Portail;

use App\Http\Controllers\Controller;
use App\Models\Medecin;
use App\Models\RendezVous;
use App\Services\RendezVousValidationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Module 4 / 4.4 — Validation des demandes de RDV (CdC §5.4.2, F3.6).
 *
 * L'agent (ou le gestionnaire superviseur) confirme ou refuse les RDV des services de son périmètre
 * ({@see \App\Models\User::servicesGeresIds()}). À la confirmation, il fixe la date/heure définitive,
 * peut assigner un médecin (si l'établissement attribue) et joindre un message. Seuls les RDV
 * `en_attente` sont traitables. Protégé par `permission:rdv.validate`.
 *
 * La logique (périmètre, transitions, règles) est centralisée dans {@see RendezVousValidationService}
 * — la même que consomme l'API du portail Next.js.
 */
class RendezVousController extends Controller
{
    public function __construct(private readonly RendezVousValidationService $rdvs)
    {
    }

    public function index(Request $request): View
    {
        $statut = (string) $request->query('statut', 'en_attente');
        if (! in_array($statut, RendezVousValidationService::STATUTS, true)) {
            $statut = 'en_attente';
        }

        $rdvs = RendezVous::whereIn('service_id', $this->rdvs->serviceIds(auth()->user()))
            ->where('statut', $statut)
            ->with(['membre', 'service', 'medecin', 'triage'])
            ->orderBy('date_souhaitee')
            ->paginate(15)
            ->withQueryString();

        return view('portail.rdv.index', ['rdvs' => $rdvs, 'statut' => $statut, 'statuts' => RendezVousValidationService::STATUTS]);
    }

    public function show(RendezVous $rdv): View
    {
        $this->rdvs->assertPerimetre(auth()->user(), $rdv);
        $rdv->load(['membre', 'service', 'medecin', 'triage', 'structure']);

        // Médecins réservables de CE service (pour l'attribution éventuelle par l'établissement).
        $medecins = Medecin::where('service_id', $rdv->service_id)->where('actif', true)->orderBy('nom')->get();

        return view('portail.rdv.show', ['rdv' => $rdv, 'medecins' => $medecins]);
    }

    public function confirmer(Request $request, RendezVous $rdv): RedirectResponse
    {
        $this->rdvs->assertPerimetre(auth()->user(), $rdv);
        $data = $request->validate(RendezVousValidationService::reglesConfirmer($rdv));
        $this->rdvs->confirmer($rdv, $data);

        return redirect()->route('portail.rdv.index')->with('statut', 'Rendez-vous confirmé.');
    }

    public function refuser(Request $request, RendezVous $rdv): RedirectResponse
    {
        $this->rdvs->assertPerimetre(auth()->user(), $rdv);
        $data = $request->validate(
            RendezVousValidationService::reglesRefuser(),
            ['message_agent.required' => 'Indiquez un motif de refus (communiqué au patient).'],
        );
        $this->rdvs->refuser($rdv, $data);

        return redirect()->route('portail.rdv.index')->with('statut', 'Rendez-vous refusé.');
    }
}
