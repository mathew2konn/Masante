<?php

namespace App\Http\Controllers\Portail;

use App\Http\Controllers\Controller;
use App\Models\Prelevement;
use App\Services\Analyse\ReglesCode128;
use App\Services\Analyse\ServiceCircuitPrelevement;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * B5-b — L'écran du laboratoire : lire une demande par jeton, enregistrer et suivre un
 * prélèvement, SANS jamais ouvrir de session de dossier (L3, CDC_09 §7.4).
 *
 * Même posture que {@see DelivranceController} (B3-a) : **404 ET JAMAIS 403** sur un jeton
 * inconnu — un 403 confirmerait qu'une demande existe là.
 */
class LaboratoireController extends Controller
{
    public function __construct(private readonly ServiceCircuitPrelevement $circuit) {}

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
