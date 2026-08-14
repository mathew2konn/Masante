<?php

namespace App\Http\Controllers\Portail;

use App\Http\Controllers\Controller;
use App\Models\Medecin;
use App\Models\ServiceEtablissement;
use App\Models\SpecialiteMedicale;
use App\Support\ProfessionsSante;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * P6.8a — Le vocabulaire national des spécialités, côté portail (CDC_09 §8).
 *
 * ═══ POURQUOI UNE PERMISSION NEUVE ═══
 *
 * Huitième occurrence du précédent posé par `urgence.bris_de_glace` : `specialite.referentiel`
 * n'est portée par **aucun rôle**. `service.manage` appartient au gestionnaire pour décrire les
 * services de SON établissement ; l'étendre au vocabulaire national laisserait chaque hôpital
 * ajouter le terme qui l'arrange, et la liste nationale deviendrait la somme des ambitions de
 * chacun. C'est aussi ce qui rendrait insoluble la question du §4.4 — « combien de services de
 * cardiologie dans ce district ? » — puisque « cardio » et « cardiologie » y coexisteraient.
 *
 * ═══ LE CODE EST IMMUABLE APRÈS CRÉATION ═══
 *
 * Le libellé se corrige, le code non. `services_etablissement.specialite` porte le code EN TEXTE —
 * c'est sur lui que le filtre `?specialite=orl` de P3 compare en égalité exacte. Renommer `orl` en
 * `oto_rhino_laryngologie` laisserait donc tous les services existants désigner un terme qui
 * n'existe plus, et le lien de l'annuaire cesserait de répondre sans qu'aucune erreur ne soit
 * levée. Un terme qui ne convient plus se DÉSACTIVE et un autre le remplace.
 *
 * ═══ CET ÉCRAN N'EST PAS LA GOUVERNANCE ═══
 *
 * Écrire ici modifie le **contenu de travail**. Il faut ensuite proposer puis publier (§10,
 * quatre-yeux) pour que la version diffusée change. Le bandeau de la vue le dit.
 */
class ReferentielSpecialiteController extends Controller
{
    public function index(): View
    {
        $termes = SpecialiteMedicale::query()
            ->withCount(['services', 'praticiens'])
            ->ordonnee()
            ->paginate(50);

        return view('portail.specialites.index', [
            'termes'      => $termes,
            'professions' => ProfessionsSante::PROFESSIONS,
            // Ce que le backfill n'a pas pu rattacher : des services dont le code n'est pas au
            // vocabulaire. Ils existent parce que le formulaire acceptait n'importe quel mot avant
            // P6.8a. On les compte plutôt que de les taire — sinon ils resteraient invisibles.
            'servicesOrphelins' => ServiceEtablissement::whereNull('specialite_id')->count(),
            // L'ÉCART ASSUMÉ. Le backfill ne réécrit aucun libellé de praticien (« Maternité » reste
            // « Maternité ») : un serveur qui réécrit une déclaration humaine se trompe avec
            // autorité — leçon de P6.7b. L'écart est donc SIGNALÉ ici, là où quelqu'un peut le
            // corriger en connaissance de cause, plutôt que corrigé en silence.
            'praticiensDesynchronises' => $this->praticiensDesynchronises(),
        ]);
    }

    public function create(): View
    {
        return view('portail.specialites.create', ['professions' => ProfessionsSante::PROFESSIONS]);
    }

