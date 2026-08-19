<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Protocole;
use App\Models\ProtocoleVersion;
use App\Services\Protocole\DiffusionProtocole;
use App\Services\Protocole\JournalProtocole;
use App\Services\Protocole\ProtocoleException;
use App\Services\Protocole\ServiceGouvernanceProtocole;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
