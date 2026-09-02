<?php

namespace App\Http\Controllers\Portail;

use App\Http\Controllers\Controller;
use App\Models\MembreFamille;
use App\Models\StructureSanitaire;
use App\Services\QrTokenService;
use App\Services\RecuRdvService;
use App\Services\ServiceNotification;
use App\Services\SessionDossierService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
        private readonly ServiceNotification $notifications,
    ) {
    }

    /** Établissement de l'agent connecté ; 403 s'il n'y en a pas (cas de l'admin). */
    private function structure(): StructureSanitaire
    {
        $user = auth()->user();

        // P11.0 — DÉFAUT RÉEL CORRIGÉ, TROUVÉ EN RENOMMANT CE RÔLE.
        //
        // Cette garde exigeait le rôle `agent_garde` PAR SON NOM, en plus de la permission
        // `qr.scan` que la route impose déjà. Conséquence : depuis P6.5a, le rôle `medecin`
        // portait `qr.scan` et **ne pouvait pas scanner** — il voyait l'entrée du menu et
        // recevait un 403 disant que le scan « est réservé aux agents de garde ». La décision
        // « le rôle medecin devient utilisable » était donc restée à moitié inopérante, sans
        // que rien ne le signale.
        //
        // Le même défaut aurait frappé les cinq rôles soignants dotés dans cet incrément.
        // Ce projet garde sur des PERMISSIONS, pas sur des noms de rôles : la route porte déjà
        // `permission:qr.scan`, il ne reste ici qu'à vérifier ce qu'elle ne peut pas vérifier —
        // le rattachement à un établissement, sans lequel on ne saurait pas au nom de qui la
        // session de dossier est ouverte.
        abort_if(
            $user->structure_id === null,
            Response::HTTP_FORBIDDEN,
            'Le scan est réservé aux comptes rattachés à un établissement.',
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

        // CdC §4.3 étape 6 : « notification au patient — qui a scanné, quand, quel hôpital ».
        // Stub levé par l'incrément D1. Le carnet familial partagé élargit le destinataire : ce ne
        // sont plus seulement le titulaire mais TOUS les délégués en lecture qui sont prévenus —
        // « si un membre fait un accident, tous les autres le sauront sans même qu'on les appelle ».
        $membre = MembreFamille::find($ouverture->membre_id);

        if ($membre !== null) {
            $this->notifications->dossierConsulte($membre, auth()->user(), 'qr_scan');
        }

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
