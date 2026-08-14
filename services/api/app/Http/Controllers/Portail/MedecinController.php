<?php

namespace App\Http\Controllers\Portail;

use App\Http\Controllers\Controller;
use App\Models\ExerciceProfessionnel;
use App\Models\Medecin;
use App\Models\ServiceEtablissement;
use App\Models\SpecialiteMedicale;
use App\Models\StructureSanitaire;
use App\Models\User;
use App\Services\Professionnel\AttributeurNumeroProfessionnel;
use App\Support\ProfessionsSante;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Module 5 / 5.6 — Fiches des praticiens d'un établissement, gérées par SON gestionnaire.
 *
 * L'annuaire `medecins` existait depuis le Module 3 (F3.5, choix du praticien à la prise de RDV) mais
 * n'était alimenté QUE par le seeder : le CdC renvoyait sa configuration « au portail admin »,
 * et l'écran n'avait jamais été construit. Conséquence concrète : un établissement créé après coup
 * n'avait aucun praticien réservable, et surtout aucune fiche à relier à un compte — la voie 2
 * (médecin référent) y était donc inutilisable. Cet écran comble ce trou.
 *
 * Deux usages, une seule table :
 *  - annuaire PUBLIC : le patient voit ces fiches à la prise de RDV (F3.5) et pour désigner son
 *    référent (5.6). Données non sensibles (nom, spécialité, tarif indicatif) ;
 *  - lien vers un COMPTE (`user_id`) : c'est lui qui permet au praticien désigné référent d'ouvrir
 *    le dossier depuis « Mes patients suivis ». Sans lien, la fiche est visible mais muette.
 *
 * Cloisonnement strict par `structure_id`, comme les services (4.3) : un gestionnaire ne touche
 * jamais aux praticiens d'un autre établissement. Aucune suppression — une fiche se DÉSACTIVE
 * (elle est référencée par des rendez-vous et, potentiellement, par des désignations de référent).
 */
class MedecinController extends Controller
{
    /** ID de l'établissement du gestionnaire connecté ; 403 si le compte n'est rattaché à aucun. */
    private function structureId(): int
    {
        $id = auth()->user()->structure_id;
        abort_if($id === null, Response::HTTP_FORBIDDEN, 'Compte non rattaché à un établissement.');

        return $id;
    }

    /** Récupère une fiche DE MON établissement, ou 404 (empêche l'accès croisé). */
    private function fichePossedee(Medecin $medecin): Medecin
    {
        abort_if($medecin->structure_id !== $this->structureId(), Response::HTTP_NOT_FOUND);

        return $medecin;
    }

    /** Services de MON établissement (un praticien est rattaché à UN service). */
    private function mesServices()
    {
        return ServiceEtablissement::where('structure_id', $this->structureId())
            ->orderBy('nom_service')
            ->get();
    }

    /**
     * Comptes de MON établissement proposables comme titulaire de la fiche : ceux qui ne sont
     * encore reliés à aucune fiche, plus celui déjà relié à la fiche éditée (sans quoi il
     * disparaîtrait de son propre formulaire). Un compte = au plus une fiche (`user_id` UNIQUE).
     *
     * P6.5a — le rôle `medecin` s'ajoute à `agent_garde`. C'est le sens de la décision P5 : un
     * praticien se connecte désormais sous son propre rôle, et non sous celui d'un agent d'accueil.
     * `agent_garde` reste proposé, sans quoi les fiches déjà reliées perdraient leur compte.
     */
    private function mesAgents(?Medecin $medecin = null)
    {
        $relies = Medecin::whereNotNull('user_id')
            ->where('id', '!=', $medecin?->id ?? 0)
            ->pluck('user_id');

        return User::role(['agent_garde', 'medecin'])
            ->where('structure_id', $this->structureId())
            ->whereNotIn('id', $relies)
            ->orderBy('nom')
            ->get();
    }

    public function index(Request $request): View
    {
        $recherche = trim((string) $request->query('q', ''));

        $medecins = Medecin::query()
            ->where('structure_id', $this->structureId())
            ->when($recherche !== '', fn ($q) => $q->where(function ($sous) use ($recherche) {
                $sous->where('nom', 'like', "%{$recherche}%")
                    ->orWhere('prenom', 'like', "%{$recherche}%")
                    ->orWhere('specialite', 'like', "%{$recherche}%")
                    // P6.5a — on cherche aussi par numéro national : c'est la clé qu'un ordre
                    // professionnel ou un autre établissement communiquera.
                    ->orWhere('numero_professionnel', 'like', "%{$recherche}%");
            }))
            ->with(['service:id,nom_service', 'user:id,prenom,nom'])
            ->orderBy('nom')
            ->paginate(15)
            ->withQueryString();

        return view('portail.medecins.index', ['medecins' => $medecins, 'recherche' => $recherche]);
    }

