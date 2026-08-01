<?php

namespace App\Http\Controllers\Portail;

use App\Http\Controllers\Controller;
use App\Services\BrisDeGlaceService;
use App\Services\SessionDossierService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Module 5 / 5.3 — Bris de glace au portail (Note_Continuite §5).
 *
 * Accès d'exception au vital minimal d'un patient hors d'état de consentir. Les six garde-fous de
 * la note §5.3 sont ici :
 *
 *  1. permission `urgence.bris_de_glace`, accordée individuellement par le gestionnaire aux seuls
 *     agents d'un service d'urgences (contrôlée à nouveau ici : la permission ne suffit pas, le
 *     service doit toujours être un service d'urgences au moment de l'accès) ;
 *  2. justification textuelle obligatoire (motif + élément d'identification du patient) ;
 *  3. notification immédiate au titulaire et aux contacts d'urgence ({@see BrisDeGlaceService}) ;
 *  4. entrée renforcée au journal d'audit, consultable par le patient ;
 *  5. session courte de 15 minutes, lecture seule sur le seul périmètre vital ;
 *  6. revue a posteriori : les bris de glace apparaissent dans les statistiques de l'admin.
 *
 * L'identification exige une correspondance EXACTE sur trois critères : on confirme une identité,
 * on n'explore pas un annuaire. Les tentatives infructueuses sont journalisées — un agent qui
 * cherche à tâtons doit laisser une trace.
 */
class BrisDeGlaceController extends Controller
{
    /** Spécialité de service habilitée au bris de glace (Note_Continuite §5.3). */
    private const SPECIALITE_URGENCES = 'urgences';

    public function __construct(
        private readonly BrisDeGlaceService $bris,
        private readonly SessionDossierService $session,
    ) {
    }

    /**
     * Vérifie que l'agent connecté est bien affecté à un service d'urgences.
     *
     * La permission seule ne suffit pas : un agent habilité, muté ensuite en ORL, ne doit plus
     * pouvoir ouvrir de dossier en urgence. On revalide donc à chaque accès.
     */
    private function agentDesUrgences()
    {
        $agent = auth()->user();

        abort_if(
            $agent->service?->specialite !== self::SPECIALITE_URGENCES,
            Response::HTTP_FORBIDDEN,
            'Le bris de glace est réservé aux agents affectés à un service d\'urgences.',
        );

        return $agent;
    }

    /** Formulaire d'identification + justification. */
    public function index(): View
    {
        return view('portail.urgence.bris-de-glace', ['agent' => $this->agentDesUrgences()]);
    }

    /** Identifie le patient, ouvre l'accès d'exception et la session de 15 minutes. */
    public function ouvrir(Request $request): RedirectResponse
    {
        $agent = $this->agentDesUrgences();

        $data = $request->validate([
            'nom'            => ['required', 'string', 'max:100'],
            'prenom'         => ['required', 'string', 'max:100'],
            'date_naissance' => ['required', 'date', 'before_or_equal:today'],
            // La justification est la contrepartie de l'exception : elle doit dire quelque chose.
            'motif'          => ['required', 'string', 'min:20', 'max:1000'],
        ], [], [
            'motif' => 'motif de l\'urgence',
        ]);

        $membre = $this->bris->identifier($data['nom'], $data['prenom'], $data['date_naissance']);

        if ($membre === null) {
            // Trace des tentatives infructueuses : un agent qui cherche à tâtons doit laisser
            // une trace, même si aucun dossier n'a été ouvert.
            Log::warning('Bris de glace — patient non identifié', [
                'agent_id' => $agent->id,
                'critere'  => "{$data['nom']} {$data['prenom']} {$data['date_naissance']}",
            ]);

            return back()->withInput()->withErrors([
                'nom' => 'Aucun patient ne correspond exactement à ces trois informations. '
                    .'Vérifiez l\'orthographe et la date de naissance.',
            ]);
        }

        // Une session de scan éventuellement ouverte est close proprement avant celle-ci.
        $this->session->fermer('bris_de_glace');

        $acces = $this->bris->ouvrir($membre, $agent, $data['motif'], $request->ip());
        $this->session->ouvrir($acces, SessionDossierService::DUREE_BRIS_DE_GLACE);

        return redirect()->route('portail.urgence.dossier');
    }

    /** Vital minimal du patient, en lecture seule, tant que la fenêtre de 15 minutes est ouverte. */
    public function dossier(): View|RedirectResponse
    {
        $this->agentDesUrgences();

        // Défense en profondeur : cette vue n'affiche QUE des sessions de bris de glace. Une session
        // ouverte par QR passe par l'écran de dossier complet (4.5), pas par celui-ci.
        abort_if(
            $this->session->typeAcces() !== null && $this->session->typeAcces() !== 'bris_de_glace',
            Response::HTTP_FORBIDDEN,
        );

        $membre = $this->session->membre();

        // Fenêtre expirée (ou jamais ouverte) : la clôture est déjà journalisée, on renvoie au
        // formulaire plutôt que d'afficher une erreur. Un nouvel accès exige une nouvelle justification.
        if ($membre === null) {
            return redirect()->route('portail.urgence.bris')
                ->with('statut', 'Accès d\'urgence expiré. Une nouvelle justification est nécessaire.');
        }

        $this->session->noterSection('fiche_vitale');

        return view('portail.urgence.dossier', [
            'fiche'   => $this->bris->ficheVitale($membre),
            'restant' => $this->session->secondesRestantes(),
        ]);
    }

    /** Ferme l'accès d'urgence : écrit la ligne d'audit de clôture (durée réelle). */
    public function fermer(): RedirectResponse
    {
        $this->session->fermer('manuelle');

        return redirect()->route('portail.dashboard')
            ->with('statut', 'Accès d\'urgence fermé. Le titulaire a été notifié et l\'accès est journalisé.');
    }
}
