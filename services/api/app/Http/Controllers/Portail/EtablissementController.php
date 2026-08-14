<?php

namespace App\Http\Controllers\Portail;

use App\Http\Controllers\Controller;
use App\Models\Analyse;
use App\Models\LaboratoireAnalyse;
use App\Services\Analyse\CatalogueDuLaboratoire;
use App\Support\TypesEtablissement;
use App\Models\ActivationPortail;
use App\Models\CategorieImageEtablissement;
use App\Models\DistrictSanitaire;
use App\Models\Region;
use App\Models\StructureSanitaire;
use App\Models\EtablissementImage;
use App\Models\User;
use App\Models\Ville;
use App\Services\Etablissement\ImagesEtablissement;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Module 4 / 4.2 — Gestion des établissements partenaires (CdC §5.4.1 & §5.4.2, ADMIN uniquement).
 *
 * L'admin IVOIRSANTÉ inscrit un établissement (formulaire guidé — pas d'upload PDF) PUIS son compte
 * gestionnaire. Le gestionnaire naît SANS mot de passe : un lien d'activation à usage unique (24h) lui
 * est remis (§5.4.1 étapes 2-3 — « le mot de passe temporaire n'existe pas »). En dev sans passerelle
 * mail, le lien est affiché à l'écran (comme l'OTP simulé). Protégé par `permission:etablissement.manage`.
 */
class EtablissementController extends Controller
{
    /**
     * Types d'établissement — délégué à la SOURCE UNIQUE `TypesEtablissement` (P6.4b).
     *
     * Cette constante était un « miroir de l'enum de la migration » recopié à la main. Le miroir
     * avait cessé de refléter : P6.4a a porté l'énumération à 13 valeurs, celle-ci en gardait 7.
     * On conserve le nom `TYPES` — les vues Blade et les tests s'y réfèrent — mais le contenu
     * vient désormais d'un seul endroit.
     */
    public const TYPES = TypesEtablissement::TYPES;

    /** Liste + recherche (nom/commune) + filtre par type. */
    public function index(Request $request): View
    {
        $recherche = trim((string) $request->query('q', ''));
        $type = (string) $request->query('type', '');

        $etablissements = StructureSanitaire::query()
            ->withCount('services')
            ->with(['staff' => fn ($q) => $q->role('gestionnaire_etablissement')])
            ->when($recherche !== '', fn ($q) => $q->where(function ($sous) use ($recherche) {
                $sous->where('nom', 'like', "%{$recherche}%")
                    ->orWhere('commune', 'like', "%{$recherche}%");
            }))
            ->when($type !== '' && isset(self::TYPES[$type]), fn ($q) => $q->where('type', $type))
            ->orderBy('nom')
            ->paginate(15)
            ->withQueryString();

        return view('portail.etablissements.index', [
            'etablissements' => $etablissements,
            'types'          => self::TYPES,
            'recherche'      => $recherche,
            'typeActif'      => $type,
        ]);
    }

    public function create(): View
    {
        return view('portail.etablissements.create', $this->donneesDeReference());
    }

    /** Crée l'établissement + son compte gestionnaire (sans mot de passe) + le lien d'activation. */
    public function store(Request $request): RedirectResponse
    {
        $data = $this->validerEtablissement($request);
        $gestionnaire = $request->validate([
            'gestionnaire_nom'    => ['required', 'string', 'max:100'],
            'gestionnaire_prenom' => ['required', 'string', 'max:100'],
            'gestionnaire_email'  => ['required', 'email', 'max:190', 'unique:users,email'],
        ]);

        $lien = DB::transaction(function () use ($data, $gestionnaire) {
            $structure = StructureSanitaire::create($data + ['actif' => true]);

            // Compte gestionnaire : SANS mot de passe (activation obligatoire), rattaché à l'établissement.
            $user = User::create([
                'nom'          => $gestionnaire['gestionnaire_nom'],
                'prenom'       => $gestionnaire['gestionnaire_prenom'],
                'email'        => $gestionnaire['gestionnaire_email'],
                'password'     => null,
                'structure_id' => $structure->id,
                'actif'        => true,
            ]);
            $user->assignRole('gestionnaire_etablissement');

            return $this->emettreLienActivation($user);
        });

        return redirect()
            ->route('portail.etablissements.index')
            ->with('statut', 'Établissement créé. Transmettez le lien d\'activation au gestionnaire.')
            ->with('lien_activation', $lien);
    }

