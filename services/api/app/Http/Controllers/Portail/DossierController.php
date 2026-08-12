<?php

namespace App\Http\Controllers\Portail;

use App\Http\Controllers\Controller;
use App\Models\MembreFamille;
use App\Models\Triage;
use App\Services\EcritureSoignantService;
use App\Services\SessionDossierService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
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

    /**
     * Sections ouvertes à l'ÉCRITURE du soignant (D0), et leur clé dans
     * {@see \App\Support\RegistreSectionsCarnet}.
     *
     * La table de correspondance existe parce que les deux vocabulaires divergent depuis le Module
     * 2 : le portail dit `analyses`, le registre dit `resultats-analyses`. Renommer l'un des deux
     * toucherait des écrans validés G5 — on traduit ici, à l'unique frontière où les deux se
     * rencontrent, plutôt que de propager le doublon.
     *
     * @var array<string, string>
     */
    private const SECTIONS_ECRITURE = [
        'antecedents'  => 'antecedents',
        'vaccinations' => 'vaccinations',
        'ordonnances'  => 'ordonnances',
        'analyses'     => 'resultats-analyses',
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
            // D0 — le formulaire n'est proposé que si les TROIS conditions sont réunies : section
            // ouverte à l'écriture, compte habilité, voie consentie. Le serveur revérifie tout ;
            // ceci n'évite qu'un formulaire qui serait de toute façon refusé.
            'peutEcrire' => $this->peutEcrire($section),
        ]);
    }

    /**
     * D0 — consigne un acte dans le carnet du membre porté par la SESSION.
     *
     * Aucun identifiant de membre n'est accepté : le dossier écrit est celui de la fenêtre
     * ouverte. Un agent ne peut donc pas écrire dans un autre dossier en changeant l'adresse,
     * exactement comme il ne peut pas en lire un autre (anti-IDOR par construction).
     */
    public function enregistrer(
        Request $request,
        string $section,
        EcritureSoignantService $ecriture,
    ): RedirectResponse {
        $cle = self::SECTIONS_ECRITURE[$section] ?? null;
        abort_if($cle === null, Response::HTTP_NOT_FOUND);

        $membre = $this->session->membre();
        abort_if($membre === null, Response::HTTP_FORBIDDEN);

        $soignant = auth()->user();

        try {
            $entree = $ecriture->ecrire(
                $soignant,
                $membre,
                (string) $this->session->typeAcces(),
                $cle,
                $request->except(['_token']),
            );
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors())->withInput();
        } catch (\RuntimeException $e) {
            // Refus métier (habilitation, voie non consentie, section fermée) : un message, pas une
            // page d'exception — à l'accueil comme au chevet, c'est une situation, pas un bug.
            return back()->withErrors(['ecriture' => $e->getMessage()])->withInput();
        }

        // Journal AVANT la notification : prévenir la famille d'un ajout dont on n'aurait pas gardé
        // la trace le rendrait invérifiable — c'est précisément ce que la fiche de parcours doit
        // pouvoir montrer.
        $this->session->noterEcriture($cle, $entree->getKey());
        $ecriture->notifier($membre, $soignant, $cle);

        return redirect()
            ->route('portail.dossier.section', $section)
            ->with('statut', 'Ajouté au carnet. Le patient et sa famille en sont informés.');
    }

    /** Le formulaire d'écriture doit-il être proposé pour cette section ? */
    private function peutEcrire(string $section): bool
    {
        return isset(self::SECTIONS_ECRITURE[$section])
            && auth()->user()?->can('dossier.ecrire')
            && in_array(
                $this->session->typeAcces(),
                EcritureSoignantService::VOIES_ECRITURE,
                true,
            );
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
