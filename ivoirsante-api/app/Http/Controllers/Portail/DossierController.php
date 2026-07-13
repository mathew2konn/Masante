<?php

namespace App\Http\Controllers\Portail;

use App\Http\Controllers\Controller;
use App\Models\MembreFamille;
use App\Models\Triage;
use App\Services\SessionDossierService;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Module 4 / 4.5 — Consultation du dossier ouvert par un scan (CdC §4.3 étapes 5-7).
 *
 * LECTURE SEULE, et sans identifiant de membre dans l'URL : le dossier consulté est celui que
 * porte la session ouverte au scan. Un agent ne peut donc pas atteindre un autre dossier en
 * modifiant l'adresse (anti-IDOR par construction, OWASP A01). Le middleware `dossier.actif`
 * ferme la fenêtre au bout de 30 minutes.
 *
 * MINIMISATION (loi 2013-450). Les documents importés et la photo du membre sont LISTÉS
 * (titre, catégorie, date) mais jamais téléchargeables depuis le portail : le déchiffrement des
 * blobs reste l'affaire de l'API mobile, pour le titulaire du carnet. Le numéro CMU n'est pas
 * exposé non plus (`$hidden` sur le modèle) ; seule sa forme masquée l'est.
 *
 * Chaque section visitée est notée en session puis inscrite au journal d'audit à la clôture
 * (§10.1 « sections consultées ») — voir {@see SessionDossierService}.
 */
class DossierController extends Controller
{
    /** Sections consultables et leur libellé (la clé est celle journalisée dans l'audit). */
    public const SECTIONS = [
        'identite'     => 'Fiche vitale',
        'antecedents'  => 'Antécédents',
        'vaccinations' => 'Vaccinations',
        'ordonnances'  => 'Ordonnances',
        'analyses'     => 'Résultats d\'analyses',
        'mesures'      => 'Journal de mesures',
        'notes'        => 'Notes & observations',
        'contacts'     => 'Contacts d\'urgence',
        'documents'    => 'Documents importés',
        'triage'       => 'Fiche de triage',
    ];

    public function __construct(private readonly SessionDossierService $session)
    {
    }

    /** Ouvre le dossier sur la fiche vitale. */
    public function show(): View
    {
        return $this->section('identite');
    }

    /** Affiche une section et la note comme consultée. */
    public function section(string $section): View
    {
        abort_if(! array_key_exists($section, self::SECTIONS), Response::HTTP_NOT_FOUND);

        $membre = $this->session->membre();
        abort_if($membre === null, Response::HTTP_FORBIDDEN);

        $this->session->noterSection($section);

        return view('portail.dossier.show', [
            'membre'   => $membre,
            'section'  => $section,
            'sections' => self::SECTIONS,
            'donnees'  => $this->donneesDe($membre, $section),
            'restant'  => $this->session->secondesRestantes(),
        ]);
    }

    /**
     * Ferme la session : écrit la ligne d'audit de clôture (durée réelle + sections).
     * On retourne d'où l'on vient : à l'écran de scan après un QR, à la liste des patients suivis
     * après un accès référent (5.6) — le type d'accès est lu AVANT la fermeture, qui purge la session.
     */
    public function fermer(): RedirectResponse
    {
        $referent = $this->session->typeAcces() === 'referent';

        $this->session->fermer('manuelle');

        return redirect()
            ->route($referent ? 'portail.patients.index' : 'portail.scan.index')
            ->with('statut', 'Dossier fermé. L\'accès est journalisé.');
    }

    /**
     * Contenu d'une section. `identite` n'a pas de collection (les champs du membre suffisent) ;
     * `triage` remonte les dernières fiches de triage du membre (permission `triage.view`).
     */
    private function donneesDe(MembreFamille $membre, string $section)
    {
        return match ($section) {
            'antecedents'  => $membre->antecedents()->orderByDesc('date_diagnostic')->get(),
            'vaccinations' => $membre->vaccinations()->orderByDesc('date_administration')->get(),
            'ordonnances'  => $membre->ordonnances()->orderByDesc('date_prescription')->get(),
            'analyses'     => $membre->resultatsAnalyses()->orderByDesc('date_analyse')->get(),
            // FN5 — « partage automatique avec le médecin référent » : le journal de bord du patient
            // (glycémie, tension…) est une section du dossier comme une autre. Elle n'est donc PAS
            // poussée hors du serveur : elle se lit ici, dans une session tracée. 90 derniers jours.
            'mesures'      => $membre->mesuresSante()
                ->where('date_mesure', '>=', now()->subDays(90))
                ->orderByDesc('date_mesure')
                ->get(),
            'notes'        => $membre->notesObservations()->latest()->get(),
            'contacts'     => $membre->contactsUrgence()->orderByDesc('est_principal')->get(),
            'documents'    => $membre->documentsMedicaux()->latest()->get(),
            // `triages.membre_id` est nullable sans FK (pas de relation Eloquent sur le membre).
            'triage'       => Triage::where('membre_id', $membre->id)->latest()->limit(5)->get(),
            default        => collect(),
        };
    }
}
