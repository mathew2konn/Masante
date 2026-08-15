<?php

namespace App\Http\Controllers\Portail;

use App\Http\Controllers\Controller;
use App\Models\NumeroUrgence;
use App\Services\Urgence\ServiceNumerosUrgence;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * P6.8e — Le référentiel des numéros d'urgence, côté portail (CDC_09 §8).
 *
 * ═══ POURQUOI UNE PERMISSION NEUVE ═══
 *
 * Douzième occurrence du précédent posé par `urgence.bris_de_glace` : `urgence.referentiel` n'est
 * portée par **aucun rôle**. Un numéro d'urgence est attribué par un plan national de numérotation —
 * aucun établissement, aucun opérateur, aucune caisse n'a qualité pour en décider.
 *
 * Et l'enjeu est plus direct qu'ailleurs : un code de spécialité faux produit une liste vide, un
 * numéro d'agrément faux produit une incohérence de guichet. **Un numéro d'urgence faux produit un
 * appel qui n'aboutit nulle part, composé par quelqu'un devant un blessé.**
 *
 * ═══ CE QUE L'ÉCRAN AFFICHE, ET QU'IL SERAIT MALHONNÊTE DE TAIRE ═══
 *
 * Trois témoins, comptés et non estimés :
 *
 *   1. **le nombre de numéros dont personne n'a vérifié la provenance** — les trois du jeu livré
 *      portent `declaration_projet` : le SAMU vient du corpus, le 100 et le 180 d'une déclaration du
 *      propriétaire, et **aucun n'a été confronté à un arrêté** ;
 *   2. **l'état de mise en vigueur** — c'est le seul écran du projet qui doit dire « aucune version
 *      publiée » comme un **fait d'exploitation**, parce que dans cet état les téléphones composent
 *      la valeur qu'ils ont en mémoire, et que personne ne le verrait autrement ;
 *   3. **la valeur de repli livrée avec l'application**, affichée telle quelle. Un exploitant doit
 *      savoir ce qui est composé quand rien ne l'est de son fait.
 *
 * ═══ CET ÉCRAN N'EST PAS LA GOUVERNANCE ═══
 *
 * Écrire ici modifie le **contenu de travail**. Il faut ensuite proposer puis publier (§10,
 * quatre-yeux) pour que la version diffusée change — et tant que ce n'est pas fait, ni l'API, ni le
 * texte de triage, ni les téléphones ne voient quoi que ce soit.
 */
class ReferentielNumeroUrgenceController extends Controller
{
    /**
     * Les provenances admises — miroir de l'ENUM `source`.
     *
     * `declaration_projet` dit exactement ce qui s'est passé pour les trois entrées livrées :
     * *quelqu'un d'identifié les a déclarées, et personne ne les a vérifiées*. `autorite_nationale`
     * affirmerait une vérification qui n'a pas eu lieu ; `demonstration` dirait qu'elles sont
     * inventées, ce qui serait faux aussi.
     */
    public const SOURCES = [
        'demonstration'      => 'Démonstration (valeur fictive)',
        'declaration_projet' => 'Déclaration du projet (non vérifiée auprès d\'une autorité)',
        'autorite_nationale' => 'Autorité nationale (Ministère, régulateur télécom)',
        'publication'        => 'Publication officielle (arrêté, journal officiel)',
    ];

    public function __construct(private readonly ServiceNumerosUrgence $numeros) {}

    public function index(): View
    {
        return view('portail.numeros-urgence.index', [
            'numeros' => NumeroUrgence::query()
                ->orderBy('pays_code')
                ->ordonne()
                ->paginate(50),
            'total' => NumeroUrgence::count(),
            // LE TÉMOIN CENTRAL DE CE MODULE : combien de numéros nul n'a confrontés à une
            // publication officielle. Il ne bloque pas — l'exiger rendrait le référentiel
            // impubliable dès le premier jour (motif `code_cim10`, P6.8c) — mais il dit ce que ce
            // contenu est.
            'nonVerifies' => NumeroUrgence::whereIn('source', ['demonstration', 'declaration_projet'])->count(),
            'actifs'      => NumeroUrgence::where('actif', true)->count(),
            // L'état d'exploitation, dit sans repli (`estEnVigueur()` ne journalise ni ne replie).
            'enVigueur' => $this->numeros->estEnVigueur(),
            'version'   => $this->numeros->version(),
            // Ce que composent les téléphones quand rien n'est publié. L'afficher n'est pas
            // décoratif : c'est la seule façon pour un exploitant de savoir ce qui sort de
            // l'application sans son intervention.
            'repli'   => ServiceNumerosUrgence::REPLI,
            'sources' => self::SOURCES,
        ]);
    }