    public function edit(StructureSanitaire $etablissement): View
    {
        $etablissement->load(['images', 'staff' => fn ($q) => $q->role('gestionnaire_etablissement')]);

        // P6.7b — les analyses realisees ne concernent qu'un laboratoire. Pour les autres
        // categories, on ne charge rien : la vue ne montre pas le bloc.
        $estLabo = $etablissement->type === 'laboratoire';
        $analysesLabo = $estLabo
            ? app(CatalogueDuLaboratoire::class)->analysesDe($etablissement)
            : collect();
        $catalogueDisponible = $estLabo
            ? Analyse::where('actif', true)
                ->whereNotIn('id', LaboratoireAnalyse::where('structure_id', $etablissement->id)->pluck('analyse_id'))
                ->orderBy('libelle')->get()
            : collect();

        return view('portail.etablissements.edit', $this->donneesDeReference() + [
            'etablissement' => $etablissement,
            'gestionnaire'  => $etablissement->staff->first(),
            // P6.4c/P6.4d — les images se gèrent depuis la fiche d'édition (lève la limite O1).
            'images'        => $etablissement->images,
            'categories'    => CategorieImageEtablissement::query()->active()->orderBy('ordre')->get(),
            // P6.7b — vides pour toute catégorie autre que `laboratoire` : la vue ne montre alors
            // pas le bloc, plutôt que d'afficher une section sans objet.
            'analysesLabo'        => $analysesLabo,
            'catalogueDisponible' => $catalogueDisponible,
        ]);
    }

    /** Met à jour les champs de l'établissement (le compte gestionnaire n'est pas modifié ici). */
    public function update(Request $request, StructureSanitaire $etablissement): RedirectResponse
    {
        $etablissement->update($this->validerEtablissement($request));

        return redirect()
            ->route('portail.etablissements.index')
            ->with('statut', 'Établissement mis à jour.');
    }

    /**
     * Active/désactive l'établissement (CdC §5.4.2 « supprimer »). On ne supprime PAS physiquement :
     * la structure est référencée par des RDV/avis/services. La désactivation la retire de l'annuaire
     * public et suspend ses comptes staff (répercuté sur `users.actif`).
     */
    public function toggleActif(StructureSanitaire $etablissement): RedirectResponse
    {
        $nouvelEtat = ! $etablissement->actif;

        DB::transaction(function () use ($etablissement, $nouvelEtat) {
            $etablissement->update(['actif' => $nouvelEtat]);
            $etablissement->staff()->update(['actif' => $nouvelEtat]);
        });

        return redirect()
            ->route('portail.etablissements.index')
            ->with('statut', $nouvelEtat ? 'Établissement réactivé.' : 'Établissement désactivé.');
    }

    /** Régénère un lien d'activation pour le gestionnaire non encore activé (lien perdu/expiré). */
    public function regenererLien(StructureSanitaire $etablissement): RedirectResponse
    {
        $gestionnaire = $etablissement->staff()->role('gestionnaire_etablissement')->first();

        if (! $gestionnaire) {
            return back()->withErrors(['gestionnaire' => 'Aucun gestionnaire rattaché à cet établissement.']);
        }

        if ($gestionnaire->password !== null) {
            return back()->withErrors(['gestionnaire' => 'Ce compte est déjà activé.']);
        }

        return redirect()
            ->route('portail.etablissements.edit', $etablissement)
            ->with('statut', 'Nouveau lien d\'activation généré.')
            ->with('lien_activation', $this->emettreLienActivation($gestionnaire));
    }

