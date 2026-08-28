<?php

namespace App\Http\Controllers\Portail;

use App\Http\Controllers\Controller;
use App\Models\MembreFamille;
use App\Models\Triage;
use App\Services\EcritureSoignantService;
use App\Services\Maladie\ServiceMaladies;
use App\Services\Pki\ServiceSignature;
use App\Services\SessionDossierService;
use App\Services\Triage\ServiceRetourTriage;
use App\Support\RegistreRetourTriage;
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

    /**
     * Les sections dont l'entrée peut être SIGNÉE, et le type de document correspondant au
     * registre de la PKI (P6.5b).
     *
     * Un seul aujourd'hui, et c'est celui que CDC_09 §5.3 nomme : « les prescriptions deviennent
     * juridiquement traçables ». Les autres sections restent écrivables sans être signables — le
     * registre `RegistreDocumentsSignables` dit lesquels des sept types du §4.5 manquent et
     * pourquoi.
     *
     * @var array<string, string>
     */
    private const SECTIONS_SIGNABLES = [
        'ordonnances' => \App\Services\Pki\DocumentOrdonnance::CODE,
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

        $donnees = $this->donneesDe($membre, $section);

        return view('portail.dossier.show', [
            'membre'   => $membre,
            'section'  => $section,
            'sections' => self::SECTIONS,
            'donnees'  => $donnees,
            'restant'  => $this->session->secondesRestantes(),

            // ═══ P10c-2-i — LE RETOUR CLINIQUE SUR L'ORIENTATION (CDC_05 §5.5.4, §9.1) ═══
            //
            // Le médecin lisait la fiche de triage depuis le Module 4 sans aucun moyen d'en dire
            // quoi que ce soit. Le formulaire n'est proposé qu'au compte habilité ; le service
            // revérifie de toute façon (la garde qui fait autorité est là-bas — piège P4, où un
            // middleware `permission:` au mauvais guard laisse passer).
            'peutDonnerRetour' => $section === 'triage'
                && auth()->user()?->can('triage.retour') === true,
            'retoursPossibles' => RegistreRetourTriage::RETOURS,
            // Les retours DÉJÀ donnés, par triage. Ils restent affichés même quand un praticien
            // s'est ravisé : le journal est append-only, et un avis retiré est lui-même une
            // information.
            'retoursDonnes' => $section === 'triage' ? $this->retoursDonnes($donnees) : [],
            // D0 — le formulaire n'est proposé que si les TROIS conditions sont réunies : section
            // ouverte à l'écriture, compte habilité, voie consentie. Le serveur revérifie tout ;
            // ceci n'évite qu'un formulaire qui serait de toute façon refusé.
            'peutEcrire' => $this->peutEcrire($section),
            // P6.5b — le champ « secret de signature » n'apparaît que si cette section est
            // signable ET que le compte est habilité. Le serveur revérifie de toute façon les cinq
            // contrôles du §5.4 ; ceci évite seulement de demander un secret pour rien.
            'peutSigner' => isset(self::SECTIONS_SIGNABLES[self::SECTIONS_ECRITURE[$section] ?? ''])
                && auth()->user()?->can('document.signer') === true
                && $this->peutEcrire($section),
            // P6.8c — le vocabulaire des maladies proposé au soignant qui consigne un antécédent.
            // DEPUIS LA VERSION PUBLIÉE, et sans lever de 503 : une liste vide veut dire « aucune
            // version en vigueur », et le formulaire le dit. Le lien reste facultatif — le serveur
            // ne devine jamais une maladie depuis la description (CDC_00 §4).
            'maladiesReferentiel' => $section === 'antecedents'
                ? app(ServiceMaladies::class)->listePubliee()
                : [],
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
        ServiceSignature $signature,
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
                // `secret_signature` est retiré ici et non laissé à la validation : il serait
                // certes écarté (les règles de la section ne le connaissent pas), mais il aurait
                // traversé un tableau susceptible d'être journalisé en cas d'erreur de validation.
                // Un secret ne traverse que ce qu'il doit traverser.
                $request->except(['_token', 'secret_signature']),
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

        // ═══ P6.5b — SIGNATURE ÉLECTRONIQUE DE LA PRESCRIPTION (CDC_09 §5.3) ═══
        //
        // Proposée, pas imposée, et c'est une décision assumée : P7-D0 est validé G5, et un
        // praticien sans certificat — ou dont le certificat vient d'expirer — doit continuer
        // d'écrire au carnet. Rendre la signature obligatoire ici aurait transformé un incrément
        // additif en régression pour tout soignant non encore certifié.
        //
        // Ce qui est en revanche INCONDITIONNEL, c'est que le nom du prescripteur vienne désormais
        // de la fiche et non du formulaire ({@see EcritureSoignantService}) : le trou du G0 se
        // referme pour toutes les ordonnances, signées ou non.
        //
        // L'échec de signature ne défait PAS l'écriture. L'ordonnance est déjà au carnet, déjà
        // notifiée, déjà journalisée ; l'annuler parce qu'un secret a été mal tapé priverait le
        // patient de sa prescription pour une raison qui ne le concerne pas. Le message le dit.
        $messageSignature = $this->signerSiDemande($request, $cle, $entree, $signature);

        return redirect()
            ->route('portail.dossier.section', $section)
            ->with('statut', 'Ajouté au carnet. Le patient et sa famille en sont informés.'.$messageSignature);
    }

    /**
     * Signe l'entrée si le praticien a fourni son secret et que le type est signable.
     *
     * Renvoie le complément de message destiné à l'écran — jamais une exception : la signature est
     * un plus, son absence n'invalide pas l'acte de soin.
     */
    private function signerSiDemande(
        Request $request,
        string $cle,
        \Illuminate\Database\Eloquent\Model $entree,
        ServiceSignature $signature,
    ): string {
        $secret = (string) $request->input('secret_signature', '');
        $type   = self::SECTIONS_SIGNABLES[$cle] ?? null;

        if ($type === null || $secret === '') {
            return '';
        }

        if (auth()->user()?->can('document.signer') !== true) {
            return " La signature n'a pas été posée : ce compte n'y est pas habilité.";
        }

        try {
            $signature->signer(auth()->user(), $type, (int) $entree->getKey(), $secret);
        } catch (\RuntimeException $e) {
            // Le motif vient des règles §5.4 ou du secret : il est déjà écrit pour être lu par un
            // humain, et le refus est déjà au journal.
            return ' Mais la signature a été refusée : '.$e->getMessage();
        }

        return ' Prescription signée électroniquement.';
    }

    /**
     * P10c-2-i — Le soignant dit si l'orientation rendue par un triage était adaptée (§5.5.4).
     *
     * ═══ UN SEUL GESTE, ET C'EST DÉLIBÉRÉ ═══
     *
     * Donner un retour sur un triage, c'est dire du même mouvement « voilà pourquoi ce patient est
     * devant moi ». Demander deux formulaires — l'un pour désigner, l'autre pour juger — aurait
     * multiplié les occasions de n'en remplir qu'un, et le lien du §5.5.4 serait resté à moitié
     * posé, ce que le G0 reprochait précisément à l'existant (constat Y6).
     *
     * Le triage n'est PAS déduit de la session : il est nommé dans le formulaire, et le service
     * vérifie qu'il appartient bien au dossier ouvert.
     *
     * ═══ DEUX ÉCRITURES, DEUX DURÉES DE VIE, ET C'EST VOULU ═══
     *
     * Le RETOUR part immédiatement au journal du §10 : il est le fait clinique précieux, et le
     * faire attendre la clôture l'exposerait à disparaître avec un navigateur fermé (constat F5 de
     * P7-D2). Le LIEN de consultation, lui, n'a de sens qu'accompagné de ce que la visite a produit
     * — il rejoint donc `donnees_ajoutees` sur la ligne de clôture.
     */
    public function retourTriage(
        Request $request,
        Triage $triage,
        ServiceRetourTriage $retours,
    ): RedirectResponse {
        $membre = $this->session->membre();
        abort_if($membre === null, Response::HTTP_FORBIDDEN);

        try {
            $retours->enregistrer(
                auth()->user(),
                $membre,
                $triage,
                (string) $request->input('retour', ''),
                $request->input('justification'),
            );
        } catch (\RuntimeException $e) {
            // Refus métier (habilitation, triage hors dossier, motif manquant) : un message à
            // l'écran, pas une page d'exception — au chevet comme à l'accueil, c'est une
            // situation, pas un bug.
            return back()->withErrors(['retour' => $e->getMessage()])->withInput();
        }

        // Le lien de consultation n'est posé QU'APRÈS que le retour a été accepté : un refus ne
        // doit pas laisser derrière lui une consultation rattachée à un triage sur lequel rien
        // n'a finalement été dit.
        $this->session->noterTriage((int) $triage->id);

        return redirect()
            ->route('portail.dossier.section', 'triage')
            ->with('statut', 'Retour enregistré. Il servira à améliorer les prochaines orientations.');
    }

    /**
     * Les retours déjà donnés, indexés par triage — pour que l'écran montre ce qui a été dit.
     *
     * @param  iterable<int, Triage>  $triages
     * @return array<int, mixed>
     */
    private function retoursDonnes(iterable $triages): array
    {
        $service = app(ServiceRetourTriage::class);
        $retours = [];

        foreach ($triages as $triage) {
            $retours[$triage->id] = $service->retoursDe($triage);
        }

        return $retours;
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
