<?php

namespace App\Http\Controllers\Portail;

use App\Http\Controllers\Controller;
use App\Models\EcheanceVaccinale;
use App\Models\Maladie;
use App\Models\Vaccin;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * P6.8b — Le référentiel des vaccins et le calendrier vaccinal, côté portail (CDC_09 §8).
 *
 * ═══ POURQUOI UNE PERMISSION NEUVE ═══
 *
 * Neuvième occurrence du précédent posé par `urgence.bris_de_glace` : `vaccin.referentiel` n'est
 * portée par **aucun rôle**. Un centre de vaccination ne peut pas décider à quel âge une dose est
 * due ni si elle est obligatoire — il serait juge et partie sur ce qu'il administre. Et le
 * caractère obligatoire d'un vaccin engage l'État, pas un établissement.
 *
 * ═══ LES ÂGES SONT SAISIS EN JOURS, ET L'ÉCRAN ASSISTE SANS DÉCIDER ═══
 *
 * Six semaines = 42 jours. Le formulaire propose des équivalences en semaines et en mois pour que
 * la saisie reste lisible, mais **la valeur enregistrée est le jour** : convertir à l'écriture
 * aurait rendu « six semaines » inexprimable dès qu'un mois de 30 ou 31 jours entre en jeu.
 *
 * ═══ LA PROVENANCE EST OBLIGATOIRE, ET C'EST LA GARDE ═══
 *
 * Une échéance sans source ne peut pas être publiée (`SourceVaccins::controlerQualite`). L'écran
 * affiche donc le compte des échéances encore issues du jeu de démonstration : c'est le témoin
 * visible du remplacement par le calendrier officiel. *Une donnée de démonstration qui ne se
 * signale pas finit par être prise pour une donnée de référence.*
 *
 * ═══ CET ÉCRAN N'EST PAS LA GOUVERNANCE ═══
 *
 * Écrire ici modifie le **contenu de travail**. Il faut ensuite proposer puis publier (§10,
 * quatre-yeux) pour que la version diffusée change — et tant que ce n'est pas fait, ni le carnet ni
 * le calendrier d'un membre ne voient quoi que ce soit. Le bandeau de la vue le dit.
 */
class ReferentielVaccinController extends Controller
{
    /** Les provenances admises — miroir de l'ENUM `calendrier_vaccinal.source`. */
    public const SOURCES = [
        'demonstration'     => 'Démonstration (non validée)',
        'autorite_nationale' => 'Autorité nationale (PEV, Ministère)',
        'oms'               => 'Organisation mondiale de la santé',
        'societe_savante'   => 'Société savante',
        'publication'       => 'Publication scientifique',
    ];

    public function index(): View
    {
        return view('portail.vaccins.index', [
            'vaccins' => Vaccin::query()
                ->with('echeances')
                ->orderBy('libelle')
                ->paginate(50),
            // Le témoin du remplacement, compté et non estimé.
            'echeancesDemonstration' => EcheanceVaccinale::where('source', 'demonstration')->count(),
            'echeancesTotal'         => EcheanceVaccinale::count(),
            // Ce que le backfill n'a pas encore fait : un vaccin sans code national ne peut pas
            // être publié (le contrôle qualité le refuse). On le compte plutôt que de le taire.
            'sansCode' => Vaccin::whereNull('code')->count(),
        ]);
    }

    public function create(): View
    {
        return view('portail.vaccins.create', ['sources' => self::SOURCES]);
    }

    public function store(Request $request): RedirectResponse
    {
        $donnees = $this->valider($request);

        $vaccin = new Vaccin();
        $vaccin->fill($donnees);
        // `code` et `pays_code` sont hors `$fillable` : le code national est ATTRIBUÉ par
        // `masante:vaccins:backfill`, jamais choisi au formulaire (précédent ETS/PRO/MED/ANA).
        $vaccin->forceFill(['pays_code' => config('referentiels.pays_defaut', 'CI')])->save();

        return redirect()->route('portail.vaccins.edit', $vaccin)
            ->with('succes', "Vaccin « {$vaccin->libelle} » ajouté au contenu de travail. "
                .'Ajoutez ses échéances, puis lancez `masante:vaccins:backfill` pour lui attribuer '
                .'un code national. Rien n\'est diffusé avant publication d\'une nouvelle version.');
    }

