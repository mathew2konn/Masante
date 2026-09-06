<?php

namespace App\Http\Controllers\Portail;

use App\Http\Controllers\Controller;
use App\Models\Prelevement;
use App\Services\Analyse\ReglesCode128;
use App\Services\Analyse\ServiceCircuitPrelevement;
use App\Services\Analyse\ServiceValidationBiologique;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * B5-b/B5-c — L'écran du laboratoire : lire une demande par jeton, enregistrer et suivre un
 * prélèvement jusqu'à la publication du résultat, SANS jamais ouvrir de session de dossier
 * (L3, CDC_09 §7.4).
 *
 * Même posture que {@see DelivranceController} (B3-a) : **404 ET JAMAIS 403** sur un jeton
 * inconnu — un 403 confirmerait qu'une demande existe là.
 */
class LaboratoireController extends Controller
{
    public function __construct(
        private readonly ServiceCircuitPrelevement $circuit,
        private readonly ServiceValidationBiologique $validation,
    ) {}

    /** Le formulaire de saisie du jeton. */
    public function index(): View
    {
        return view('portail.laboratoire.index');
    }

    /** La demande désignée par le jeton — ce qu'il faut pour enregistrer un prélèvement. */
    public function montrer(Request $request): View
    {
        $demande = $this->circuit->demandePourJeton($request->query('jeton'));

        abort_if($demande === null, Response::HTTP_NOT_FOUND);

        $this->circuit->journaliserConsultation($request->user(), $demande);

        return view('portail.laboratoire.montrer', [
            'demande' => $demande,
            'jeton' => (string) $request->query('jeton'),
        ]);
    }

    /** Enregistre le prélèvement, puis redirige vers son suivi. */
    public function enregistrer(Request $request): RedirectResponse
    {
        $valide = $request->validate(['jeton' => ['required', 'string']]);

        $demande = $this->circuit->demandePourJeton($valide['jeton']);

        abort_if($demande === null, Response::HTTP_NOT_FOUND);

        try {
            $prelevement = $this->circuit->enregistrer($request->user(), $demande);
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors())->withInput();
        }

