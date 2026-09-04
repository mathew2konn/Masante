<?php

namespace App\Http\Controllers\Portail;

use App\Http\Controllers\Controller;
use App\Models\InteractionMedicamenteuse;
use App\Models\Medicament;
use App\Services\Medicament\AttributeurCodeMedicament;
use App\Services\Medicament\ServiceCodeBarres;
use App\Services\Medicament\ServiceInteractions;
use App\Support\Medicaments;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * P6.6a — Le référentiel national des médicaments, côté portail (CDC_09 §6.2).
 *
 * ═══ POURQUOI UNE PERMISSION NEUVE PLUTÔT QUE `medicament.manage` ═══
 *
 * `medicament.manage` existe déjà — mais elle appartient au **gestionnaire d'établissement**, et son
 * commentaire au seeder dit exactement ce qu'elle couvre : « prix et ruptures de SA pharmacie ».
 * La réutiliser ici donnerait à toute officine partenaire le droit d'écrire les indications, les
 * contre-indications et les **interactions** du catalogue national. Un laboratoire fabricant
 * deviendrait juge et partie sur son propre produit.
 *
 * D'où `medicament.referentiel`, **attribuée à aucun rôle** — sixième occurrence du précédent posé
 * par `urgence.bris_de_glace`, puis `dossier.ecrire`, `referentiel.proposer` / `referentiel.publier`
 * et `professionnel.habiliter`. Elle s'accorde nominativement, à qui exerce l'autorité sanitaire.
 *
 * ═══ CE QUE CET ÉCRAN N'EST PAS ═══
 *
 * Ce n'est pas la gouvernance. Écrire ici modifie le **contenu de travail** ; il faut ensuite
 * PROPOSER puis PUBLIER (§10, quatre-yeux) pour que la version en vigueur change — exactement comme
 * les seuils de mesure depuis L1. Le bandeau de l'écran le dit, sans quoi un agent croirait qu'un
 * enregistrement suffit.
 */
class ReferentielMedicamentController extends Controller
{
    public function __construct(
        private readonly AttributeurCodeMedicament $attributeur,
        private readonly ServiceInteractions $interactions,
        private readonly ServiceCodeBarres $codesBarres,
    ) {
    }

    public function index(Request $request): View
    {
        $filtres = $request->validate([
            'q'      => ['nullable', 'string', 'max:120'],
            'statut' => ['nullable', Rule::in(Medicaments::statutsMarche())],
        ]);

        $medicaments = Medicament::query()
            ->when($filtres['q'] ?? null, fn ($query, $q) => $query->where(
                fn ($sous) => $sous->where('nom_generique', 'like', "%{$q}%")
                    ->orWhere('nom_commercial', 'like', "%{$q}%")
                    ->orWhere('code', 'like', "%{$q}%")
            ))
            ->when($filtres['statut'] ?? null, fn ($query, $s) => $query->where('statut_marche', $s))
            ->orderBy('code')
            ->orderBy('nom_generique')
            ->paginate(25)
            ->withQueryString();

        return view('portail.medicaments.index', [
            'medicaments'   => $medicaments,
            'filtres'       => $filtres,
            'statutsMarche' => Medicaments::STATUTS_MARCHE,
            'sansCode'      => Medicament::whereNull('code')->count(),
        ]);
    }

    public function edit(Medicament $medicament): View
    {
        return view('portail.medicaments.edit', [
            'medicament'       => $medicament,
            'formes'           => Medicaments::FORMES,
            'voies'            => Medicaments::VOIES,
            'statutsMarche'    => Medicaments::STATUTS_MARCHE,
            'statutsGenerique' => Medicaments::STATUTS_GENERIQUE,
            'niveaux'          => Medicaments::NIVEAUX_INTERACTION,
            'interactions'     => $medicament->interactions()
                ->with(['medicamentA:id,code,nom_generique,nom_commercial', 'medicamentB:id,code,nom_generique,nom_commercial'])
                ->get(),
        ]);
    }