    public function create(): View
    {
        return view('portail.medecins.create', [
            'services'      => $this->mesServices(),
            'agents'        => $this->mesAgents(),
            'specialites'   => $this->vocabulaire(),
            'peutHabiliter' => $this->peutHabiliter(),
        ]);
    }

    public function store(Request $request, AttributeurNumeroProfessionnel $attributeur): RedirectResponse
    {
        $data = $this->valider($request);

        $professionnel = Medecin::create([
            ...$data,
            'structure_id' => $this->structureId(),
            'actif'        => true,
        ]);

        // Le numéro national est attribué DÈS LA CRÉATION, pas au prochain passage du backfill :
        // une fiche créée aujourd'hui et publiée demain sans numéro ferait échouer le contrôle
        // qualité du référentiel — le backfill n'a alors plus à servir que le rattrapage.
        $attributeur->attribuer($professionnel);

        // Et son exercice principal, pour la même raison : `structure_id` seul laisserait le
        // référentiel affirmer que ce praticien n'exerce nulle part (contrôle de cohérence de
        // `SourceProfessionnels`). La redondance assumée par P6.5a se paie ici aussi.
        ExerciceProfessionnel::firstOrCreate(
            ['medecin_id' => $professionnel->id, 'structure_id' => $professionnel->structure_id],
            ['service_id' => $professionnel->service_id, 'est_principal' => true, 'actif' => true],
        );

        return redirect()->route('portail.medecins.index')
            ->with('statut', "Praticien ajouté à l'annuaire — numéro national {$professionnel->numero_professionnel}.");
    }

    public function edit(Medecin $medecin): View
    {
        $this->fichePossedee($medecin);

        return view('portail.medecins.edit', [
            'medecin'       => $medecin->load('exercices.structure:id,nom,identifiant_national'),
            'services'      => $this->mesServices(),
            'agents'        => $this->mesAgents($medecin),
            'specialites'   => $this->vocabulaire(),
            'peutHabiliter' => $this->peutHabiliter(),
            // Chargée seulement pour qui peut s'en servir : un gestionnaire n'a pas à recevoir la
            // liste complète des établissements du pays pour un formulaire qu'il ne verra pas.
            'structures'    => $this->peutHabiliter()
                ? StructureSanitaire::orderBy('nom')->get(['id', 'nom', 'identifiant_national'])
                : collect(),
        ]);
    }

    public function update(Request $request, Medecin $medecin): RedirectResponse
    {
        $this->fichePossedee($medecin);

        $medecin->update($this->valider($request, $medecin));

        return redirect()->route('portail.medecins.index')->with('statut', 'Fiche du praticien mise à jour.');
    }

    /**
     * Désactive (ou réactive) la fiche. Pas de suppression : elle est référencée par des
     * rendez-vous et peut l'être par des désignations de référent. Une fiche désactivée sort de
     * l'annuaire public — les désignations en cours restent, mais le patient ne peut plus la choisir.
     */
    public function toggleActif(Medecin $medecin): RedirectResponse
    {
        $this->fichePossedee($medecin);
        $medecin->update(['actif' => ! $medecin->actif]);

        return redirect()->route('portail.medecins.index')
            ->with('statut', $medecin->actif ? 'Praticien réactivé.' : 'Praticien retiré de l\'annuaire public.');
    }

    /**
     * Déclare un lieu d'exercice supplémentaire (§5.2, « établissements d'exercice »).
     *
     * POURQUOI CETTE ACTION EST RÉSERVÉE À `professionnel.habiliter` et non à `medecin.manage` :
     * dire qu'un praticien exerce dans un second établissement est une affirmation sur SA
     * situation, pas la description de son propre annuaire. Un hôpital qui pourrait l'écrire seul
     * se rattacherait le médecin d'un confrère sans que celui-ci en sache rien.
     *
     * L'exercice principal, lui, n'est pas déclaré ici : il naît de `structure_id` à la création
     * (et du backfill pour l'existant), justement pour que l'annuaire de P3/P4 et le référentiel
     * ne puissent pas se contredire.
     */
    public function ajouterExercice(Request $request, Medecin $medecin): RedirectResponse
    {
        $this->fichePossedee($medecin);
        abort_unless($this->peutHabiliter(), Response::HTTP_FORBIDDEN);

        $data = $request->validate([
            'structure_id' => [
                'required',
                'exists:structures_sanitaires,id',
                // Un exercice de plus dans le MÊME établissement ne dirait rien de neuf, et
                // fausserait tout dénombrement — l'unicité est aussi en base, on la double ici
                // pour un message lisible plutôt qu'une 500.
                Rule::unique('professionnel_etablissement', 'structure_id')
                    ->where('medecin_id', $medecin->id),
            ],
            'debut_le' => ['nullable', 'date'],
            'fin_le'   => ['nullable', 'date', 'after_or_equal:debut_le'],
        ], [
            'structure_id.unique' => 'Ce praticien exerce déjà dans cet établissement.',
            'fin_le.after_or_equal' => "La fin d'exercice ne peut pas précéder son début.",
        ], ['structure_id' => 'établissement']);

        $medecin->exercices()->create([...$data, 'est_principal' => false, 'actif' => true]);

        return back()->with('statut', "Lieu d'exercice ajouté.");
    }

