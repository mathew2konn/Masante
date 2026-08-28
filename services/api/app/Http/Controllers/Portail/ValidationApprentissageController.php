<?php

namespace App\Http\Controllers\Portail;

use App\Models\JeuDonneesEntrainement;
use App\Services\Triage\ServiceValidationApprentissage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * P10c-2-i (F4) — Revue médicale du jeu d'apprentissage (CDC_05 §7.2).
 *
 * L'habilitation qui fait AUTORITÉ est celle de {@see ServiceValidationApprentissage}, vérifiée en
 * service (piège de P4 sur `rdv.validate` : un middleware `permission:` posé sur le mauvais guard
 * laisse passer). Le middleware de ces routes n'évite qu'un écran inutile à qui n'est pas habilité.
 *
 * ═══ AUCUNE IDENTITÉ N'EST AFFICHÉE, ET C'EST LE POINT DU §7.2 ═══
 *
 * L'écran ne montre que ce que {@see JeuDonneesEntrainement} porte — âge, sexe, symptômes,
 * constantes, niveau rendu, label proposé. Rien qui permette de remonter au patient : c'est la
 * revue « sans savoir de qui il s'agit » que le corpus demande.
 */
class ValidationApprentissageController
{
    public function __construct(private readonly ServiceValidationApprentissage $service) {}

    public function index(): View
    {
        $enAttente = JeuDonneesEntrainement::query()
            ->whereDoesntHave('validation')
            ->latest('id')
            ->limit(50)
            ->get();

        return view('portail.apprentissage.index', ['lignes' => $enAttente]);
    }

    public function valider(JeuDonneesEntrainement $jeu, Request $request): RedirectResponse
    {
        try {
            $this->service->valider($request->user(), $jeu);
        } catch (\RuntimeException $e) {
            return back()->withErrors(['decision' => $e->getMessage()]);
        }

        return redirect()->route('portail.apprentissage.index')->with('statut', 'Ligne validée.');
    }

    public function rejeter(JeuDonneesEntrainement $jeu, Request $request): RedirectResponse
    {
        try {
            $this->service->rejeter($request->user(), $jeu, (string) $request->input('motif', ''));
        } catch (\RuntimeException $e) {
            return back()->withErrors(['decision' => $e->getMessage()]);
        }

        return redirect()->route('portail.apprentissage.index')->with('statut', 'Ligne rejetée.');
    }
}
