<?php

namespace App\Http\Controllers\Portail;

use App\Http\Controllers\Controller;
use App\Models\Medecin;
use App\Models\RendezVous;
use App\Models\User;
use App\Services\PartageRdvService;
use App\Services\RecuRdvService;
use App\Services\ReferentService;
use App\Services\RendezVousValidationService;
use App\Services\SessionDossierService;
use App\Support\TypeAccesDossier;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Module 4 / 4.4 — Validation des demandes de RDV (CdC §5.4.2, F3.6).
 *
 * L'agent pré-valide (étape 1), le médecin confirme (étape 2, CDC_11 §9.1) ou l'un des deux
 * refuse — des services de son périmètre ({@see User::servicesGeresIds()}). À la
 * confirmation, le médecin fixe la date/heure définitive, peut assigner un médecin (si
 * l'établissement attribue) et joindre un message.
 *
 * Le groupe de routes est gardé par `permission:rdv.prevalider|rdv.validate` (n'importe laquelle
 * des deux — B1-a) : la lecture de la file est commune, mais CHAQUE action délègue sa propre
 * autorisation à {@see RendezVousValidationService}, seul à savoir laquelle des deux elle exige.
 *
 * La logique (périmètre, transitions, règles) est centralisée dans {@see RendezVousValidationService}
 * — la même que consomme l'API du portail Next.js.
 */
class RendezVousController extends Controller
{
    public function __construct(
        private readonly RendezVousValidationService $rdvs,
        private readonly SessionDossierService $session,
    ) {}

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

    /**
     * B1-b — fiche enrichie : référent (lu via {@see ReferentService}, aucun nouveau mécanisme —
     * D6), aperçu du tarif avec sa source (D7, `RecuRdvService::tarifPour()`, la MÊME méthode que
     * `payer()`, sans effet de bord).
     */
    public function show(RendezVous $rdv, ReferentService $referents, RecuRdvService $recus): View
    {
        $this->rdvs->assertPerimetre(auth()->user(), $rdv);
        $rdv->load(['membre', 'service', 'medecin', 'triage', 'structure']);

        // Médecins réservables de CE service (pour l'attribution éventuelle par l'établissement).
        $medecins = Medecin::where('service_id', $rdv->service_id)->where('actif', true)->orderBy('nom')->get();
        $referent = $rdv->membre !== null ? $referents->actif($rdv->membre) : null;
        $tarif = $recus->tarifPour($rdv);

        // B1-c — état de l'accès partagé DU COMPTE CONNECTÉ pour CE rendez-vous précis : une
        // session ouverte par un autre médecin, sur un autre onglet, n'est pas la sienne (chaque
        // PHP session est indépendante — {@see SessionDossierService}).
        $partageActif = $this->session->estActive()
            && $this->session->typeAcces() === TypeAccesDossier::RDV_PARTAGE->value
            && $this->session->rdvDeclare() === $rdv->id;

        return view('portail.rdv.show', [
            'rdv' => $rdv, 'medecins' => $medecins, 'referent' => $referent,
            'tarif' => $tarif[0] ?? null, 'tarifSource' => $tarif[1] ?? null,
            'partageActif' => $partageActif,
            'partageSecondesRestantes' => $partageActif ? $this->session->secondesRestantes() : 0,
            // B1-d (D10) — visible avant même de cliquer « Clore » : si jamais le règlement
            // n'était pas acquis, l'écran le dit plutôt que de laisser découvrir un 409.
            'reglementVerifie' => $recus->estRegle($rdv),
        ]);
    }

    /** B1-c (D8) — le médecin de ce rendez-vous ouvre son accès de 30 minutes. */
    public function ouvrirPartage(Request $request, RendezVous $rdv, PartageRdvService $partage): RedirectResponse
    {
        $partage->ouvrir(auth()->user(), $rdv, $request->ip());

        return redirect()->route('portail.dossier.show')
            ->with('statut', 'Accès au dossier ouvert pour 30 minutes.');
    }

    /** B1-c — clôture explicite (« Terminer »), avant l'expiration automatique à 30 minutes. */
    public function fermerPartage(RendezVous $rdv, PartageRdvService $partage): RedirectResponse
    {
        $partage->fermer($rdv);

        return redirect()->route('portail.rdv.show', $rdv)->with('statut', 'Accès au dossier refermé.');
    }

    /** B1-d (D10) — clôt le rendez-vous (`confirme → honore`). Voir {@see RendezVousValidationService::terminer()}. */
    public function terminer(RendezVous $rdv): RedirectResponse
    {
        $this->rdvs->assertPerimetre(auth()->user(), $rdv);
        $this->rdvs->terminer(auth()->user(), $rdv);

        return redirect()->route('portail.rdv.index')->with('statut', 'Rendez-vous clos.');
    }

    /** Étape 1 (accueil) — pré-valide un RDV en attente. */
    public function previsalider(Request $request, RendezVous $rdv): RedirectResponse
    {
        $this->rdvs->assertPerimetre(auth()->user(), $rdv);
        $data = $request->validate(['message_agent' => ['nullable', 'string', 'max:1000']]);
        $this->rdvs->previsalider(auth()->user(), $rdv, $data);

        return redirect()->route('portail.rdv.index')->with('statut', 'Rendez-vous pré-validé — en attente du médecin.');
    }

    /** Étape 2 (médecin) — validation finale. */
    public function confirmer(Request $request, RendezVous $rdv): RedirectResponse
    {
        $this->rdvs->assertPerimetre(auth()->user(), $rdv);
        $data = $request->validate(RendezVousValidationService::reglesConfirmer($rdv));
        $this->rdvs->confirmer(auth()->user(), $rdv, $data);

        return redirect()->route('portail.rdv.index')->with('statut', 'Rendez-vous confirmé.');
    }

    public function refuser(Request $request, RendezVous $rdv): RedirectResponse
    {
        $this->rdvs->assertPerimetre(auth()->user(), $rdv);
        $data = $request->validate(
            RendezVousValidationService::reglesRefuser(),
            ['message_agent.required' => 'Indiquez un motif de refus (communiqué au patient).'],
        );
        $this->rdvs->refuser(auth()->user(), $rdv, $data);

        return redirect()->route('portail.rdv.index')->with('statut', 'Rendez-vous refusé.');
    }
}
