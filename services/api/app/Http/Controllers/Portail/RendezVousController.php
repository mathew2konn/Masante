<?php

namespace App\Http\Controllers\Portail;

use App\Http\Controllers\Controller;
use App\Models\Medecin;
use App\Models\RendezVous;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Module 4 / 4.4 — Validation des demandes de RDV (CdC §5.4.2, F3.6).
 *
 * L'agent (ou le gestionnaire superviseur) confirme ou refuse les RDV des services de son périmètre
 * ({@see \App\Models\User::servicesGeresIds()}). À la confirmation, il fixe la date/heure définitive,
 * peut assigner un médecin (si l'établissement attribue) et joindre un message. Seuls les RDV
 * `en_attente` sont traitables. Protégé par `permission:rdv.validate`.
 */
class RendezVousController extends Controller
{
    /** Statuts filtrables dans la liste. */
    public const STATUTS = ['en_attente', 'confirme', 'refuse', 'annule', 'honore'];

    private function serviceIds(): array
    {
        $ids = auth()->user()->servicesGeresIds();
        abort_if($ids === [], Response::HTTP_FORBIDDEN, 'Aucun service à gérer pour ce compte.');

        return $ids;
    }

    /** Récupère un RDV DE MON périmètre, ou 404. */
    private function rdvEnPerimetre(RendezVous $rdv): RendezVous
    {
        abort_if(! in_array($rdv->service_id, $this->serviceIds(), true), Response::HTTP_NOT_FOUND);

        return $rdv;
    }

    public function index(Request $request): View
    {
        $statut = (string) $request->query('statut', 'en_attente');
        if (! in_array($statut, self::STATUTS, true)) {
            $statut = 'en_attente';
        }

        $rdvs = RendezVous::whereIn('service_id', $this->serviceIds())
            ->where('statut', $statut)
            ->with(['membre', 'service', 'medecin', 'triage'])
            ->orderBy('date_souhaitee')
            ->paginate(15)
            ->withQueryString();

        return view('portail.rdv.index', ['rdvs' => $rdvs, 'statut' => $statut, 'statuts' => self::STATUTS]);
    }

    public function show(RendezVous $rdv): View
    {
        $this->rdvEnPerimetre($rdv);
        $rdv->load(['membre', 'service', 'medecin', 'triage', 'structure']);

        // Médecins réservables de CE service (pour l'attribution éventuelle par l'établissement).
        $medecins = Medecin::where('service_id', $rdv->service_id)->where('actif', true)->orderBy('nom')->get();

        return view('portail.rdv.show', ['rdv' => $rdv, 'medecins' => $medecins]);
    }

    public function confirmer(Request $request, RendezVous $rdv): RedirectResponse
    {
        $this->rdvEnPerimetre($rdv);
        $this->assertTraitable($rdv);

        $data = $request->validate([
            'date_confirmee' => ['required', 'date', 'after_or_equal:today'],
            'medecin_id'     => [
                'nullable',
                \Illuminate\Validation\Rule::exists('medecins', 'id')->where('service_id', $rdv->service_id),
            ],
            'message_agent'  => ['nullable', 'string', 'max:1000'],
        ]);

        $rdv->update([
            'statut'         => 'confirme',
            'date_confirmee' => $data['date_confirmee'],
            'medecin_id'     => $data['medecin_id'] ?? $rdv->medecin_id,
            'message_agent'  => $data['message_agent'] ?? null,
        ]);

        return redirect()->route('portail.rdv.index')->with('statut', 'Rendez-vous confirmé.');
    }

    public function refuser(Request $request, RendezVous $rdv): RedirectResponse
    {
        $this->rdvEnPerimetre($rdv);
        $this->assertTraitable($rdv);

        $data = $request->validate([
            'message_agent' => ['required', 'string', 'max:1000'],
        ], [
            'message_agent.required' => 'Indiquez un motif de refus (communiqué au patient).',
        ]);

        $rdv->update(['statut' => 'refuse', 'message_agent' => $data['message_agent']]);

        return redirect()->route('portail.rdv.index')->with('statut', 'Rendez-vous refusé.');
    }

    /** Un RDV déjà traité (confirmé/refusé/annulé/honoré) n'est plus modifiable ici. */
    private function assertTraitable(RendezVous $rdv): void
    {
        abort_if($rdv->statut !== 'en_attente', Response::HTTP_CONFLICT, 'Ce rendez-vous a déjà été traité.');
    }
}
