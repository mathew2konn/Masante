<?php

namespace App\Http\Controllers\Portail;

use App\Http\Controllers\Controller;
use App\Models\AlerteEpidemique;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Module 5 / 5.4 — Gestion des alertes épidémiques (CdC FN3), admin IVOIRSANTÉ uniquement
 * (`permission:sante_publique.manage`).
 *
 * L'admin reporte les bulletins OMS / Ministère de la Santé CI. Une alerte se désactive plutôt
 * qu'elle ne se supprime : on conserve l'historique des épisodes épidémiques.
 */
class AlerteEpidemiqueController extends Controller
{
    public const NIVEAUX = [
        'information' => 'Information',
        'vigilance'   => 'Vigilance',
        'alerte'      => 'Alerte',
    ];

    /** Maladies à surveillance prioritaire en Côte d'Ivoire (CdC FN3) ; champ libre malgré tout. */
    public const MALADIES = ['Paludisme', 'Choléra', 'Méningite', 'Fièvre typhoïde', 'Dengue', 'Fièvre jaune', 'Rougeole'];

    public function index(): View
    {
        return view('portail.sante-publique.index', [
            'alertes' => AlerteEpidemique::with('publiePar:id,prenom,nom')
                ->orderByDesc('actif')
                ->orderByDesc('date_debut')
                ->paginate(20),
        ]);
    }

    public function create(): View
    {
        return view('portail.sante-publique.form', [
            'alerte'   => new AlerteEpidemique(['date_debut' => now()->toDateString(), 'niveau_alerte' => 'vigilance']),
            'niveaux'  => self::NIVEAUX,
            'maladies' => self::MALADIES,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $alerte = AlerteEpidemique::create([
            ...$this->valider($request),
            'publie_par_user_id' => auth()->id(),
        ]);

        return redirect()->route('portail.sante-publique.index')
            ->with('statut', "Alerte « {$alerte->titre} » publiée.");
    }

    public function edit(AlerteEpidemique $alerte): View
    {
        return view('portail.sante-publique.form', [
            'alerte'   => $alerte,
            'niveaux'  => self::NIVEAUX,
            'maladies' => self::MALADIES,
        ]);
    }

    public function update(Request $request, AlerteEpidemique $alerte): RedirectResponse
    {
        $alerte->update($this->valider($request));

        return redirect()->route('portail.sante-publique.index')->with('statut', 'Alerte mise à jour.');
    }

    /** Active / désactive sans supprimer (conservation de l'historique). */
    public function toggleActif(AlerteEpidemique $alerte): RedirectResponse
    {
        $alerte->update(['actif' => ! $alerte->actif]);

        return back()->with('statut', $alerte->actif ? 'Alerte réactivée.' : 'Alerte désactivée.');
    }

    /**
     * @return array<string, mixed>
     */
    private function valider(Request $request): array
    {
        // La portée est saisie via un choix (commune / nationale) + un champ texte : on en dérive
        // la valeur réelle de `commune` avant validation. Une alerte nationale porte la sentinelle.
        $request->merge([
            'commune' => $request->input('portee') === 'nationale'
                ? AlerteEpidemique::NATIONALE
                : trim((string) $request->input('commune_saisie')),
        ]);

        return $request->validate([
            'commune'       => ['required', 'string', 'max:100'],
            'titre'         => ['required', 'string', 'max:300'],
            'description'   => ['required', 'string', 'max:5000'],
            'maladie'       => ['required', 'string', 'max:100'],
            'niveau_alerte' => ['required', Rule::in(array_keys(self::NIVEAUX))],
            'source'        => ['required', 'string', 'max:200'],
            'date_debut'    => ['required', 'date'],
            'date_fin'      => ['nullable', 'date', 'after_or_equal:date_debut'],
            // `actif` n'est pas édité ici : l'activation passe par `toggleActif` (une case à cocher
            // décochée serait absente de la requête, donc ambiguë en mise à jour).
        ]);
    }
}
