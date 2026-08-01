<?php

namespace App\Http\Controllers\Portail;

use App\Http\Controllers\Controller;
use App\Models\StructureSanitaire;
use App\Services\QrTokenService;
use App\Services\RecuRdvService;
use App\Services\SessionDossierService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Module 4 / 4.5 — Scan des QR Code à l'accueil (CdC §4.3 ; Sécurité §5.3 ; Analyse_Delta_RDV N3/N6).
 *
 * DEUX FLUX VOLONTAIREMENT SÉPARÉS — les confondre serait une faille (Analyse_Delta_RDV §46) :
 *
 *  1. QR CARNET (`scanner`)   → consomme un token à usage unique, OUVRE LE DOSSIER MÉDICAL
 *                               pour 30 minutes, journalise l'accès (loi 2013-450).
 *  2. QR REÇU DE RDV (`checkIn`) → enregistre l'arrivée physique du patient. Ne porte aucune
 *                               donnée médicale et n'ouvre JAMAIS un dossier.
 *
 * Deux routes, deux secrets de signature, deux vues. Le scan est réservé au rôle `agent_garde`
 * (CdC §5.4.2, Sécurité §4.1 : permission `qr.scan`) : le gestionnaire ne scanne pas, et l'admin
 * — qui hérite pourtant de toutes les permissions — est refusé faute d'établissement de rattachement
 * (son accès à un dossier relèverait de la voie « admin » du §4.4, hors périmètre de 4.5).
 */
class ScanController extends Controller
{
    public function __construct(
        private readonly QrTokenService $qr,
        private readonly RecuRdvService $recus,
        private readonly SessionDossierService $session,
    ) {
    }

    /** Établissement de l'agent connecté ; 403 s'il n'y en a pas (cas de l'admin). */
    private function structure(): StructureSanitaire
    {
        $user = auth()->user();

        abort_if(
            $user->structure_id === null || ! $user->hasRole('agent_garde'),
            Response::HTTP_FORBIDDEN,
            'Le scan est réservé aux agents de garde rattachés à un établissement.',
        );

        return StructureSanitaire::findOrFail($user->structure_id);
    }

    /** Écran de scan du QR carnet (caméra + saisie manuelle de secours). */
    public function index(): View
    {
        return view('portail.scan.index', ['structure' => $this->structure()]);
    }

    /**
     * Consomme le QR carnet et ouvre la session dossier de 30 minutes.
     *
     * Les échecs (404 invalide / 409 déjà utilisé / 410 expiré) remontent en messages d'erreur
     * plutôt qu'en pages d'exception : à l'accueil, un QR périmé est un cas courant, pas un bug.
     */
    public function scanner(Request $request): RedirectResponse
    {
        $structure = $this->structure();
        $data = $request->validate(['token' => ['required', 'string', 'max:255']]);

        // Une session déjà ouverte est close proprement (audit) avant d'en ouvrir une autre.
        $this->session->fermer('nouveau_scan');

        try {
            $ouverture = $this->qr->consommer($data['token'], [
                'agent_id'      => auth()->id(),
                'etablissement' => $structure->nom,
                'ip'            => $request->ip(),
            ]);
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            return back()->withErrors(['token' => $this->messageEchec($e->getStatusCode())]);
        }

        $this->session->ouvrir($ouverture);

        // CdC §4.3 étape 6 : notification push au patient (« qui a scanné, quand, quel hôpital »).
        // Firebase n'est pas intégré au projet → trace applicative, à brancher au module Notifications.
        Log::info('Dossier consulté après scan QR', [
            'membre_id'     => $ouverture->membre_id,
            'agent_id'      => auth()->id(),
            'etablissement' => $structure->nom,
        ]);

        return redirect()->route('portail.dossier.show');
    }

    /** Écran de scan du QR de reçu de RDV (check-in accueil). */
    public function indexRdv(): View
    {
        return view('portail.scan.rdv', ['structure' => $this->structure()]);
    }

    /**
     * Enregistre l'arrivée du patient (N6). Idempotent : re-scanner un reçu déjà enregistré
     * affiche l'heure d'arrivée initiale sans la réécrire.
     */
    public function checkIn(Request $request): RedirectResponse
    {
        $structure = $this->structure();
        $data = $request->validate(['code' => ['required', 'string', 'max:2000']]);

        try {
            $recu = $this->recus->verifierCode($data['code']);
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            return back()->withErrors(['code' => $this->messageEchecRecu($e->getStatusCode())]);
        }

        $rdv = $recu->rendezVous;

        // Cloisonnement : un agent n'enregistre que les RDV de SON établissement (404, pas 403 :
        // on ne confirme pas l'existence d'un reçu d'un autre hôpital).
        abort_if($rdv === null || $rdv->structure_id !== $structure->id, Response::HTTP_NOT_FOUND);

        if ($rdv->statut !== 'confirme') {
            return back()->withErrors([
                'code' => "Ce rendez-vous n'est pas confirmé (statut : {$rdv->statut}). Validez-le avant l'enregistrement.",
            ]);
        }

        if ($rdv->estEnregistre()) {
            return back()->with('statut', 'Patient déjà enregistré à '.$rdv->checked_in_at->format('H:i').'.');
        }

        $rdv->update([
            'checked_in_at'          => now(),
            'checked_in_by_agent_id' => auth()->id(),
        ]);

        // Le reçu a rempli son office : il ne pourra plus servir à un second enregistrement.
        $recu->update(['statut' => 'utilise']);

        return redirect()
            ->route('portail.rdv.show', $rdv)
            ->with('statut', 'Patient enregistré à l\'accueil à '.now()->format('H:i').'.');
    }

    /** Messages d'accueil pour les trois échecs du QR carnet (Sécurité §5.3). */
    private function messageEchec(int $code): string
    {
        return match ($code) {
            409 => 'Ce QR Code a déjà été utilisé. Demandez au patient d\'en générer un nouveau.',
            410 => 'Ce QR Code a expiré (validité 10 minutes). Demandez au patient d\'en générer un nouveau.',
            default => 'QR Code invalide. Vérifiez qu\'il s\'agit bien du QR du carnet de santé.',
        };
    }

    /** Messages d'accueil pour les échecs du code de reçu (N3). */
    private function messageEchecRecu(int $code): string
    {
        return match ($code) {
            410 => 'Ce code de reçu a expiré. Demandez au patient de rouvrir son reçu dans l\'application.',
            default => 'Code de reçu invalide. Vérifiez qu\'il s\'agit bien du QR du reçu de rendez-vous.',
        };
    }
}
