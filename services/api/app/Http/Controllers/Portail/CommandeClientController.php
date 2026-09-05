<?php

namespace App\Http\Controllers\Portail;

use App\Http\Controllers\Controller;
use App\Models\Commande;
use App\Services\Medicament\ServiceTraitementCommande;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * B3-d — le pharmacien reçoit et traite les commandes de SON officine (CDC_11 §9.5).
 *
 * AUCUN IDENTIFIANT D'OFFICINE DANS L'URL pour la liste (patron `StockOfficineController`) : on
 * voit les commandes de SA structure, celle que porte le compte. Le détail, lui, porte l'id de la
 * commande — l'anti-IDOR est vérifié PAR `ServiceTraitementCommande` (404, jamais 403).
 */
class CommandeClientController extends Controller
{
    public function __construct(private readonly ServiceTraitementCommande $traitement) {}

    /** Les commandes de mon officine, les plus récentes d'abord. */
    public function index(Request $request): View
    {
        $officine = $request->user()->structure;
        abort_if($officine === null, 404);

        $commandes = Commande::with('membre:id,nom,prenom', 'lignes')
            ->where('structure_id', $officine->id)
            ->orderByRaw("CASE statut WHEN 'en_attente' THEN 0 WHEN 'acceptee' THEN 1 WHEN 'prete' THEN 2 ELSE 3 END")
            ->orderByDesc('created_at')
            ->get();

        return view('portail.commandes.index', ['commandes' => $commandes]);
    }

    public function show(Commande $commande): View
    {
        abort_if($commande->structure_id !== auth()->user()->structure_id, 404);

        return view('portail.commandes.show', [
            'commande' => $commande->load('membre:id,nom,prenom', 'lignes', 'ordonnance'),
        ]);
    }

    public function accepter(Request $request, Commande $commande): RedirectResponse
    {
        try {
            $this->traitement->accepter($request->user(), $commande);
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors());
        }

        return redirect()->route('portail.commandes.show', $commande)->with('succes', 'Commande acceptée.');
    }

    public function refuser(Request $request, Commande $commande): RedirectResponse
    {
        $valide = $request->validate(['motif' => ['required', 'string', 'max:300']]);

        try {
            $this->traitement->refuser($request->user(), $commande, $valide['motif']);
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors());
        }

        return redirect()->route('portail.commandes.show', $commande)->with('succes', 'Commande refusée.');
    }

    public function preparer(Request $request, Commande $commande): RedirectResponse
    {
        try {
            $this->traitement->preparer($request->user(), $commande);
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors());
        }

        return redirect()->route('portail.commandes.show', $commande)->with('succes', 'Commande prête.');
    }

    public function remettre(Request $request, Commande $commande): RedirectResponse
    {
        try {
            $this->traitement->remettre($request->user(), $commande);
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors());
        }

        return redirect()->route('portail.commandes.show', $commande)->with('succes', 'Commande remise.');
    }
}