    /**
     * Retire un lieu d'exercice.
     *
     * L'EXERCICE PRINCIPAL NE PEUT PAS ÊTRE RETIRÉ : il double `medecins.structure_id`, dont
     * dépendent l'annuaire (P3) et les rendez-vous (P4), tous deux validés G5. Le supprimer
     * laisserait le référentiel affirmer que ce praticien n'exerce pas là où le patient le
     * réserve — la contradiction exacte que le contrôle qualité de `SourceProfessionnels`
     * signale. Pour changer d'établissement principal, on change la fiche.
     */
    public function retirerExercice(Medecin $medecin, ExerciceProfessionnel $exercice): RedirectResponse
    {
        $this->fichePossedee($medecin);
        abort_unless($this->peutHabiliter(), Response::HTTP_FORBIDDEN);
        abort_if($exercice->medecin_id !== $medecin->id, Response::HTTP_NOT_FOUND);

        if ($exercice->est_principal) {
            return back()->withErrors([
                'exercice' => "L'établissement principal ne se retire pas ici : modifiez la fiche du praticien.",
            ]);
        }

        $exercice->delete();

        return back()->with('statut', "Lieu d'exercice retiré.");
    }

    /**
     * Valide la fiche. Le service et le compte doivent appartenir à MON établissement : on ne
     * s'approprie ni le service ni l'agent d'un autre hôpital.
     *
     * ═══ P6.5a — CE QUE LE GESTIONNAIRE NE PEUT PAS DÉCLARER ═══
     *
     * Le bloc « ordre professionnel + autorisation d'exercer » n'est accepté que si le compte
     * porte `professionnel.habiliter`. Sans elle, les champs sont ABSENTS du formulaire et, s'ils
     * arrivent quand même dans la requête, ils ne sont simplement pas repris — motif éprouvé en
     * P6.4d, où `identifiant_national` et `pays_code` étaient ignorés malgré l'envoi.
     *
     * Ce n'est pas une précaution de forme. Ces colonnes sont celles que le §5.4 interrogera avant
     * de laisser signer une ordonnance : si l'établissement qui emploie le praticien pouvait les
     * écrire, le contrôle qui autorise la signature reposerait sur la déclaration de l'intéressé.
     *
     * La garde est vérifiée par `can()` EN SERVICE et non par le middleware spatie : le portail est
     * à sessions (guard `web`), mais c'est le même piège que `rdv.validate` en P4 — on ne suppose
     * pas, on vérifie là où la décision est prise.
     *
     * @return array<string, mixed>
     */
    private function valider(Request $request, ?Medecin $medecin = null): array
    {
        $vocabulaire = $this->vocabulaire();

        $regles = [
            'titre'              => ['required', Rule::in(['Dr', 'Pr'])],
            'prenom'             => ['required', 'string', 'max:120'],
            'nom'                => ['required', 'string', 'max:120'],
            'sexe'               => ['nullable', Rule::in(['M', 'F'])],
            'date_naissance'     => ['nullable', 'date', 'before:today'],
            // ═══ P6.8a — LA SPÉCIALITÉ SE CHOISIT, ELLE NE SE TAPE PLUS ═══
            //
            // Le champ envoyé est un CODE du vocabulaire national ; le libellé affiché par
            // l'annuaire (`specialite`, que P3 et P4 sérialisent) est écrit PAR LE SERVEUR d'après
            // le référentiel. Un établissement ne décide donc plus du nom sous lequel une
            // spécialité apparaît au citoyen — sans quoi « Cardiologie », « cardio » et « Cardio. »
            // coexisteraient dans l'annuaire national.
            //
            // La liste n'est pas filtrée sur `nature` : la spécialité d'un biologiste EST
            // « biologie ». Y poser un garde-fou serait inventer une règle que le §8 ne pose pas.
            'specialite_code'    => ['required', Rule::in($vocabulaire->pluck('code')->all())],
            // La profession (§5.1) vient de la source unique : la liste n'est jamais recopiée.
            'profession'         => ['nullable', ProfessionsSante::regleIn()],
            'sous_specialite'    => ['nullable', 'string', 'max:100'],
            'universite'         => ['nullable', 'string', 'max:150'],
            // Bornes plutôt qu'un simple `integer` : §10 interdit les valeurs aberrantes, et une
            // année de diplôme dans le futur en est une.
            'annee_diplome'      => ['nullable', 'integer', 'min:1900', 'max:'.now()->format('Y')],
            'experience_annees'  => ['nullable', 'integer', 'min:0', 'max:80'],
            'telephone'          => ['nullable', 'string', 'max:30'],
            'email'              => ['nullable', 'email', 'max:190'],
            'biographie'         => ['nullable', 'string', 'max:2000'],
            'langues_json'       => ['nullable', 'array', 'max:10'],
            'langues_json.*'     => ['string', 'max:40'],
            'consultation_en_ligne' => ['nullable', 'boolean'],
            'consultation_physique' => ['nullable', 'boolean'],
            'tarif_consultation' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'service_id'         => [
                'required',
                Rule::exists('services_etablissement', 'id')->where('structure_id', $this->structureId()),
            ],
            // Le compte du praticien : facultatif (une fiche peut n'exister que pour l'annuaire).
            // UNIQUE en base — on l'exige aussi ici, pour une erreur lisible plutôt qu'une 500.
            'user_id' => [
                'nullable',
                Rule::exists('users', 'id')->where('structure_id', $this->structureId()),
                Rule::unique('medecins', 'user_id')->ignore($medecin?->id),
            ],
        ];

        if ($this->peutHabiliter()) {
            $regles += [
                'ordre_professionnel'      => ['nullable', 'string', 'max:150'],
                'numero_ordre'             => ['nullable', 'string', 'max:60'],
                'autorisation_numero'      => ['nullable', 'string', 'max:60'],
                'autorisation_statut'      => ['nullable', Rule::in(array_keys(ProfessionsSante::STATUTS_AUTORISATION))],
                'autorisation_delivree_le' => ['nullable', 'date'],
                // La cohérence des deux dates est contrôlée ICI, au formulaire, et non seulement
                // par le référentiel : détecter après coup obligerait à retrouver qui a saisi quoi,
                // alors qu'au formulaire l'agent a encore l'information sous les yeux. Même
                // renversement détection → interdiction qu'en P6.4d pour le couple région/district.
                'autorisation_expire_le'   => ['nullable', 'date', 'after_or_equal:autorisation_delivree_le'],
            ];
        }

        $donnees = $request->validate($regles, [
            'autorisation_expire_le.after_or_equal' =>
                "L'autorisation ne peut pas expirer avant d'avoir été délivrée.",
            'specialite_code.in' => 'Cette spécialité ne fait pas partie du vocabulaire national.',
        ], [
            'user_id'                => 'compte du praticien',
            'service_id'             => 'service',
            'specialite_code'        => 'spécialité',
            'annee_diplome'          => 'année de diplôme',
            'autorisation_expire_le' => "date d'expiration de l'autorisation",
        ]);

        // Cases à cocher : absentes de la requête quand elles sont décochées. Sans ces deux lignes,
        // décocher « consultation en ligne » laisserait la valeur précédente en base.
        $donnees['consultation_en_ligne'] = $request->boolean('consultation_en_ligne');
        $donnees['consultation_physique'] = $request->boolean('consultation_physique');

        // Le code entre, le LIBELLÉ et le rattachement sortent — tous deux relus au référentiel.
        // `specialite` n'est jamais repris de la requête : un client qui l'enverrait quand même est
        // écarté par `validate()` (la clé n'est pas déclarée) puis écrasé ici.
        $terme = $vocabulaire->firstWhere('code', $donnees['specialite_code']);
        unset($donnees['specialite_code']);

        $donnees['specialite']    = $terme?->libelle;
        $donnees['specialite_id'] = $terme?->id;

        return $donnees;
    }

    /**
     * Le vocabulaire national des spécialités (P6.8a).
     *
     * Termes INACTIFS exclus : une fiche déjà rattachée à un terme retiré garde son libellé, mais
     * on ne peut plus en rattacher de nouvelles — c'est la raison d'être de la désactivation.
     */
    private function vocabulaire(): \Illuminate\Database\Eloquent\Collection
    {
        return SpecialiteMedicale::query()->active()->ordonnee()->get();
    }

    /** Ce compte peut-il déclarer l'ordre professionnel et l'autorisation d'exercer ? (§5.2) */
    private function peutHabiliter(): bool
    {
        return auth()->user()?->can('professionnel.habiliter') === true;
    }
}