    public function update(Request $request, Medicament $medicament): RedirectResponse
    {
        // `code` et `pays_code` ne figurent PAS dans les règles : l'identifiant national ne se
        // choisit pas. Un client qui les enverrait les verrait simplement ignorés — `validate()`
        // ne renvoie que les clés validées, et ils sont de toute façon hors `$fillable`.
        $donnees = $request->validate([
            'nom_generique'        => ['required', 'string', 'max:200'],
            'nom_commercial'       => ['nullable', 'string', 'max:200'],
            'laboratoire'          => ['nullable', 'string', 'max:200'],
            'forme'                => ['nullable', Rule::in(Medicaments::formes())],
            'dosage'               => ['nullable', 'string', 'max:100'],
            'voie_administration'  => ['nullable', Rule::in(Medicaments::voies())],
            'categorie'            => ['required', 'string', 'max:100'],
            'indications'          => ['nullable', 'string', 'max:5000'],
            'contre_indications'   => ['nullable', 'string', 'max:5000'],
            'effets_secondaires'   => ['nullable', 'string', 'max:5000'],
            'statut_marche'        => ['required', Rule::in(Medicaments::statutsMarche())],
            'statut_generique'     => ['nullable', Rule::in(Medicaments::statutsGenerique())],
            'prix_reference_cfa'   => ['nullable', 'integer', 'min:0'],
            'ordonnance_requise'   => ['nullable', 'boolean'],
            'disponible_generique' => ['nullable', 'boolean'],
            'cename_reference'     => ['nullable', 'string', 'max:50'],
            // B3-c (E4) — un EAN/GTIN, saisi par l'agent lui-même : refus NOMMÉ ci-dessous, la
            // forme brute n'est jamais celle qu'on écrit (la normalisation retire espaces/tirets).
            'code_barres'          => ['nullable', 'string', 'max:20'],
        ]);

        $donnees['ordonnance_requise']   = $request->boolean('ordonnance_requise');
        $donnees['disponible_generique'] = $request->boolean('disponible_generique');

        // Une saisie vide EFFACE le code-barres (le champ est nullable) ; une saisie non vide doit
        // avoir la forme d'un GTIN — sinon `assertSaisieValide()` refuse en NOMMANT la raison,
        // contrairement au scan de `ServiceCodeBarres::identifier()`, qui ne bloque jamais (E5).
        $saisie = trim((string) ($donnees['code_barres'] ?? ''));
        $donnees['code_barres'] = $saisie === '' ? null : $this->codesBarres->assertSaisieValide($saisie);

        $medicament->update($donnees);

        // Un produit édité doit pouvoir entrer dans le référentiel : sans code national, le contrôle
        // qualité refuserait la publication de TOUT le référentiel à cause de cette seule ligne.
        $this->attributeur->attribuer($medicament);

        return redirect()
            ->route('portail.medicaments.edit', $medicament)
            ->with('succes', 'Fiche enregistrée. Elle ne sera diffusée qu\'après publication d\'une nouvelle version du référentiel.');
    }

    /** Déclare une interaction avec un autre médicament. */
    public function declarerInteraction(Request $request, Medicament $medicament): RedirectResponse
    {
        $donnees = $request->validate([
            'medicament_b_id'  => ['required', 'integer', 'exists:medicaments,id'],
            'niveau'           => ['required', Rule::in(Medicaments::niveauxInteraction())],
            'description'      => ['required', 'string', 'max:2000'],
            'conduite_a_tenir' => ['nullable', 'string', 'max:2000'],
            'source'           => ['required', 'string', 'max:200'],
        ]);

        $autre = Medicament::findOrFail($donnees['medicament_b_id']);

        // Le service porte les gardes (auto-interaction, couple déjà déclaré dans un sens ou dans
        // l'autre) : les réécrire ici en ferait une seconde vérité, qui divergerait le jour où
        // l'une des deux changerait. Précédent P6.4d, où les gardes d'image ne sont pas réécrites.
        $this->interactions->declarer(
            $medicament,
            $autre,
            $donnees['niveau'],
            $donnees['description'],
            $donnees['conduite_a_tenir'] ?? null,
            $donnees['source'],
        );

        return redirect()
            ->route('portail.medicaments.edit', $medicament)
            ->with('succes', 'Interaction déclarée.');
    }

    public function retirerInteraction(Medicament $medicament, InteractionMedicamenteuse $interaction): RedirectResponse
    {
        // L'interaction doit appartenir à CE médicament : sans ce contrôle, l'identifiant de l'URL
        // permettrait de supprimer n'importe quelle interaction du référentiel depuis n'importe
        // quelle fiche.
        $appartient = (int) $interaction->medicament_a_id === (int) $medicament->id
            || (int) $interaction->medicament_b_id === (int) $medicament->id;

        abort_unless($appartient, 404);

        $interaction->delete();

        return redirect()
            ->route('portail.medicaments.edit', $medicament)
            ->with('succes', 'Interaction retirée du contenu de travail.');
    }
}
