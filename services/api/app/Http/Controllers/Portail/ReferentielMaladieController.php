<?php

namespace App\Http\Controllers\Portail;

use App\Http\Controllers\Controller;
use App\Models\AlerteEpidemique;
use App\Models\Antecedent;
use App\Models\LibelleMaladie;
use App\Models\Maladie;
use App\Models\SurveillanceMaladie;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * P6.8c — Le référentiel des maladies, côté portail (CDC_09 §8).
 *
 * ═══ POURQUOI UNE PERMISSION NEUVE ═══
 *
 * Dixième occurrence du précédent posé par `urgence.bris_de_glace` : `maladie.referentiel` n'est
 * portée par **aucun rôle**. `sante_publique.manage` existe déjà et sert à PUBLIER LES ALERTES ;
 * l'étendre au vocabulaire ferait de **l'auteur d'une alerte celui qui décide de ce qu'est une
 * maladie** — et de la liste de ce que le pays surveille.
 *
 * ═══ CE QUE L'ÉCRAN AFFICHE, ET QU'IL SERAIT MALHONNÊTE DE TAIRE ═══
 *
 * Le compte EXACT des entrées de démonstration **et** celui des entrées sans code CIM. Le second ne
 * bloque rien — l'exiger rendrait le référentiel impubliable dès le premier jour — mais il dit ce
 * que ce contenu est : *une donnée de démonstration qui ne se signale pas finit par être prise pour
 * une donnée de référence* (motif P6.7a).
 *
 * ═══ CET ÉCRAN N'EST PAS LA GOUVERNANCE ═══
 *
 * Écrire ici modifie le **contenu de travail**. Il faut ensuite proposer puis publier (§10,
 * quatre-yeux) pour que la version diffusée change — et tant que ce n'est pas fait, ni l'alerte, ni
 * le carnet, ni l'API ne voient quoi que ce soit. Le bandeau de la vue le dit.
 */
class ReferentielMaladieController extends Controller
{
    /** Les provenances admises — miroir des ENUM `source` des trois tables. */
    public const SOURCES = [
        'demonstration'      => 'Démonstration (non validée)',
        'autorite_nationale' => 'Autorité nationale (Ministère)',
        'oms'                => 'Organisation mondiale de la santé',
        'societe_savante'    => 'Société savante',
        'publication'        => 'Publication scientifique',
    ];

    public function index(): View
    {
        return view('portail.maladies.index', [
            'maladies' => Maladie::query()
                ->with(['libelles', 'surveillances'])
                ->orderBy('libelle')
                ->paginate(50),
            // Les témoins, comptés et non estimés.
            'demonstration' => Maladie::where('source', 'demonstration')->count(),
            'total'         => Maladie::count(),
            // AUCUN code CIM n'a été chargé dans ce projet : le compte le dit au lieu de le taire.
            'sansCim'       => Maladie::whereNull('code_cim10')->whereNull('code_cim11')->count(),
            // Ce que le backfill n'a pas encore fait : sans code national, la publication refuse.
            'sansCode'      => Maladie::whereNull('code')->count(),
        ]);
    }

    public function create(): View
    {
        return view('portail.maladies.create', ['sources' => self::SOURCES]);
    }

    public function store(Request $request): RedirectResponse
    {
        $maladie = new Maladie();
        $maladie->fill($this->valider($request));
        // `code` est hors `$fillable` : le code national est ATTRIBUÉ par
        // `masante:maladies:backfill`, jamais choisi au formulaire (précédent ETS/PRO/MED/ANA/VAC).
        $maladie->save();

        return redirect()->route('portail.maladies.edit', $maladie)
            ->with('succes', "« {$maladie->libelle} » ajoutée au contenu de travail. Lancez "
                .'`masante:maladies:backfill` pour lui attribuer un code national. Rien n\'est '
                .'diffusé avant publication d\'une nouvelle version.');
    }

    public function edit(Maladie $maladie): View
    {
        return view('portail.maladies.edit', [
            'maladie' => $maladie->load(['libelles', 'surveillances', 'vaccins']),
            'sources' => self::SOURCES,
            'pivot'   => (string) config('referentiels.langue_pivot', 'fr'),
            // Combien de lignes la référencent : retirer une maladie n'est pas anodin, et l'écran
            // doit le dire AVANT, pas après (motif P6.8b).
            'alertes'     => AlerteEpidemique::where('maladie_id', $maladie->id)->count(),
            'antecedents' => Antecedent::where('maladie_id', $maladie->id)->count(),
        ]);
    }

    public function update(Request $request, Maladie $maladie): RedirectResponse
    {
        $maladie->update($this->valider($request, $maladie));

        return redirect()->route('portail.maladies.edit', $maladie)
            ->with('succes', 'Maladie enregistrée. Elle ne sera diffusée qu\'après publication '
                .'d\'une nouvelle version du référentiel.');
    }

