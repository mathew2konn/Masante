<?php

namespace App\Http\Controllers\Portail;

use App\Http\Controllers\Controller;
use App\Models\MembreFamille;
use App\Services\ReferentService;
use App\Services\SessionDossierService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Module 5 / 5.6 — « Mes patients suivis » : la voie 2 au portail (Sécurité §4.4 ; Note_Continuite §2).
 *
 * Le médecin référent n'a ni QR à scanner, ni urgence à justifier : le patient l'a désigné. Mais le
 * droit permanent ne dispense d'aucune des garanties du dossier :
 *
 *  - la LISTE est celle des désignations ACTIVES, relue à chaque affichage — une révocation faite à
 *    l'instant depuis le mobile fait disparaître le patient de l'écran ;
 *  - la QUALITÉ DE RÉFÉRENT est revérifiée à l'ouverture, pas seulement à l'affichage de la liste
 *    (le patient a pu révoquer entre les deux) ;
 *  - chaque ouverture crée une session de 30 minutes et DEUX lignes d'audit, comme un scan : le
 *    patient voit qui a consulté, quand, combien de temps et quelles sections (§10.1) ;
 *  - le titulaire est notifié à chaque accès ({@see ReferentService}).
 *
 * L'`{membre}` n'apparaît que sur la route d'OUVERTURE, jamais sur celles du dossier : une fois la
 * session ouverte, l'anti-IDOR du 4.5 reprend la main (aucun identifiant dans l'URL).
 */
class MesPatientsController extends Controller
{
    public function __construct(
        private readonly ReferentService $referents,
        private readonly SessionDossierService $session,
    ) {
    }

    /**
     * Le compte connecté doit être relié à une fiche de l'annuaire (lien posé par le gestionnaire).
     * La permission `dossier.referent` ne suffit pas : elle dit que le RÔLE peut être référent, pas
     * que CE compte est un praticien identifié.
     */
    private function medecin()
    {
        $medecin = auth()->user()->medecin;

        abort_if(
            $medecin === null,
            Response::HTTP_FORBIDDEN,
            'Votre compte n\'est relié à aucune fiche de praticien. Demandez à votre gestionnaire de '
                .'faire le lien pour pouvoir suivre vos patients référents.',
        );

        return $medecin;
    }

    /** Patients ayant désigné ce praticien comme référent (désignations actives). */
    public function index(): View
    {
        return view('portail.patients.index', [
            'medecin'  => $this->medecin(),
            'patients' => $this->referents->membresSuivisPar(auth()->user()),
        ]);
    }

    /** Ouvre le dossier d'un patient suivi : journalise l'accès et ouvre la fenêtre de 30 minutes. */
    public function ouvrir(Request $request, MembreFamille $membre): RedirectResponse
    {
        $this->medecin();

        // Revérification à l'instant de l'accès : une désignation révoquée entre-temps ne doit plus
        // rien ouvrir. 404 et non 403 — on ne confirme pas l'existence du dossier d'un inconnu.
        abort_if(
            ! $this->referents->estReferentDe(auth()->user(), $membre),
            Response::HTTP_NOT_FOUND,
        );

        // Une session déjà ouverte (scan précédent) est close proprement — audit complet — avant
        // d'en ouvrir une autre. Un dossier à la fois.
        $this->session->fermer('nouvel_acces_referent');

        $acces = $this->referents->ouvrir($membre, auth()->user(), $request->ip());
        $this->session->ouvrir($acces);

        return redirect()->route('portail.dossier.show');
    }
}
