<?php

namespace App\Http\Controllers\Portail;

use App\Http\Controllers\Controller;
use App\Models\CouvertureMembre;
use App\Models\OrganismeAssurance;
use App\Support\TypesOrganismeAssurance;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * P6.8d — Le registre des organismes d'assurance, côté portail (CDC_09 §8).
 *
 * ═══ POURQUOI UNE PERMISSION NEUVE ═══
 *
 * Onzième occurrence du précédent posé par `urgence.bris_de_glace` : `assurance.referentiel` n'est
 * portée par **aucun rôle**. Et sa raison est la plus littérale de toutes — le rôle `assurance`
 * existe depuis P1 et désigne **précisément les organismes que ce registre recense**. La lui donner
 * ferait décider de la liste des organismes agréés par un assureur, juge et partie sur son propre
 * agrément. `gestionnaire_etablissement` non plus : il gère les conventions de SON établissement, et
 * la liste nationale deviendrait la somme des conventions de chacun.
 *
 * ═══ CE QUE L'ÉCRAN AFFICHE, ET QU'IL SERAIT MALHONNÊTE DE TAIRE ═══
 *
 * Le compte EXACT des entrées de démonstration **et** celui des organismes sans numéro d'agrément.
 * Le second ne bloque rien — l'exiger rendrait le référentiel impubliable dès le premier jour — mais
 * il dit ce que ce contenu est. Et un troisième témoin, propre à ce module : le nombre de
 * **couvertures citoyennes hors référentiel**, qui mesure ce que le registre ne couvre pas encore.
 *
 * ═══ CET ÉCRAN N'EST PAS LA GOUVERNANCE ═══
 *
 * Écrire ici modifie le **contenu de travail**. Il faut ensuite proposer puis publier (§10,
 * quatre-yeux) pour que la version diffusée change — et tant que ce n'est pas fait, ni l'API, ni
 * l'écran des couvertures, ni la carte ne voient quoi que ce soit.
 */
class ReferentielAssuranceController extends Controller
{
    /** Les provenances admises — miroir de l'ENUM `source`. */
    public const SOURCES = [
        'demonstration'      => 'Démonstration (non vérifiée)',
        'autorite_nationale' => 'Autorité nationale (Ministère, régulateur)',
        'publication'        => 'Publication officielle',
    ];

    /** Les états d'agrément — l'absence reste une réponse légitime (voir le formulaire). */
    public const STATUTS_AGREMENT = [
        'valide'    => 'Agrément valide',
        'suspendu'  => 'Agrément suspendu',
        'retire'    => 'Agrément retiré',
    ];

    public function index(): View
    {
        return view('portail.assurances.index', [
            'organismes' => OrganismeAssurance::query()
                ->orderBy('pays_code')
                ->orderBy('nom')
                ->paginate(50),
            // Les témoins, comptés et non estimés.
            'demonstration' => OrganismeAssurance::where('source', 'demonstration')->count(),
            'total'         => OrganismeAssurance::count(),
            // Aucun numéro d'agrément n'a été chargé dans ce projet : le compte le dit au lieu de le
            // taire. Il ne bloque pas la publication (motif `code_cim10`, P6.8c).
            'sansAgrement'  => OrganismeAssurance::whereNull('numero_agrement')->count(),
            // Ce que le backfill n'a pas encore fait : sans code national, la publication refuse.
            'sansCode'      => OrganismeAssurance::whereNull('code')->count(),
            // LE TÉMOIN PROPRE À CE MODULE (motif E4) : combien d'assurés ont dû saisir le nom de
            // leur organisme à la main parce qu'il n'est pas au registre. Il doit tendre vers zéro à
            // mesure que le registre réel est chargé — c'est ce qui distingue cet écart de celui des
            // alertes épidémiques, qui est structurel.
            'horsReferentiel' => CouvertureMembre::whereNull('organisme_assurance_id')->count(),
            'types'         => TypesOrganismeAssurance::TYPES,
        ]);
    }