    /**
     * Ajoute un libellé ALTERNATIF (autre langue ou synonyme).
     *
     * Le libellé officiel de la langue pivot n'est PAS modifiable ici : il vit sur la maladie. Le
     * moteur refuse d'ailleurs d'enregistrer un alternatif identique au libellé officiel — le
     * message ci-dessous nomme le problème à l'agent, le déclencheur le rend impossible.
     */
    public function enregistrerLibelle(Request $request, Maladie $maladie): RedirectResponse
    {
        $donnees = $request->validate([
            'langue'        => ['required', 'string', 'max:5', 'regex:/^[a-z]{2,3}$/'],
            'libelle'       => ['required', 'string', 'max:200'],
            'principal'     => ['nullable', 'boolean'],
            'source'        => ['required', Rule::in(array_keys(self::SOURCES))],
            'source_detail' => ['nullable', 'string', 'max:200'],
        ], [
            'langue.regex' => 'Utilisez une étiquette de langue courte : « en », « dyu », « bci »…',
        ]);

        $pivot = (string) config('referentiels.langue_pivot', 'fr');

        if (mb_strtolower(trim($donnees['libelle'])) === mb_strtolower($maladie->libelle)) {
            return back()->withInput()->withErrors([
                'libelle' => 'Ce libellé est déjà le libellé officiel de cette maladie : le stocker '
                    .'deux fois créerait deux endroits à corriger, et le second serait oublié.',
            ]);
        }

        $donnees['principal'] = $donnees['langue'] === $pivot ? false : $request->boolean('principal');

        $maladie->libelles()->updateOrCreate(
            ['langue' => $donnees['langue'], 'libelle' => $donnees['libelle']],
            $donnees,
        );

        return redirect()->route('portail.maladies.edit', $maladie)
            ->with('succes', $donnees['langue'] === $pivot
                ? "« {$donnees['libelle']} » enregistré comme SYNONYME de recherche : en langue "
                    ."« {$pivot} », le libellé officiel est celui de la maladie."
                : "Libellé « {$donnees['libelle']} » enregistré pour la langue « {$donnees['langue']} ».");
    }

    public function supprimerLibelle(Maladie $maladie, LibelleMaladie $libelle): RedirectResponse
    {
        // Scopé à la maladie : un libellé d'une autre entrée renvoie 404, jamais une suppression
        // transversale (même principe anti-IDOR que les sections du carnet).
        if ($libelle->maladie_id !== $maladie->id) {
            abort(404);
        }

        $libelle->delete();

        return redirect()->route('portail.maladies.edit', $maladie)
            ->with('succes', 'Libellé retiré du contenu de travail.');
    }

    /** Déclare — ou met à jour — ce qu'un pays surveille. */
    public function enregistrerSurveillance(Request $request, Maladie $maladie): RedirectResponse
    {
        $donnees = $request->validate([
            'pays_code'                => ['required', 'string', 'size:2', 'regex:/^[A-Za-z]{2}$/'],
            'declaration_obligatoire'  => ['nullable', 'boolean'],
            'surveillance_prioritaire' => ['nullable', 'boolean'],
            'source'                   => ['required', Rule::in(array_keys(self::SOURCES))],
            'source_detail'            => ['nullable', 'string', 'max:200'],
        ]);

        $donnees['pays_code']                = strtoupper($donnees['pays_code']);
        $donnees['declaration_obligatoire']  = $request->boolean('declaration_obligatoire');
        $donnees['surveillance_prioritaire'] = $request->boolean('surveillance_prioritaire');

        $maladie->surveillances()->updateOrCreate(['pays_code' => $donnees['pays_code']], $donnees);

        return redirect()->route('portail.maladies.edit', $maladie)
            ->with('succes', "Surveillance enregistrée pour {$donnees['pays_code']}.");
    }

    public function supprimerSurveillance(Maladie $maladie, SurveillanceMaladie $surveillance): RedirectResponse
    {
        if ($surveillance->maladie_id !== $maladie->id) {
            abort(404);
        }

        $surveillance->delete();

        return redirect()->route('portail.maladies.edit', $maladie)
            ->with('succes', 'Statut de surveillance retiré : ce pays ne déclare plus rien sur '
                .'cette maladie.');
    }

    /**
     * @return array<string, mixed>
     */
    private function valider(Request $request, ?Maladie $maladie = null): array
    {
        $donnees = $request->validate([
            // Unicité déclarative en base (`uq_maladie_libelle`) ET ici, pour que l'agent lise un
            // message plutôt qu'une erreur de moteur : deux maladies au même libellé seraient
            // indiscernables dans la liste d'une alerte.
            'libelle' => [
                'required', 'string', 'max:200',
                Rule::unique('maladies', 'libelle')->ignore($maladie?->id),
            ],
            'description'   => ['nullable', 'string', 'max:2000'],
            'source'        => ['required', Rule::in(array_keys(self::SOURCES))],
            'source_detail' => ['nullable', 'string', 'max:200'],
            'actif'         => ['nullable', 'boolean'],
        ]);

        $donnees['actif'] = $maladie === null ? true : $request->boolean('actif');

        return $donnees;
    }
}