        return redirect()
            ->route('portail.laboratoire.prelevement', $prelevement)
            ->with('succes', 'Prélèvement enregistré : '.$prelevement->identifiant);
    }

    /** Le travail en cours de CE laboratoire. */
    public function travail(Request $request): View
    {
        return view('portail.laboratoire.travail', [
            'prelevements' => $this->circuit->travailPour($request->user()),
        ]);
    }

    /** Le détail d'un prélèvement — anti-IDOR porté par le service (404, jamais 403). */
    public function prelevement(Request $request, Prelevement $prelevement): View
    {
        $this->assertAppartientAuLaboratoire($request, $prelevement);

        return view('portail.laboratoire.prelevement', ['prelevement' => $prelevement->fresh('demande.lignes')]);
    }

    /** L'étiquette imprimable (SVG, L16) — même garde d'appartenance que le détail. */
    public function etiquette(Request $request, Prelevement $prelevement): HttpResponse
    {
        $this->assertAppartientAuLaboratoire($request, $prelevement);

        return response(ReglesCode128::svg($prelevement->identifiant), 200, [
            'Content-Type' => 'image/svg+xml',
        ]);
    }

    public function expedier(Request $request, Prelevement $prelevement): RedirectResponse
    {
        return $this->transitionner($request, $prelevement, 'expedier', 'Prélèvement marqué expédié.');
    }

    public function recevoir(Request $request, Prelevement $prelevement): RedirectResponse
    {
        return $this->transitionner($request, $prelevement, 'recevoir', 'Prélèvement marqué reçu.');
    }

    public function mettreEnAnalyse(Request $request, Prelevement $prelevement): RedirectResponse
    {
        return $this->transitionner($request, $prelevement, 'mettreEnAnalyse', 'Prélèvement mis en analyse.');
    }

    /**
     * Saisie manuelle du résultat (B5-c, étape 6 du §7.4) — un formulaire pré-rempli PAR LIGNE de
     * la demande : le biologiste/laborantin ne saisit que la valeur, jamais l'identité de
     * l'examen. Les lignes laissées vides sont IGNORÉES, jamais transformées en résultat inventé
     * (CDC_00 §4) — un résultat partiel reste un résultat honnête.
     */
    public function saisirResultat(Request $request, Prelevement $prelevement): RedirectResponse
    {
        $this->assertAppartientAuLaboratoire($request, $prelevement);

        $brutes = (array) $request->input('valeurs', []);
        $valeurs = array_values(array_filter(
            $brutes,
            static fn ($v): bool => is_array($v) && trim((string) ($v['valeur'] ?? '')) !== '',
        ));

        try {
            $valide = validator($valeurs, [
                '*.parametre' => ['required', 'string', 'max:200'],
                '*.valeur' => ['required', 'string', 'max:120'],
                '*.unite' => ['nullable', 'string', 'max:40'],
                '*.analyse_id' => ['nullable', 'integer'],
            ])->validate();

            $this->validation->saisir($request->user(), $prelevement, $valide);
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors());
        }

        return redirect()->route('portail.laboratoire.prelevement', $prelevement)
            ->with('succes', 'Résultat saisi. En attente de validation biologique.');
    }

    /** Le verdict qui valide (étape 7 du §7.4) — le verrou : aucun résultat non validé ne se publie. */
    public function valider(Request $request, Prelevement $prelevement): RedirectResponse
    {
        $this->assertAppartientAuLaboratoire($request, $prelevement);

        try {
            $this->validation->valider($request->user(), $prelevement);
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors());
        }

        return redirect()->route('portail.laboratoire.prelevement', $prelevement)
            ->with('succes', 'Résultat validé.');
    }

    /** Le verdict qui rejette : efface le brouillon, journalise, exige son motif (M4). */
    public function rejeter(Request $request, Prelevement $prelevement): RedirectResponse
    {
        $this->assertAppartientAuLaboratoire($request, $prelevement);

        $valide = $request->validate(['motif' => ['required', 'string', 'max:500']]);

        try {
            $this->validation->rejeter($request->user(), $prelevement, $valide['motif']);
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors())->withInput();
        }

        return redirect()->route('portail.laboratoire.prelevement', $prelevement)
            ->with('succes', 'Résultat rejeté : une nouvelle saisie est attendue.');
    }

    /** Étape 8 du §7.4 — transmission au dossier patient. Exige un prélèvement `valide` (L7). */
    public function publier(Request $request, Prelevement $prelevement): RedirectResponse
    {
        $this->assertAppartientAuLaboratoire($request, $prelevement);

        try {
            $this->validation->publier($request->user(), $prelevement);
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors());
        }

        return redirect()->route('portail.laboratoire.prelevement', $prelevement)
            ->with('succes', 'Résultat publié dans le carnet du patient.');
    }

    private function transitionner(
        Request $request,
        Prelevement $prelevement,
        string $methode,
        string $messageSucces,
    ): RedirectResponse {
        try {
            $this->circuit->{$methode}($request->user(), $prelevement);
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors());
        }

        return redirect()
            ->route('portail.laboratoire.prelevement', $prelevement)
            ->with('succes', $messageSucces);
    }

    /**
     * Anti-IDOR AVANT toute lecture du détail — le service la revérifie de toute façon à chaque
     * transition, mais un laboratoire d'une autre structure ne doit pas même VOIR la fiche.
     */
    private function assertAppartientAuLaboratoire(Request $request, Prelevement $prelevement): void
    {
        $structureId = $request->user()?->structure_id;

        abort_if(! $prelevement->appartientA($structureId), Response::HTTP_NOT_FOUND);
    }
}