    /**
     * Dépôt d'une image depuis la fiche d'édition (P6.4d — lève la limite O1 d'ADR-028).
     *
     * SOURCE UNIQUE : toutes les gardes vivent dans `ImagesEtablissement`, le service que l'API
     * mobile utilise déjà. Le portail ne les réécrit pas — c'est le motif de P4, où le workflow de
     * validation des rendez-vous a été extrait dans un service partagé par le Blade et l'API. Deux
     * copies des mêmes contrôles, c'est deux comportements le jour où l'une est corrigée.
     *
     * Le service renvoie ses refus en `abort()` (403/404/409/422) ; on les traduit ici en message
     * d'écran, parce qu'une page d'erreur brute au milieu d'un formulaire fait perdre la saisie.
     */
    public function ajouterImage(
        Request $request,
        StructureSanitaire $etablissement,
        ImagesEtablissement $images,
    ): RedirectResponse {
        $request->validate([
            'image'     => ['required', 'file'],
            'categorie' => ['required', 'string', 'max:40'],
        ]);

        try {
            $images->deposer($request->file('image'), $etablissement, $request->string('categorie')->toString(), $request->user());
        } catch (HttpException $e) {
            return back()->with('erreur', $e->getMessage());
        }

        return back()->with('statut', 'Image ajoutée.');
    }

    /** Suppression d'une image (mêmes gardes, même service). */
    public function supprimerImage(
        Request $request,
        StructureSanitaire $etablissement,
        EtablissementImage $image,
        ImagesEtablissement $images,
    ): RedirectResponse {
        // L'image doit appartenir à l'établissement de l'URL : sans ce contrôle, deux chemins
        // désigneraient la même ressource et un identifiant deviné suffirait à supprimer ailleurs.
        abort_unless($image->structure_id === $etablissement->id, 404);

        $images->supprimer($image, $request->user());

        return back()->with('statut', 'Image supprimée.');
    }

    /**
     * Données de référence servies aux deux formulaires (création et édition).
     *
     * Régions, districts et villes viennent tous de TABLES : §1.2.4 interdit la saisie libre d'une
     * donnée de référence. Ajouter une région ou une ville ne demande donc aucune retouche de ce
     * contrôleur ni des vues.
     *
     * @return array<string, mixed>
     */
    private function donneesDeReference(): array
    {
        return [
            'types'     => self::TYPES,
            'regions'   => Region::query()->orderBy('nom')->get(),
            // Le district est présenté AVEC sa région (« Abidjan — Cocody-Bingerville ») : c'est
            // ce qui rend visible à l'oeil le couple que le serveur vérifie plus bas.
            'districts' => DistrictSanitaire::query()->with('region:id,nom')->orderBy('nom')->get(),
            'villes'    => Ville::query()->active()->orderBy('ordre')->get(),
        ];
    }

