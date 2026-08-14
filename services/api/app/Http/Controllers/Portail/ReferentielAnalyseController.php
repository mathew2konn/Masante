<?php

namespace App\Http\Controllers\Portail;

use App\Http\Controllers\Controller;
use App\Models\Analyse;
use App\Models\AnalyseReference;
use App\Services\Analyse\AttributeurCodeAnalyse;
use App\Support\Analyses;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * P6.7a — Le catalogue national des analyses, côté portail (CDC_09 §7.3).
 *
 * ═══ POURQUOI UNE PERMISSION NEUVE ═══
 *
 * Septième occurrence du précédent posé par `urgence.bris_de_glace` : `analyse.referentiel` n'est
 * portée par **aucun rôle**. La raison est celle qui a fait naître `medicament.referentiel` en
 * P6.6a, et elle est encore plus nette ici — **un laboratoire ne peut pas fixer les valeurs de
 * référence nationales** : il serait juge et partie sur les résultats qu'il rend lui-même.
 *
 * ═══ CE QUE CET ÉCRAN N'EST PAS ═══
 *
 * Ce n'est pas la gouvernance. Écrire ici modifie le **contenu de travail** ; il faut ensuite
 * proposer puis publier (§10, quatre-yeux) pour que la version en vigueur change. Le bandeau le dit.
 */
class ReferentielAnalyseController extends Controller
{
    public function __construct(private readonly AttributeurCodeAnalyse $attributeur)
    {
    }

    public function index(Request $request): View
    {
        $filtres = $request->validate([
            'q'         => ['nullable', 'string', 'max:120'],
            'categorie' => ['nullable', Rule::in(Analyses::categories())],
        ]);

        $analyses = Analyse::query()
            ->withCount('references')
            ->when($filtres['q'] ?? null, fn ($query, $q) => $query->where(
                fn ($sous) => $sous->where('libelle', 'like', "%{$q}%")->orWhere('code', 'like', "%{$q}%")
            ))
            ->when($filtres['categorie'] ?? null, fn ($query, $c) => $query->where('categorie', $c))
            ->orderBy('code')
            ->paginate(25)
            ->withQueryString();

        return view('portail.analyses.index', [
            'analyses'   => $analyses,
            'filtres'    => $filtres,
            'categories' => Analyses::CATEGORIES,
            'sansCode'   => Analyse::whereNull('code')->count(),
            // Combien de strates reposent encore sur le jeu de démonstration : le chiffre qui dit
            // où en est le remplacement par un référentiel biologique réel.
            'demonstration' => AnalyseReference::where('source', 'demonstration')->count(),
            'totalStrates'  => AnalyseReference::count(),
        ]);
    }

    public function edit(Analyse $analyse): View
    {
        return view('portail.analyses.edit', [
            'analyse'    => $analyse,
            'categories' => Analyses::CATEGORIES,
            'milieux'    => Analyses::MILIEUX,
            'sexes'      => Analyses::SEXES_STRATE,
            'etats'      => Analyses::ETATS,
            'sources'    => Analyses::SOURCES_REFERENCE,
            'strates'    => $analyse->references()->orderBy('etat_physiologique')->orderBy('age_min_jours')->get(),
        ]);
    }

    public function update(Request $request, Analyse $analyse): RedirectResponse
    {
        // `code` et `pays_code` ne figurent pas dans les règles : l'identifiant national ne se
        // choisit pas. `loinc` est saisissable — c'est une donnée d'interopérabilité qu'une
        // autorité renseigne, pas un identifiant que la plateforme attribue.
        $donnees = $request->validate([
            'libelle'                => ['required', 'string', 'max:200'],
            'loinc'                  => ['nullable', 'string', 'max:20'],
            'description'            => ['nullable', 'string', 'max:5000'],
            'categorie'              => ['nullable', Rule::in(Analyses::categories())],
            'milieu_preleve'         => ['nullable', Rule::in(Analyses::milieux())],
            'unite'                  => ['required', 'string', 'max:40'],
            'methode'                => ['nullable', 'string', 'max:200'],
            'conditions_prelevement' => ['nullable', 'string', 'max:2000'],
            'conservation'           => ['nullable', 'string', 'max:2000'],
            'delai_rendu_heures'     => ['nullable', 'integer', 'min:0', 'max:8760'],
            'actif'                  => ['nullable', 'boolean'],
        ]);

        $donnees['actif'] = $request->boolean('actif');

        $analyse->update($donnees);
        $this->attributeur->attribuer($analyse);

        return redirect()
            ->route('portail.analyses.edit', $analyse)
            ->with('succes', 'Fiche enregistrée. Elle ne sera diffusée qu\'après publication d\'une nouvelle version du catalogue.');
    }

    /** Ajoute une strate de référence. */
    public function ajouterStrate(Request $request, Analyse $analyse): RedirectResponse
    {
        $donnees = $request->validate([
            'sexe'               => ['required', Rule::in(Analyses::sexesStrate())],
            'age_min_jours'      => ['nullable', 'integer', 'min:0', 'max:45000'],
            'age_max_jours'      => ['nullable', 'integer', 'min:0', 'max:45000'],
            'etat_physiologique' => ['required', Rule::in(Analyses::etats())],
            'valeur_min'         => ['nullable', 'numeric'],
            'valeur_max'         => ['nullable', 'numeric'],
            'critique_bas'       => ['nullable', 'numeric'],
            'critique_haut'      => ['nullable', 'numeric'],
            'libelle_strate'     => ['required', 'string', 'max:120'],
            // OBLIGATOIRE : un intervalle biologique sans provenance est une rumeur, et un
            // référentiel national ne publie pas de rumeur.
            'source'             => ['required', Rule::in(Analyses::sourcesReference())],
            'source_detail'      => ['nullable', 'string', 'max:200'],
        ]);

        // `?? null` et non un accès direct : une règle `nullable` n'ajoute PAS la clé au tableau
        // validé quand le client l'omet — le contrôleur plantait alors sur « Undefined array key »
        // au lieu d'afficher son message. Trouvé par le vecteur de la strate sans borne.
        $min = $donnees['valeur_min'] ?? null;
        $max = $donnees['valeur_max'] ?? null;

        if ($min === null && $max === null) {
            return back()->withInput()->withErrors([
                'valeur_min' => 'Une strate doit porter au moins une borne — sinon elle n\'affirme rien.',
            ]);
        }

        if ($min !== null && $max !== null && $min > $max) {
            return back()->withInput()->withErrors([
                'valeur_min' => 'La borne basse ne peut pas dépasser la borne haute.',
            ]);
        }

        $analyse->references()->create($donnees);

        return redirect()
            ->route('portail.analyses.edit', $analyse)
            ->with('succes', 'Strate de référence ajoutée.');
    }

    public function retirerStrate(Analyse $analyse, AnalyseReference $reference): RedirectResponse
    {
        // La strate doit appartenir à CETTE analyse : sans ce contrôle, l'identifiant de l'URL
        // permettrait de supprimer n'importe quelle strate depuis n'importe quelle fiche.
        abort_unless((int) $reference->analyse_id === (int) $analyse->id, 404);

        $reference->delete();

        return redirect()
            ->route('portail.analyses.edit', $analyse)
            ->with('succes', 'Strate retirée du contenu de travail.');
    }
}