    public function create(): View
    {
        return view('portail.numeros-urgence.create', ['sources' => self::SOURCES]);
    }

    public function store(Request $request): RedirectResponse
    {
        $numero = new NumeroUrgence();
        $numero->fill($this->valider($request));

        // `code` et `pays_code` sont hors `$fillable` : un terme de nomenclature nationale ne se
        // choisit pas au formulaire par le client (précédent constant depuis P6.4a). Ils sont posés
        // ici, par le serveur, à partir de champs validés contre une forme fermée.
        $numero->code      = $this->codeValide($request);
        $numero->pays_code = $this->pays($request);
        $numero->save();

        return redirect()->route('portail.numeros-urgence.edit', $numero)
            ->with('succes', "« {$numero->libelle} » ajouté au contenu de travail. Rien n'est "
                .'diffusé avant publication d\'une nouvelle version du référentiel.');
    }

    public function edit(NumeroUrgence $numero): View
    {
        return view('portail.numeros-urgence.edit', [
            'numero'  => $numero,
            'sources' => self::SOURCES,
        ]);
    }

    public function update(Request $request, NumeroUrgence $numero): RedirectResponse
    {
        // Le CODE N'EST PAS MODIFIABLE — même raisonnement qu'en P6.8a pour les spécialités : c'est
        // par lui que le mobile et le triage demandent un numéro précis (`samu`). Le renommer
        // laisserait ces appelants désigner un terme disparu **sans lever d'erreur**, et le repli
        // jouerait en silence. Un terme qui ne convient plus se désactive.
        $numero->update($this->valider($request, $numero));

        return redirect()->route('portail.numeros-urgence.edit', $numero)
            ->with('succes', 'Numéro enregistré. Il ne sera composé qu\'après publication d\'une '
                .'nouvelle version du référentiel.');
    }

    /**
     * @return array<string, mixed>
     */
    private function valider(Request $request, ?NumeroUrgence $numero = null): array
    {
        $donnees = $request->validate([
            // La forme est fermée ET le message dit pourquoi : un numéro qu'un téléphone ne sait
            // pas composer est un bouton mort, et l'agent a l'information sous les yeux au moment
            // où il le saisit (passage de la détection à l'interdiction, motif P6.4d).
            'numero' => ['required', 'string', 'max:20', 'regex:/^[+0-9][0-9 .\-*#]*$/'],
            // C'est ce qu'un citoyen lit pour savoir lequel composer.
            'libelle'       => ['required', 'string', 'max:120'],
            'description'   => ['nullable', 'string', 'max:255'],
            'ordre'         => ['required', 'integer', 'min:0', 'max:9999'],
            'source'        => ['required', Rule::in(array_keys(self::SOURCES))],
            'source_detail' => ['nullable', 'string', 'max:200'],
            'actif'         => ['nullable', 'boolean'],
        ], [
            'numero.regex' => 'Ce numéro ne peut pas être composé par un téléphone : seuls les '
                .'chiffres, le « + » international, « * », « # », l\'espace, le point et le tiret '
                .'sont admis.',
        ]);

        $donnees['actif'] = $numero === null ? true : $request->boolean('actif');

        return $donnees;
    }

    private function pays(Request $request): string
    {
        return strtoupper((string) ($request->input('pays_code')
            ?: config('referentiels.pays_defaut', 'CI')));
    }

    /**
     * Le code d'un numéro neuf, validé à part parce qu'il n'est pas `$fillable`.
     *
     * Forme fermée `[a-z_]`, unicité par pays vérifiée ICI en plus de `uq_numero_urgence_pays_code`
     * — pour que l'agent lise un message plutôt qu'une erreur de moteur. Deux gardes, deux publics,
     * aucune ne rattrape l'autre.
     */
    private function codeValide(Request $request): string
    {
        $pays = $this->pays($request);

        $valide = $request->validate([
            'code' => [
                'required', 'string', 'max:40', 'regex:/^[a-z][a-z_]*$/',
                Rule::unique('numeros_urgence', 'code')->where(fn ($q) => $q->where('pays_code', $pays)),
            ],
        ], [
            'code.regex'  => 'Le code doit être un terme en minuscules sans accent (samu, police, '
                .'pompiers) : c\'est par lui que les applications demandent un numéro précis.',
            'code.unique' => 'Ce code existe déjà pour ce pays.',
        ]);

        return $valide['code'];
    }
}