    /** Règles de validation communes aux champs de l'établissement. */
    private function validerEtablissement(Request $request): array
    {
        $valide = $request->validate([
            // — Informations générales (CDC_11 §3.1) —
            'nom'                   => ['required', 'string', 'max:200'],
            'nom_officiel'          => ['nullable', 'string', 'max:200'],
            'type'                  => ['required', 'string', Rule::in(array_keys(self::TYPES))],
            'statut_juridique'      => ['nullable', Rule::in(['public', 'prive', 'universitaire', 'militaire'])],
            'forme_juridique'       => ['nullable', 'string', 'max:80'],
            'niveau_soins'          => ['nullable', Rule::in(['primaire', 'secondaire', 'tertiaire'])],
            // P6.7b — §7.1/§7.2. Ces champs ne concernent qu'un laboratoire ; on ne les impose donc
            // jamais, et le formulaire ne les montre que pour cette categorie. Le referentiel ne
            // gouverne QUE `type_laboratoire` : les autres sont des donnees d'exploitation, qui
            // changent avec le personnel et les automates (voir `SourceEtablissements`).
            'type_laboratoire'      => ['nullable', Rule::in(\App\Support\Analyses::typesLaboratoire())],
            'responsable_scientifique'       => ['nullable', 'string', 'max:200'],
            'responsable_scientifique_titre' => ['nullable', 'string', 'max:120'],
            'equipements'           => ['nullable', 'string', 'max:5000'],
            'delai_rendu_moyen_heures' => ['nullable', 'integer', 'min:0', 'max:8760'],
            'connecte_si_national'  => ['nullable', 'boolean'],

            // — Coordonnées —
            'adresse'               => ['required', 'string', 'max:500'],
            'commune'               => ['required', 'string', 'max:100'],
            'quartier'              => ['nullable', 'string', 'max:100'],
            'latitude'              => ['required', 'numeric', 'between:-90,90'],
            'longitude'             => ['required', 'numeric', 'between:-180,180'],
            'telephone'             => ['nullable', 'string', 'max:20'],
            'whatsapp'              => ['nullable', 'string', 'max:20'],
            'email'                 => ['nullable', 'email', 'max:190'],
            'site_web'              => ['nullable', 'url', 'max:190'],

            // — Rattachement (tables de référence, jamais du texte libre : §1.2.4) —
            'ville_id'              => ['nullable', 'exists:villes,id'],
            'region_id'             => ['nullable', 'exists:regions,id'],
            'district_id'           => ['nullable', 'exists:districts_sanitaires,id'],

            // — Informations légales —
            'directeur'             => ['nullable', 'string', 'max:150'],
            'numero_autorisation'   => ['nullable', 'string', 'max:60'],
            'numero_fiscal'         => ['nullable', 'string', 'max:60'],
            'registre_commerce'     => ['nullable', 'string', 'max:60'],
            'licence_exploitation'  => ['nullable', 'string', 'max:60'],
            'autorite_tutelle'      => ['nullable', 'string', 'max:150'],
            'date_creation'         => ['nullable', 'date', 'before_or_equal:today'],

            // — Capacités —
            'capacite_accueil'      => ['nullable', 'integer', 'min:0'],
            'nombre_lits'           => ['nullable', 'integer', 'min:0', 'lte:capacite_accueil'],

            // — Tarifs, agréments, description —
            'tarif_min_cfa'         => ['nullable', 'integer', 'min:0'],
            'tarif_max_cfa'         => ['nullable', 'integer', 'min:0', 'gte:tarif_min_cfa'],
            'agrements'             => ['nullable', 'string', 'max:500'],
            'certifications'        => ['nullable', 'string', 'max:500'],
            'description'           => ['nullable', 'string', 'max:2000'],
            'partenaire_ivoirsante' => ['nullable', 'boolean'],
        ], [
            'nombre_lits.lte' => 'Le nombre de lits ne peut pas depasser la capacite d accueil.',
        ]);

        $this->exigerDistrictDansSaRegion($valide);

        // Agréments et certifications : listes courtes saisies en « ISO 9001, HAS » → tableau JSON.
        // Ce ne sont PAS des données de référence — ce sont des libellés portés par l'établissement,
        // qui varient d'un organisme certificateur à l'autre ; les figer en table les figerait faux.
        foreach (['agrements' => 'agrements_json', 'certifications' => 'certifications_json'] as $saisi => $colonne) {
            $valide[$colonne] = collect(explode(',', (string) ($valide[$saisi] ?? '')))
                ->map(fn ($v) => trim($v))
                ->filter()
                ->values()
                ->all();
            unset($valide[$saisi]);
        }

        // `specialites` a été RETIRÉ du formulaire (P6.4d, décision K2) : la colonne
        // `specialites_json` était écrite ici et lue par PERSONNE — ni la fiche mobile, ni la
        // tuile, ni aucun filtre. Le filtre `?specialite=` de l'annuaire passe par
        // `services_etablissement.specialite`, une AUTRE colonne, qui porte aussi l'orientation
        // après triage (F1.5). La colonne est conservée en base (aucune donnée perdue) ; on cesse
        // simplement de faire saisir au gestionnaire une donnée que rien ne consomme.

        $valide['partenaire_ivoirsante'] = $request->boolean('partenaire_ivoirsante');

        // P6.7b — les champs du §7.2 ne concernent QUE les laboratoires. Sur une autre catégorie,
        // on les efface plutôt que de les laisser passer : un CHU qui porterait
        // `type_laboratoire = 'prive'` produirait une statistique fausse, et le champ resterait
        // invisible au formulaire — donc impossible à corriger par celui qui l'aurait rempli.
        if (($valide['type'] ?? null) !== 'laboratoire') {
            foreach (['type_laboratoire', 'responsable_scientifique', 'responsable_scientifique_titre',
                'equipements', 'delai_rendu_moyen_heures'] as $champ) {
                $valide[$champ] = null;
            }

            $valide['connecte_si_national'] = false;
        } else {
            $valide['connecte_si_national'] = $request->boolean('connecte_si_national');
        }

        return $valide;
    }

