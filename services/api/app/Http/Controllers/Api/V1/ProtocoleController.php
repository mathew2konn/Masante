<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Protocole;
use App\Models\ProtocoleApplication;
use App\Models\ProtocoleVersion;
use App\Services\Protocole\DiffusionProtocole;
use App\Services\Protocole\JournalApplicationProtocole;
use App\Services\Protocole\JournalProtocole;
use App\Services\Protocole\ProtocoleException;
use App\Services\Protocole\ServiceGouvernanceProtocole;
use App\Services\Protocole\SelecteurProtocoles;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * P10b-1 — Le registre des protocoles médicaux (CDC_08 §9.1).
 *
 * ═══ LA GOUVERNANCE PASSE PAR L'API, COMME LES DIX RÉFÉRENTIELS DE P6 ═══
 *
 * Aucun écran d'authoring dans cet incrément : le portail est en Blade et ADR-011 le condamne, la
 * migration du portail étant un module déjà identifié depuis P6.4d. Y ajouter des écrans
 * reviendrait à investir dans une surface qu'on a décidé de refaire — et à le faire pour l'écran
 * le plus complexe du projet.
 *
 * ═══ LES REFUS SONT TRADUITS, PAS RECOPIÉS ═══
 *
 * `ProtocoleException` porte son code HTTP (403 habilitation, 409 état, 422 contenu). Le
 * contrôleur ne décide d'aucun statut : il transmet celui que le service a choisi, avec ses
 * détails. Un contrôleur qui rechoisirait le code finirait par diverger du service, et c'est le
 * service qui sait pourquoi il refuse.
 */
class ProtocoleController extends Controller
{
    public function __construct(
        private readonly ServiceGouvernanceProtocole $gouvernance,
        private readonly DiffusionProtocole $diffusion,
        private readonly JournalProtocole $journal,
        private readonly SelecteurProtocoles $selecteur,
        private readonly JournalApplicationProtocole $applications,
    ) {}

    /** `GET /protocoles?pays=CI&specialite=cardiologie&domaine=triage&statut=actif` (§9.1). */
    public function index(Request $request): JsonResponse
    {
        $filtres = $request->validate([
            'pays'       => ['nullable', 'string', 'size:2'],
            'domaine'    => ['nullable', 'string', 'max:30'],
            'specialite' => ['nullable', 'string', 'max:50'],
            'statut'     => ['nullable', 'in:actif,brouillon,archive'],
        ]);

        $pays = $filtres['pays'] ?? config('referentiels.pays_defaut', 'CI');

        $protocoles = Protocole::query()
            ->where('pays_code', $pays)
            ->when($filtres['domaine'] ?? null, fn ($q, $d) => $q->where('domaine', $d))
            ->when($filtres['specialite'] ?? null, fn ($q, $s) => $q->where('specialite_code', $s))
            ->when(
                $filtres['statut'] ?? null,
                fn ($q, $etat) => $q->whereHas('versions', fn ($v) => $v->where('etat', $etat))
            )
            ->orderBy('domaine')
            ->orderBy('code')
            ->get()
            ->map(fn (Protocole $p): array => [
                'code'          => $p->code,
                'titre'         => $p->titre,
                'domaine'       => $p->domaine,
                'niveau_source' => $p->niveau_source,
                'organisme'     => $p->organisme,
                'specialite'    => $p->specialite_code,
                'actif'         => $p->actif,
                // ═══ L'ÉTAT DE PUBLICATION EST DIT EXPLICITEMENT ═══
                //
                // « Aucune version en vigueur » n'est pas une anomalie de la réponse : c'est
                // l'état normal d'un protocole thérapeutique rédigé mais non validé (§1.6). Le
                // taire ferait croire à une erreur d'affichage.
                'version_en_vigueur' => $p->versionActive()?->libelle,
                'a_un_brouillon'     => $p->brouillon() !== null,
            ]);

        return response()->json(['total' => $protocoles->count(), 'protocoles' => $protocoles]);
    }

    /** `GET /protocoles/{code}` — la version en vigueur, telle que le moteur la lit. */
    public function show(string $code): JsonResponse
    {
        return $this->reponse(fn (): array => $this->diffusion->lire($code));
    }