    public function create(): View
    {
        return view('portail.assurances.create', [
            'sources' => self::SOURCES,
            'statuts' => self::STATUTS_AGREMENT,
            'types'   => TypesOrganismeAssurance::TYPES,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $organisme = new OrganismeAssurance();
        $organisme->fill($this->valider($request));
        // `code` est hors `$fillable` : le code national est ATTRIBUÉ par
        // `masante:assurances:backfill`, jamais choisi au formulaire (précédent ETS/PRO/MED/ANA/
        // VAC/MAL). `numero_agrement` l'est aussi — il désigne un acte administratif, pas une saisie.
        $organisme->save();

        return redirect()->route('portail.assurances.edit', $organisme)
            ->with('succes', "« {$organisme->nom} » ajouté au contenu de travail. Lancez "
                .'`masante:assurances:backfill` pour lui attribuer un code national. Rien n\'est '
                .'diffusé avant publication d\'une nouvelle version.');
    }

    public function edit(OrganismeAssurance $organisme): View
    {
        return view('portail.assurances.edit', [
            'organisme' => $organisme,
            'sources'   => self::SOURCES,
            'statuts'   => self::STATUTS_AGREMENT,
            'types'     => TypesOrganismeAssurance::TYPES,
            // Combien d'assurés le désignent : retirer un organisme n'est pas anodin, et l'écran
            // doit le dire AVANT, pas après (motif P6.8b/P6.8c).
            'couvertures' => CouvertureMembre::where('organisme_assurance_id', $organisme->id)->count(),
        ]);
    }

    public function update(Request $request, OrganismeAssurance $organisme): RedirectResponse
    {
        $organisme->update($this->valider($request, $organisme));

        return redirect()->route('portail.assurances.edit', $organisme)
            ->with('succes', 'Organisme enregistré. Il ne sera diffusé qu\'après publication d\'une '
                .'nouvelle version du référentiel.');
    }

    /**
     * @return array<string, mixed>
     */
    private function valider(Request $request, ?OrganismeAssurance $organisme = null): array
    {
        $pays = strtoupper((string) ($request->input('pays_code')
            ?? $organisme?->pays_code
            ?? config('referentiels.pays_defaut', 'CI')));

        $donnees = $request->validate([
            'pays_code' => ['nullable', 'string', 'size:2', 'regex:/^[A-Za-z]{2}$/'],
            // Unicité déclarative en base (`uq_organisme_nom_pays`) ET ici, pour que l'agent lise un
            // message plutôt qu'une erreur de moteur : deux organismes au même nom seraient
            // indiscernables dans la liste où un assuré choisit le sien.
            'nom' => [
                'required', 'string', 'max:200',
                Rule::unique('organismes_assurance', 'nom')
                    ->where(fn ($q) => $q->where('pays_code', $pays))
                    ->ignore($organisme?->id),
            ],
            'sigle' => ['nullable', 'string', 'max:30'],
            // Les six familles du §8.2, servies par une source unique — jamais recopiées.
            'type'  => ['required', TypesOrganismeAssurance::regleIn()],
            // NULLABLE, et c'est une réponse légitime : *un organisme sans agrément renseigné n'est
            // pas « probablement agréé »*. L'absence doit pouvoir se dire pour que l'écran ne
            // l'affirme pas (raisonnement d'`autorisation_statut`, P6.5a).
            'agrement_statut' => ['nullable', Rule::in(array_keys(self::STATUTS_AGREMENT))],
            'agrement_debut'  => ['nullable', 'date'],
            // Le moteur refuse déjà l'incohérence (déclencheur `ck_agrement_dates`) : ce contrôle-ci
            // la transforme en message d'écran. Deux gardes, deux publics, aucune ne rattrape
            // l'autre.
            'agrement_fin'    => ['nullable', 'date', 'after_or_equal:agrement_debut'],
            'source'          => ['required', Rule::in(array_keys(self::SOURCES))],
            'source_detail'   => ['nullable', 'string', 'max:200'],
            'actif'           => ['nullable', 'boolean'],
        ], [
            'agrement_fin.after_or_equal' => 'L\'agrément ne peut pas se terminer avant de commencer.',
        ]);

        $donnees['pays_code'] = $pays;
        $donnees['actif']     = $organisme === null ? true : $request->boolean('actif');

        return $donnees;
    }
}