    public function store(Request $request): RedirectResponse
    {
        $pays = config('referentiels.pays_defaut', 'CI');

        $donnees = $request->validate([
            // Le code n'est saisi QU'ICI, à la création, et par une autorité. C'est la différence
            // avec `ETS`/`PRO`/`MED`/`ANA`, que la plateforme attribue : un terme de nomenclature
            // n'a pas de numéro, son code EST son identité (précédent `regions.code`, P6.4a).
            'code' => [
                'required', 'string', 'max:60', 'regex:/^[a-z][a-z0-9_]*$/',
                Rule::unique('specialites_medicales', 'code')->where('pays_code', $pays),
            ],
            'libelle'     => ['required', 'string', 'max:120'],
            'nature'      => ['required', Rule::in(['specialite_medicale', 'activite'])],
            'profession'  => ['nullable', Rule::in(array_keys(ProfessionsSante::PROFESSIONS))],
            'description' => ['nullable', 'string', 'max:255'],
            'ordre'       => ['nullable', 'integer', 'min:0', 'max:9999'],
        ], [
            'code.regex'  => 'Le code doit commencer par une lettre minuscule et ne contenir que '
                .'des minuscules, des chiffres et des soulignés (ex. medecine_generale).',
            'code.unique' => 'Ce code existe déjà dans le vocabulaire.',
        ]);

        $terme = new SpecialiteMedicale();
        $terme->fill([
            'libelle'     => $donnees['libelle'],
            'nature'      => $donnees['nature'],
            'profession'  => $donnees['profession'] ?? null,
            'description' => $donnees['description'] ?? null,
            'ordre'       => $donnees['ordre'] ?? 100,
            'actif'       => true,
        ]);
        // `code` et `pays_code` sont hors `$fillable` : ils passent par `forceFill`, seul endroit du
        // module où ils sont écrits.
        $terme->forceFill(['code' => $donnees['code'], 'pays_code' => $pays])->save();

        return redirect()->route('portail.specialites.index')
            ->with('succes', "Terme « {$terme->libelle} » ajouté au contenu de travail. "
                .'Il ne sera diffusé qu\'après publication d\'une nouvelle version du référentiel.');
    }

    public function edit(SpecialiteMedicale $specialite): View
    {
        return view('portail.specialites.edit', [
            'terme'       => $specialite,
            'professions' => ProfessionsSante::PROFESSIONS,
            'services'    => $specialite->services()->count(),
            'praticiens'  => $specialite->praticiens()->count(),
        ]);
    }

    public function update(Request $request, SpecialiteMedicale $specialite): RedirectResponse
    {
        // `code` et `pays_code` ne figurent PAS dans les règles : voir l'en-tête de classe. Un code
        // envoyé quand même est écarté par `validate()` puis jamais repris.
        $donnees = $request->validate([
            'libelle'     => ['required', 'string', 'max:120'],
            'nature'      => ['required', Rule::in(['specialite_medicale', 'activite'])],
            'profession'  => ['nullable', Rule::in(array_keys(ProfessionsSante::PROFESSIONS))],
            'description' => ['nullable', 'string', 'max:255'],
            'ordre'       => ['nullable', 'integer', 'min:0', 'max:9999'],
            'actif'       => ['nullable', 'boolean'],
        ]);

        $donnees['ordre'] = $donnees['ordre'] ?? $specialite->ordre;
        $donnees['actif'] = $request->boolean('actif');

        $specialite->update($donnees);

        return redirect()->route('portail.specialites.edit', $specialite)
            ->with('succes', 'Terme enregistré. Il ne sera diffusé qu\'après publication d\'une '
                .'nouvelle version du référentiel.');
    }

    /**
     * Les praticiens dont le libellé affiché ne correspond plus à celui de leur terme.
     *
     * Il ne peut y en avoir que de deux façons : une fiche antérieure à P6.8a (rattachée par le
     * backfill, libellé d'origine conservé), ou un libellé renommé au référentiel après coup.
     * Aucune n'est une anomalie de saisie — d'où le signalement, et non le blocage : le contrôle
     * qualité de `SourceSpecialites` ne refuse pas la publication pour autant, car le vocabulaire,
     * lui, est parfaitement valide.
     */
    private function praticiensDesynchronises(): int
    {
        return Medecin::query()
            ->whereNotNull('specialite_id')
            ->whereHas('specialiteReferencee', fn ($q) => $q->whereColumn(
                'specialites_medicales.libelle', '!=', 'medecins.specialite',
            ))
            ->count();
    }
}