    /**
     * `GET /protocoles/{code}/versions/{numero}` (§9.1).
     *
     * Le chemin qui rend une décision passée explicable : §6.1 « chaque décision conserve la
     * version exacte du protocole utilisée », et « un protocole archivé reste consultable
     * indéfiniment ».
     */
    public function version(string $code, int $numero): JsonResponse
    {
        return $this->reponse(fn (): array => $this->diffusion->lireVersion($code, $numero));
    }

    /** `POST /protocoles` — enregistrement au registre (§4.1). */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            // `code` est une donnée d'identité : littéral, choisi par l'auteur, immuable ensuite.
            // Le format est contraint pour qu'il reste citable dans un dossier médico-légal.
            'code'            => ['required', 'string', 'max:60', 'regex:/^[A-Z0-9][A-Z0-9\-]*$/'],
            'pays_code'       => ['nullable', 'string', 'size:2'],
            'titre'           => ['required', 'string', 'max:250'],
            'domaine'         => ['required', 'in:triage,infectieux,chronique,maternelle,infantile,urgence,specialise'],
            'niveau_source'   => ['nullable', 'in:national,regional,oms,societe_savante,hospitalier'],
            'organisme'       => ['nullable', 'string', 'max:200'],
            'auteur'          => ['nullable', 'string', 'max:200'],
            'specialite_code' => ['nullable', 'string', 'max:50'],
            'langue'          => ['nullable', 'string', 'size:2'],
            'mots_cles_json'  => ['nullable', 'array'],
        ]);

        return $this->reponse(function () use ($data, $request): array {
            $protocole = $this->gouvernance->creer(
                $data['code'],
                $data['pays_code'] ?? config('referentiels.pays_defaut', 'CI'),
                $request->user(),
                collect($data)->except(['code', 'pays_code'])->all(),
            );

            return [
                'code'    => $protocole->code,
                'titre'   => $protocole->titre,
                'message' => 'Protocole enregistré. Il ne peut encore rien décider : aucune version '
                    .'n\'a été rédigée ni validée (CDC_08 §1.6).',
            ];
        }, 201);
    }

    /** `POST /protocoles/{code}/versions` — création de brouillon (§9.1, §6.2 « Rédaction »). */
    public function ouvrirBrouillon(string $code, Request $request): JsonResponse
    {
        $data = $request->validate([
            'version'                => ['required', 'string', 'max:30'],
            'motif'                  => ['required', 'string', 'min:10', 'max:500'],
            'niveau_preuve'          => ['nullable', 'in:A,B,C,D'],
            'population'             => ['nullable', 'string', 'max:200'],
            'conditions_utilisation' => ['nullable', 'string'],
            'date_expiration'        => ['nullable', 'date'],
        ]);

        return $this->reponse(function () use ($code, $data, $request): array {
            $version = $this->gouvernance->ouvrirBrouillon(
                $this->protocole($code),
                $request->user(),
                $data['version'],
                $data['motif'],
                collect($data)->except(['version', 'motif'])->all(),
            );

            return [
                'version' => $version->libelle,
                'numero'  => $version->numero,
                'etat'    => $version->etat,
            ];
        }, 201);
    }

    /**
     * `POST /protocoles/{code}/versions/{numero}/valider` (§9.1, §7).
     *
     * Enregistre UNE des quatre validations. L'empreinte du contenu est figée à cet instant : le
     * validateur signe un texte précis, pas un protocole en général.
     */
    public function valider(string $code, int $numero, Request $request): JsonResponse
    {
        $data = $request->validate([
            'type'         => ['required', 'in:clinique,reglementaire,scientifique,technique'],
            'avis'         => ['required', 'in:favorable,reserve,defavorable'],
            'role'         => ['required', 'string', 'max:100'],
            'commentaires' => ['nullable', 'string', 'max:2000'],
        ]);

        return $this->reponse(function () use ($code, $numero, $data, $request): array {
            $validation = $this->gouvernance->valider(
                $this->versionDe($code, $numero),
                $request->user(),
                $data['type'],
                $data['avis'],
                $data['role'],
                $data['commentaires'] ?? null,
            );

            return [
                'type'      => $validation->type,
                'avis'      => $validation->avis,
                'empreinte' => $validation->empreinte_contenu,
                'message'   => 'Validation enregistrée. Elle porte sur le contenu actuel : toute '
                    .'modification ultérieure la rendra caduque et il faudra re-signer (§7).',
            ];
        }, 201);
    }

    /** `POST /protocoles/{code}/versions/{numero}/publier` (§9.1, §6.2, §10). */
    public function publier(string $code, int $numero, Request $request): JsonResponse
    {
        return $this->reponse(function () use ($code, $numero, $request): array {
            $version = $this->gouvernance->publier($this->versionDe($code, $numero), $request->user());

            return [
                'version'   => $version->libelle,
                'numero'    => $version->numero,
                'etat'      => $version->etat,
                'empreinte' => $version->empreinte,
                'publie_le' => $version->publie_le?->toIso8601String(),
            ];
        });
    }

    /**
     * `GET /protocoles/{code}/versions/{numero}/validations` — le dossier de validation (§7).
     *
     * Il NOMME chaque validateur et son rôle : le §7 veut la pièce « opposable », et une pièce
     * opposable qui ne dirait pas qui a signé n'opposerait rien.
     */
    public function dossierValidation(string $code, int $numero): JsonResponse
    {
        return $this->reponse(function () use ($code, $numero): array {
            $version = $this->versionDe($code, $numero);

            return [
            'version'     => $version->libelle,
            'etat'        => $version->etat,
            'validations' => $version->validations()->get()->map(fn ($v): array => [
                'type'           => $v->type,
                'avis'           => $v->avis,
                'validateur'     => $v->validateur_nom,
                'role'           => $v->validateur_role,
                'commentaires'   => $v->commentaires,
                'empreinte'      => $v->empreinte_contenu,
                'valide_le'      => $v->valide_le->toIso8601String(),
                // Dit explicitement si la signature porte encore sur le texte actuel. C'est la
                // question que se pose un relecteur, et la lui faire déduire de deux empreintes
                // affichées côte à côte serait lui demander de faire le travail du système.
                'porte_sur_le_contenu_actuel' => $v->avis === 'favorable'
                    && $v->autorisePublication(app(\App\Services\Protocole\CompilateurProtocole::class)->empreinte($version)),
            ])->all(),
            ];
        });
    }

    /** `GET /protocoles/journal/integrite` — vérification de la chaîne d'audit (§10, CDC_10). */
    public function integrite(): JsonResponse
    {
        return response()->json($this->journal->verifierChaine());
    }

    /**
     * `POST /protocoles/evaluer` — le contrat d'évaluation du §9.1.
     *
     * ═══ CE QUE CETTE PORTE N'EST PAS ═══
     *
     * Ce n'est pas le chemin du triage citoyen. Celui-ci appelle le même sélecteur en interne, avec
     * son propre contexte : **deux portes, un seul moteur**. Les faire diverger — un moteur pour le
     * citoyen, un autre pour le professionnel — donnerait deux réponses possibles au même cas,
     * exactement ce que le §1.6 et le §6.1 existent pour empêcher.
     *
     * ═══ POURQUOI ELLE EST GARDÉE ═══
     *
     * Elle rend des recommandations de conduite à tenir et inscrit une ligne au journal
     * médico-légal du §10 à chaque appel. `protocole.evaluer` n'est portée par aucun rôle métier :
     * l'ouvrir largement ferait d'un moteur d'aide à la décision une API publique, et remplirait
     * le journal d'évaluations qui ne concernent aucun patient.
     *
     * ═══ LA DÉCISION DU PROFESSIONNEL S'ÉCRIT ICI OU JAMAIS ═══
     *
     * `decision_finale` et `ecart_justification` (§10) sont acceptés **à l'appel** : un
     * professionnel qui évalue et décide dans le même geste les fournit. Ils ne se rattrapent pas
     * ensuite — le journal est append-only, et compléter après coup serait réécrire le passé. Une
     * décision prise plus tard produira une nouvelle entrée.
     *
     * Aucun écran n'émet encore cet appel (limite N6 : pas d'écran soignant).
     */
    public function evaluer(Request $request): JsonResponse
    {
        return $this->reponse(function () use ($request): array {
            $utilisateur = $request->user();

            $this->gouvernance->exigerEvaluation($utilisateur);

            $donnees = $request->validate([
                'contexte'            => ['required', 'string', 'max:20'],
                'pays_code'           => ['nullable', 'string', 'size:2'],
                'membre_id'           => ['nullable', 'integer', 'exists:membres_famille,id'],
                // Les faits, tels que le registre les nomme. Le moteur REFUSE un fait inconnu
                // (b-1) : on ne filtre donc pas ici, sous peine d'écarter en silence une clé mal
                // orthographiée que l'appelant croit avoir fournie.
                'faits'               => ['required', 'array'],
                'decision_finale'     => ['nullable', 'string', 'max:200'],
                'ecart_justification' => ['nullable', 'string', 'max:2000'],
            ]);

            $resultat = $this->selecteur->evaluer(
                $donnees['contexte'],
                $donnees['faits'],
                $donnees['pays_code'] ?? null,
            );

            $application = DB::transaction(fn () => $this->applications->inscrire($resultat, [
                'contexte'            => $donnees['contexte'],
                'pays_code'           => $donnees['pays_code'] ?? config('referentiels.pays_defaut', 'CI'),
                'membre_id'           => $donnees['membre_id'] ?? null,
                'user_id'             => $utilisateur->id,
                'professionnel_id'    => $utilisateur->id,
                'decision_finale'     => $donnees['decision_finale'] ?? null,
                'ecart_justification' => $donnees['ecart_justification'] ?? null,
            ]));

            return [
                // §9.1 nomme exactement ces clés.
                'recommandations' => $resultat['actions'],
                'conflits'        => $resultat['conflits'],
                'trace_id'        => $application->trace_id,

                // Ce que le §9.1 ne nomme pas mais que le §10 exige de pouvoir reconstituer.
                'protocole_retenu'    => $resultat['protocole_retenu'],
                'protocoles_evalues'  => $resultat['protocoles'],
                'protocoles_ecartes'  => $resultat['ecartes'],
                'regles_declenchees'  => $resultat['regles_declenchees'],

                // `questions_suivantes` du §9.1 : le questionnaire adaptatif arrive en P10b-3. On
                // rend un tableau vide plutôt que d'omettre la clé — un client qui la lit doit
                // pouvoir distinguer « aucune question » de « ce serveur ne sait pas encore ».
                'questions_suivantes' => [],
            ];
        }, 201);
    }

    /**
     * `GET /protocoles/applications` — le journal d'exécution (§10).
     *
     * Réservé : il porte des recommandations rattachées à des patients. La même permission que
     * l'évaluation — qui peut faire évaluer peut relire ce qui a été évalué.
     */
    public function applications(Request $request): JsonResponse
    {
        return $this->reponse(function () use ($request): array {
            $this->gouvernance->exigerEvaluation($request->user());

            $filtres = $request->validate([
                'contexte'  => ['nullable', 'string', 'max:20'],
                'protocole' => ['nullable', 'string', 'max:60'],
                'membre_id' => ['nullable', 'integer'],
            ]);

            $entrees = ProtocoleApplication::query()
                ->when($filtres['contexte'] ?? null, fn ($q, $v) => $q->where('contexte', $v))
                ->when($filtres['protocole'] ?? null, fn ($q, $v) => $q->where('protocole_retenu_code', $v))
                ->when($filtres['membre_id'] ?? null, fn ($q, $v) => $q->where('membre_id', $v))
                ->withCount('conflits')
                ->orderByDesc('id')
                ->limit(100)
                ->get()
                ->map(fn (ProtocoleApplication $a): array => [
                    'trace_id'         => $a->trace_id,
                    'contexte'         => $a->contexte,
                    'protocole_retenu' => $a->protocole_retenu_code,
                    'version'          => $a->protocole_retenu_version,
                    'nb_protocoles'    => count($a->protocoles_json ?? []),
                    'nb_conflits'      => $a->conflits_count,
                    'triage_id'        => $a->triage_id,
                    'cree_le'          => $a->cree_le->toIso8601String(),
                ])->all();

            return ['total' => count($entrees), 'applications' => $entrees];
        });
    }

    /** `GET /protocoles/applications/{trace}` — une évaluation, telle qu'elle a été rendue. */
    public function application(string $trace, Request $request): JsonResponse
    {
        return $this->reponse(function () use ($trace, $request): array {
            $this->gouvernance->exigerEvaluation($request->user());

            $entree = ProtocoleApplication::query()->where('trace_id', $trace)->first();

            if ($entree === null) {
                throw new ProtocoleException("Aucune évaluation « {$trace} » au journal.", 404);
            }

            return [
                'trace_id'   => $entree->trace_id,
                'contexte'   => $entree->contexte,
                'pays_code'  => $entree->pays_code,
                'membre_id'  => $entree->membre_id,
                'triage_id'  => $entree->triage_id,
                'professionnel_id' => $entree->professionnel_id,
                'protocole_retenu' => $entree->protocole_retenu_code === null ? null : [
                    'code'   => $entree->protocole_retenu_code,
                    'numero' => $entree->protocole_retenu_version,
                ],
                'protocoles'      => $entree->protocoles_json,
                'recommandations' => $entree->recommandations_json,
                'conflits'        => $entree->conflits()->orderBy('id')->get()->map(fn ($c): array => [
                    'action'    => $c->action_type,
                    'retenu'    => [
                        'valeur'    => $c->valeur_retenue,
                        'protocole' => $c->protocole_retenu_code,
                        'version'   => $c->protocole_retenu_version,
                        'source'    => $c->source_retenue,
                    ],
                    'ecarte'    => [
                        'valeur'    => $c->valeur_ecartee,
                        'protocole' => $c->protocole_ecarte_code,
                        'version'   => $c->protocole_ecarte_version,
                        'source'    => $c->source_ecartee,
                    ],
                    'critere'   => $c->critere,
                ])->all(),

                // ═══ DEUX CHAMPS VIDES QUI DISENT QUELQUE CHOSE ═══
                //
                // Le §10 exige la décision finale et la justification d'écart. Sur un triage
                // citoyen il n'y a PAS de professionnel pour décider : les rendre explicitement
                // nuls, plutôt que de les omettre, évite de faire passer une absence structurelle
                // pour un défaut d'affichage.
                'decision_finale'     => $entree->decision_finale,
                'ecart_justification' => $entree->ecart_justification,

                'cree_le' => $entree->cree_le->toIso8601String(),
            ];
        });
    }

    /**
     * `GET /protocoles/applications/integrite` — la chaîne du journal d'exécution (§10, CDC_10).
     *
     * Distincte de `/protocoles/journal/integrite` : deux chaînes, deux vérifications. Les
     * confondre laisserait croire qu'une altération du journal de gouvernance affecte celui des
     * exécutions, ou l'inverse.
     */
    public function integriteApplications(Request $request): JsonResponse
    {
        return $this->reponse(function () use ($request): array {
            $this->gouvernance->exigerEvaluation($request->user());

            return $this->applications->verifierChaine();
        });
    }

    private function protocole(string $code): Protocole
    {
        $protocole = Protocole::query()
            ->where('pays_code', config('referentiels.pays_defaut', 'CI'))
            ->where('code', $code)
            ->first();

        if ($protocole === null) {
            throw new ProtocoleException("Aucun protocole « {$code} » au registre.", 404);
        }

        return $protocole;
    }

    private function versionDe(string $code, int $numero): ProtocoleVersion
    {
        $version = $this->protocole($code)->versions()->where('numero', $numero)->first();

        if ($version === null) {
            throw new ProtocoleException("La version n°{$numero} de « {$code} » n'existe pas.", 404);
        }

        return $version;
    }

    /**
     * Traduit un refus métier en réponse HTTP, avec LE statut choisi par le service.
     *
     * Motif `GouvernanceReferentielController` (P6.3). Le contrôleur ne rechoisit jamais le code :
     * c'est le service qui sait s'il refuse pour un défaut de droit (403), un état incompatible
     * (409) ou un contenu invalide (422), et un contrôleur qui trancherait à sa place finirait par
     * en diverger.
     */
    private function reponse(callable $action, int $succes = 200): JsonResponse
    {
        try {
            return response()->json($action(), $succes);
        } catch (ProtocoleException $e) {
            return response()->json([
                'error' => [
                    'code'    => 'PROTOCOLE_REFUS',
                    'message' => $e->getMessage(),
                    'details' => $e->details,
                ],
            ], $e->statut);
        }
    }
}