    public function edit(Vaccin $vaccin): View
    {
        return view('portail.vaccins.edit', [
            'vaccin'    => $vaccin->load(['echeances', 'maladies']),
            'sources'   => self::SOURCES,
            // P6.8c — le vocabulaire des maladies, pour rattacher ce vaccin à ce dont il protège.
            // Lu dans la TABLE et non dans la version publiée : on édite ici du contenu de travail,
            // et rattacher une maladie qui n'est pas encore publiée est légitime — c'est la
            // publication du référentiel des vaccins qui décidera de ce qui est diffusé.
            'maladies'  => Maladie::query()->active()->orderBy('libelle')->get(['id', 'libelle', 'code']),
            // Combien de lignes de carnet le référencent : retirer un vaccin n'est pas anodin, et
            // l'écran doit le dire avant, pas après.
            'rattachees' => $vaccin->vaccinations()->count(),
        ]);
    }

    public function update(Request $request, Vaccin $vaccin): RedirectResponse
    {
        $vaccin->update($this->valider($request, $vaccin));

        // P6.8c — les maladies évitées, par lien plutôt que par phrase. `sync` et non
        // `syncWithoutDetaching` : ici l'agent voit la liste complète à l'écran et la coche, donc
        // décocher DOIT retirer — à la différence du seeder, où un rejeu ne doit rien détruire.
        // Un envoi sans le champ ne détache rien : l'absence de champ n'est pas une liste vide.
        if ($request->has('maladies')) {
            $vaccin->maladies()->sync($request->input('maladies', []));
        }

        return redirect()->route('portail.vaccins.edit', $vaccin)
            ->with('succes', 'Vaccin enregistré. Il ne sera diffusé qu\'après publication d\'une '
                .'nouvelle version du référentiel.');
    }

    /** Ajoute ou remplace l'échéance d'une dose. */
    public function enregistrerEcheance(Request $request, Vaccin $vaccin): RedirectResponse
    {
        $donnees = $request->validate([
            'numero_dose'      => ['required', 'integer', 'min:1', 'max:255'],
            'age_jours_du'     => ['required', 'integer', 'min:0', 'max:36500'],
            'tolerance_jours'  => ['nullable', 'integer', 'min:0', 'max:3650'],
            // La cohérence des deux bornes est aussi tenue par un TRIGGER : le moteur refuse
            // l'écriture même par un import direct. Ici, on la nomme pour l'agent qui saisit —
            // deux gardes, deux publics, aucune ne rattrape l'autre.
            'age_jours_limite' => ['nullable', 'integer', 'min:0', 'max:36500', 'gte:age_jours_du'],
            'obligatoire'      => ['nullable', 'boolean'],
            'libelle_echeance' => ['required', 'string', 'max:120'],
            'source'           => ['required', Rule::in(array_keys(self::SOURCES))],
            'source_detail'    => ['nullable', 'string', 'max:200'],
        ], [
            'age_jours_limite.gte' => 'La fenêtre de rattrapage ne peut pas se fermer avant '
                .'l\'âge auquel la dose devient due.',
        ]);

        $donnees['obligatoire']     = $request->boolean('obligatoire');
        $donnees['tolerance_jours'] = $donnees['tolerance_jours'] ?? 0;

        $vaccin->echeances()->updateOrCreate(
            ['numero_dose' => $donnees['numero_dose']],
            $donnees,
        );

        return redirect()->route('portail.vaccins.edit', $vaccin)
            ->with('succes', "Échéance de la dose n°{$donnees['numero_dose']} enregistrée.");
    }

    public function supprimerEcheance(Vaccin $vaccin, EcheanceVaccinale $echeance): RedirectResponse
    {
        // Scopée au vaccin : une échéance d'un autre vaccin renvoie 404, jamais une suppression
        // transversale (même principe anti-IDOR que les sections du carnet).
        if ($echeance->vaccin_id !== $vaccin->id) {
            abort(404);
        }

        $echeance->delete();

        return redirect()->route('portail.vaccins.edit', $vaccin)
            ->with('succes', 'Échéance retirée du contenu de travail.');
    }

    /**
     * @return array<string, mixed>
     */
    private function valider(Request $request, ?Vaccin $vaccin = null): array
    {
        $donnees = $request->validate([
            'libelle'             => ['required', 'string', 'max:200'],
            'abreviation'         => ['nullable', 'string', 'max:40'],
            'maladies_evitees'    => ['nullable', 'string', 'max:2000'],
            'voie_administration' => ['nullable', Rule::in([
                'intramusculaire', 'sous_cutanee', 'intradermique', 'orale', 'nasale',
            ])],
            'nb_doses'      => ['required', 'integer', 'min:1', 'max:20'],
            'statut_marche' => ['required', Rule::in(['disponible', 'rupture', 'retire'])],
            'description'   => ['nullable', 'string', 'max:2000'],
            'actif'         => ['nullable', 'boolean'],
        ]);

        $donnees['actif'] = $vaccin === null ? true : $request->boolean('actif');

        return $donnees;
    }
}