    /**
     * Le district déclaré doit appartenir à la région déclarée.
     *
     * L'ANOMALIE LA PLUS SOURNOISE DU LOT, et la raison pour laquelle ce contrôle vit ICI : les
     * deux références sont valides prises séparément — `exists:` les accepte toutes les deux — et
     * c'est leur COMBINAISON qui est fausse. Une statistique par région la propagerait sans que
     * rien ne la signale.
     *
     * P6.4a la DÉTECTE dans les contrôles qualité du référentiel. Le formulaire est l'endroit où
     * elle doit être EMPÊCHÉE : détecter après coup oblige à retrouver qui a saisi quoi, alors
     * qu'ici l'agent a encore l'information sous les yeux.
     *
     * @param  array<string, mixed>  $valide
     */
    private function exigerDistrictDansSaRegion(array $valide): void
    {
        if (empty($valide['district_id'])) {
            return;
        }

        if (empty($valide['region_id'])) {
            throw ValidationException::withMessages([
                'region_id' => 'Choisissez la region : un district ne peut pas etre renseigne seul.',
            ]);
        }

        $district = DistrictSanitaire::with('region:id,nom')->find($valide['district_id']);

        if ((int) $district->region_id !== (int) $valide['region_id']) {
            throw ValidationException::withMessages([
                'district_id' => "Le district « {$district->nom} » appartient a la region "
                    . "« {$district->region->nom} », pas a celle que vous avez choisie.",
            ]);
        }
    }

    /** Émet un jeton d'activation (usage unique, 24h) pour un compte staff et renvoie l'URL du lien. */
    private function emettreLienActivation(User $user): string
    {
        return route('portail.activation.show', ['token' => ActivationPortail::genererPour($user)]);
    }

    /**
     * P6.7b — Déclare qu'un laboratoire réalise une analyse (CDC_09 §7.2).
     *
     * Les gardes ne sont PAS réécrites ici : elles vivent dans `CatalogueDuLaboratoire`, qui vérifie
     * l'habilitation à deux chemins et refuse un établissement qui n'est pas un laboratoire. Motif
     * de P6.4d, où les gardes d'image n'ont pas été dupliquées non plus.
     */
    public function ajouterAnalyse(Request $request, StructureSanitaire $etablissement): RedirectResponse
    {
        $donnees = $request->validate([
            'analyse_id'         => ['required', 'integer', 'exists:analyses,id'],
            'delai_rendu_heures' => ['nullable', 'integer', 'min:0', 'max:8760'],
            'methode'            => ['nullable', 'string', 'max:200'],
        ]);

        app(CatalogueDuLaboratoire::class)->declarer(
            $etablissement,
            Analyse::findOrFail($donnees['analyse_id']),
            $request->user(),
            $donnees['delai_rendu_heures'] ?? null,
            $donnees['methode'] ?? null,
        );

        return redirect()->route('portail.etablissements.edit', $etablissement)
            ->with('succes', 'Analyse déclarée.');
    }

    public function retirerAnalyse(Request $request, StructureSanitaire $etablissement, LaboratoireAnalyse $ligne): RedirectResponse
    {
        app(CatalogueDuLaboratoire::class)->retirer($etablissement, $ligne, $request->user());

        return redirect()->route('portail.etablissements.edit', $etablissement)
            ->with('succes', 'Analyse retirée.');
    }
}
