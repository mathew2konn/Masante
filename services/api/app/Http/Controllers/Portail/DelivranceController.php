<?php

namespace App\Http\Controllers\Portail;

use App\Http\Controllers\Controller;
use App\Services\Medicament\ServiceCodeBarres;
use App\Services\Medicament\ServiceDelivrance;
use App\Services\Medicament\ServiceInteractions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * B3-a — le comptoir : servir une ordonnance présentée par un patient (CDC_11 §7.1).
 *
 * AUCUNE SESSION DE DOSSIER N'EST OUVERTE ICI. Le pharmacien atteint l'ordonnance par son JETON,
 * et il ne voit qu'elle : ni antécédents, ni vaccinations, ni résultats d'analyses. Ce n'est pas une
 * garde qu'on vérifie, c'est une porte qui n'existe pas — minimisation (loi 2013-450).
 *
 * **404 ET JAMAIS 403** sur un jeton inconnu : un 403 confirmerait qu'une ordonnance existe là, et
 * permettrait de balayer l'espace des jetons pour découvrir lesquels sont valides (patron P10a).
 */
class DelivranceController extends Controller
{
    public function __construct(
        private readonly ServiceDelivrance $delivrances,
        private readonly ServiceInteractions $interactions,
        private readonly ServiceCodeBarres $codesBarres,
    ) {}

    /** Le formulaire de saisie du jeton (ou de scan du QR patient). */
    public function index(): View
    {
        return view('portail.delivrance.index');
    }

    /** L'ordonnance désignée par le jeton, et ce qu'il faut pour la servir. */
    public function montrer(Request $request): View
    {
        $ordonnance = $this->delivrances->ordonnancePourJeton($request->query('jeton'));

        abort_if($ordonnance === null, Response::HTTP_NOT_FOUND);

        // §7.2 — les interactions sont CONSULTABLES, jamais calculées à la place du pharmacien :
        // le choix propriétaire de P6.6b (« consultation explicite ») n'est pas rouvert ici, et
        // calculer rapprocherait ce module d'une aide à la décision (CDC_05/CDC_08).
        $codes = $ordonnance->lignes->pluck('medicament_id')->filter()->values()->all();

        // B3-c (E6) — le champ de saisie EST le scanner : un lecteur de comptoir se comporte comme
        // un clavier, aucune dépendance ni caméra n'est nécessaire pour vérifier une boîte au
        // référentiel avant de la remettre au patient.
        $scan = trim((string) $request->query('scan', ''));

        return view('portail.delivrance.montrer', [
            'ordonnance' => $ordonnance,
            'jeton' => (string) $request->query('jeton'),
            'interactions' => count($codes) > 1 ? $this->interactions->entre($codes) : [],
            'scanSaisie' => $scan,
            'scanResultat' => $scan === '' ? null : $this->codesBarres->identifier($scan),
        ]);
    }

    /** Enregistre ce qui a été servi. */
    public function servir(Request $request): RedirectResponse
    {
        $valide = $request->validate([
            'jeton' => ['required', 'string'],
            'quantites' => ['required', 'array'],
            'quantites.*' => ['nullable', 'integer', 'min:0'],
        ], [
            'quantites.required' => 'Indiquez au moins un médicament servi.',
            'quantites.*.integer' => 'Une quantité servie est un nombre entier.',
            'quantites.*.min' => 'Une quantité servie ne peut pas être négative.',
        ]);

        $ordonnance = $this->delivrances->ordonnancePourJeton($valide['jeton']);

        abort_if($ordonnance === null, Response::HTTP_NOT_FOUND);

        try {
            $this->delivrances->delivrer($request->user(), $ordonnance, $valide['quantites']);
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors())->withInput();
        }

        return redirect()
            ->route('portail.delivrance.montrer', ['jeton' => $valide['jeton']])
            ->with('succes', 'Délivrance enregistrée.');
    }
}
