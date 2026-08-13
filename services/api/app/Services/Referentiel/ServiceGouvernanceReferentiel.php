<?php

namespace App\Services\Referentiel;

use App\Models\Referentiel;
use App\Models\ReferentielJournal;
use App\Models\ReferentielVersion;
use App\Models\User;
use App\Support\RegistreReferentiels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Le cycle de vie des référentiels nationaux (CDC_09 §10) : proposition → validation → publication
 * → archivage, avec quatre-yeux, contrôles qualité et audit chaîné.
 *
 * TOUTES LES DÉCISIONS SONT ICI, aucune au front. Qui peut publier, si une proposition est
 * recevable, si un contenu est publiable, quel numéro porte la version : le client ne fait
 * qu'appeler et recevoir. « Quelles règles métier ce module calcule-t-il côté front ? » → aucune.
 *
 * ORDRE DES VERROUS : chaque opération commence par verrouiller la ligne `referentiels`, et rien
 * d'autre n'est verrouillé avant elle. Deux publications simultanées du même référentiel se
 * sérialisent donc ; deux référentiels différents n'interfèrent pas. L'ordre est le même partout,
 * ce qui exclut l'inversion qui produit les interblocages (leçon P6.1).
 */
final class ServiceGouvernanceReferentiel
{
    /** Déposer une proposition de changement d'un référentiel national (§10). */
    public const PERMISSION_PROPOSER = 'referentiel.proposer';

    /** Décider du sort d'une proposition — publier ou rejeter (§10 « autorité compétente »). */
    public const PERMISSION_PUBLIER = 'referentiel.publier';

    public function __construct(private readonly JournalReferentiel $journal) {}

    /**
     * Inscrit un référentiel au registre s'il n'y est pas déjà. Idempotent : rejouer ne crée rien
     * et ne journalise rien — c'est ce qui permet de l'appeler depuis un seeder ou une commande
     * sans salir la chaîne d'audit.
     */
    public function enregistrer(string $code, string $paysCode = 'CI'): Referentiel
    {
        $source = RegistreReferentiels::source($code);

        $existant = Referentiel::query()
            ->where('code', $code)->where('pays_code', $paysCode)->first();

        if ($existant !== null) {
            return $existant;
        }

        return DB::transaction(function () use ($source, $code, $paysCode): Referentiel {
            $referentiel = Referentiel::create([
                'code'             => $code,
                'pays_code'        => $paysCode,
                'libelle'          => $source->libelle(),
                'role_responsable' => $source->roleResponsable(),
            ]);

            $this->journal->inscrire($referentiel, ReferentielJournal::ENREGISTRE, null, null, [
                'libelle'          => $source->libelle(),
                'role_responsable' => $source->roleResponsable(),
            ]);

            return $referentiel;
        });
    }

    /**
     * Dépose une proposition : fige le contenu ACTUEL de la table métier et le soumet à décision.
     *
     * Trois refus possibles, tous en 409 :
     *  - une proposition est déjà en cours (l'unicité est aussi garantie en base par `verrou_unicite`) ;
     *  - le contenu est identique à la version publiée — publier une version qui ne change rien
     *    ferait grossir l'historique sans rien y ajouter ;
     *  - le référentiel n'est pas au registre.
     *
     * Les contrôles qualité sont exécutés ici mais ne bloquent PAS : ils sont retournés en
     * avertissement. C'est à la publication qu'ils font barrage — un auteur doit pouvoir soumettre
     * un contenu à discuter, pas un décideur publier un contenu incohérent.
     */
    public function proposer(string $code, string $paysCode, User $auteur, string $motif): ReferentielVersion
    {
        $source = RegistreReferentiels::source($code);

        $this->exigerHabilitation($auteur, self::PERMISSION_PROPOSER);

        return DB::transaction(function () use ($source, $code, $paysCode, $auteur, $motif): ReferentielVersion {
            $referentiel = $this->verrouiller($code, $paysCode);

            if ($referentiel->propositionEnCours() !== null) {
                throw new ReferentielException(
                    "Une proposition est déjà en cours sur « {$code} » : elle doit être décidée avant "
                    .'qu\'une autre soit déposée.'
                );
            }

            $contenu = $source->extraire();
            $empreinte = EmpreinteReferentiel::duContenu($contenu);

            $publiee = $referentiel->versionPubliee();
            if ($publiee !== null && hash_equals($publiee->empreinte, $empreinte)) {
                throw new ReferentielException(
                    "Le contenu de « {$code} » est identique à la version publiée n°{$publiee->numero} : "
                    .'il n\'y a rien à proposer.'
                );
            }

            $version = ReferentielVersion::create([
                'referentiel_id' => $referentiel->id,
                'numero'         => $this->prochainNumero($referentiel),
                'statut'         => ReferentielVersion::PROPOSITION,
                'verrou_unicite' => ReferentielVersion::verrouPour(ReferentielVersion::PROPOSITION, $referentiel->id),
                'motif'          => $motif,
                'contenu_json'   => $contenu,
                'empreinte'      => $empreinte,
                'nb_entrees'     => count($contenu),
                'propose_par'    => $auteur->id,
                'propose_le'     => Carbon::now(),
            ]);

            $this->journal->inscrire(
                $referentiel,
                ReferentielJournal::PROPOSITION_DEPOSEE,
                $auteur,
                $version->numero,
                ['empreinte' => $empreinte, 'nb_entrees' => count($contenu), 'motif' => $motif],
            );

            return $version;
        });
    }

    /**
     * Valide et publie une proposition. C'est le seul chemin qui met un référentiel en vigueur.
     *
     * TROIS GARDES CUMULATIVES, aucune ne rattrapant les autres :
     *
     *  1. **Quatre-yeux** (§10 « double validation ») — le décideur n'est pas l'auteur. Vérifié
     *     ici ET par le `CHECK ck_ref_version_quatre_yeux` en base : le service donne le message
     *     utile, le moteur garantit qu'aucun autre chemin d'écriture ne puisse contourner la règle.
     *
     *  2. **Anti-substitution** — le contenu est ré-extrait et son empreinte comparée à celle qui a
     *     été proposée. Si la table métier a bougé entre la proposition et la décision, on refuse.
     *     Sans ce contrôle, on publierait un contenu que PERSONNE n'a relu, et surtout le
     *     référentiel diffusé cesserait de correspondre à la table que lisent le triage et les
     *     mesures : deux vérités. C'est le contrôle « destination révoquée depuis le figeage »
     *     de P5.5b-2, transposé.
     *
     *  3. **Contrôles qualité** (§10) — unicité, format, cohérence, doublons, valeurs aberrantes.
     *     Bloquants : une version incohérente ne devient jamais la version en vigueur.
     */
    public function publier(string $code, string $paysCode, User $decideur, string $motifDecision): ReferentielVersion
    {
        $source = RegistreReferentiels::source($code);

        $this->exigerHabilitation($decideur, self::PERMISSION_PUBLIER);

        return DB::transaction(function () use ($source, $code, $paysCode, $decideur, $motifDecision): ReferentielVersion {
            $referentiel = $this->verrouiller($code, $paysCode);
            $version = $this->propositionADecider($referentiel, $code);

            // Garde 1 — quatre-yeux.
            if ((int) $version->propose_par === (int) $decideur->id) {
                throw new ReferentielException(
                    'L\'auteur d\'une proposition ne peut pas la valider lui-même (CDC_09 §10, '
                    .'double validation).'
                );
            }

            // Garde 2 — anti-substitution.
            $contenuActuel = $source->extraire();
            $empreinteActuelle = EmpreinteReferentiel::duContenu($contenuActuel);

            if (! hash_equals($version->empreinte, $empreinteActuelle)) {
                throw new ReferentielException(
                    "Le contenu de « {$code} » a changé depuis la proposition n°{$version->numero} : "
                    .'ce qui serait publié n\'est plus ce qui a été relu. Rejetez cette proposition '
                    .'et déposez-en une nouvelle.'
                );
            }

            // Garde 3 — contrôles qualité, bloquants.
            $erreurs = $source->controlerQualite($contenuActuel);
            if ($erreurs !== []) {
                throw ReferentielException::qualite($erreurs);
            }

            // L'ancienne version en vigueur s'archive : elle n'est jamais supprimée, c'est elle
            // qui explique les décisions prises pendant qu'elle était en vigueur.
            $ancienne = $referentiel->versionPubliee();
            if ($ancienne !== null) {
                $ancienne->statut = ReferentielVersion::ARCHIVEE;
                $ancienne->verrou_unicite = null;
                $ancienne->save();

                $this->journal->inscrire(
                    $referentiel,
                    ReferentielJournal::VERSION_ARCHIVEE,
                    $decideur,
                    $ancienne->numero,
                    ['remplacee_par' => $version->numero],
                );
            }

            $version->statut = ReferentielVersion::PUBLIEE;
            $version->verrou_unicite = ReferentielVersion::verrouPour(ReferentielVersion::PUBLIEE, $referentiel->id);
            $version->decide_par = $decideur->id;
            $version->decide_le = Carbon::now();
            $version->motif_decision = $motifDecision;
            $version->save();

            // Le numéro publié change → la clé de cache de la diffusion change → l'ancienne entrée
            // est périmée sans avoir à être supprimée (§10 « invalidation lors d'une nouvelle
            // version »), quel que soit le store de cache.
            $referentiel->version_publiee_numero = $version->numero;
            $referentiel->publiee_le = $version->decide_le;
            $referentiel->save();

            $this->journal->inscrire(
                $referentiel,
                ReferentielJournal::VERSION_PUBLIEE,
                $decideur,
                $version->numero,
                [
                    'empreinte'  => $version->empreinte,
                    'nb_entrees' => $version->nb_entrees,
                    'remplace'   => $ancienne?->numero,
                    'motif'      => $motifDecision,
                ],
            );

            return $version->refresh();
        });
    }

    /**
     * Rejette une proposition. Soumis au même quatre-yeux que la publication : refuser un
     * changement est une décision de gouvernance au même titre que l'accepter.
     */
    public function rejeter(string $code, string $paysCode, User $decideur, string $motifDecision): ReferentielVersion
    {
        $this->exigerHabilitation($decideur, self::PERMISSION_PUBLIER);

        return DB::transaction(function () use ($code, $paysCode, $decideur, $motifDecision): ReferentielVersion {
            $referentiel = $this->verrouiller($code, $paysCode);
            $version = $this->propositionADecider($referentiel, $code);

            if ((int) $version->propose_par === (int) $decideur->id) {
                throw new ReferentielException(
                    'L\'auteur d\'une proposition ne peut pas décider de son sort (CDC_09 §10).'
                );
            }

            $version->statut = ReferentielVersion::REJETEE;
            $version->verrou_unicite = null;
            $version->decide_par = $decideur->id;
            $version->decide_le = Carbon::now();
            $version->motif_decision = $motifDecision;
            $version->save();

            $this->journal->inscrire(
                $referentiel,
                ReferentielJournal::VERSION_REJETEE,
                $decideur,
                $version->numero,
                ['motif' => $motifDecision],
            );

            return $version;
        });
    }

    /**
     * L'état de qualité du contenu ACTUEL de la table métier, sans rien décider.
     *
     * Sert au responsable avant de proposer, et à la commande de contrôle. Détection seule :
     * cette méthode ne corrige jamais rien — même principe qu'en P5.3b-4.
     *
     * @return array{code: string, nb_entrees: int, empreinte: string, erreurs: array<int, string>}
     */
    public function controler(string $code): array
    {
        $source = RegistreReferentiels::source($code);
        $contenu = $source->extraire();

        return [
            'code'       => $code,
            'nb_entrees' => count($contenu),
            'empreinte'  => EmpreinteReferentiel::duContenu($contenu),
            'erreurs'    => $source->controlerQualite($contenu),
        ];
    }

    /**
     * L'habilitation à écrire dans les référentiels nationaux (§10 « accès en écriture strictement
     * réservé aux rôles habilités »).
     *
     * VÉRIFIÉE ICI, DANS LE SERVICE, et non par le middleware `permission:` de spatie : ces routes
     * sont authentifiées par Sanctum alors que les permissions du projet vivent sur le guard `web`.
     * Le middleware refuserait sur un désaccord de guard plutôt que sur un défaut de droit — piège
     * déjà rencontré en P4 sur `rdv.validate`, où la garde a dû passer par `can()`. Le service est
     * de toute façon le bon endroit : il reste faisant autorité quel que soit l'appelant.
     */
    private function exigerHabilitation(User $utilisateur, string $permission): void
    {
        if (! $utilisateur->can($permission)) {
            throw new ReferentielException(
                "Cette action exige l'habilitation « {$permission} », accordée nominativement par "
                .'le gestionnaire (CDC_09 §10).',
                403,
            );
        }
    }

    /** Le référentiel, verrouillé pour la durée de la transaction. */
    private function verrouiller(string $code, string $paysCode): Referentiel
    {
        $referentiel = Referentiel::query()
            ->where('code', $code)
            ->where('pays_code', $paysCode)
            ->lockForUpdate()
            ->first();

        if ($referentiel === null) {
            throw new ReferentielException(
                "Le référentiel « {$code} » n'est pas enregistré pour le pays « {$paysCode} ».",
                404,
            );
        }

        return $referentiel;
    }

    private function propositionADecider(Referentiel $referentiel, string $code): ReferentielVersion
    {
        $version = $referentiel->propositionEnCours();

        if ($version === null) {
            throw new ReferentielException("Aucune proposition en cours sur « {$code} ».");
        }

        return $version;
    }

    /**
     * Le numéro suivant, calculé sous le verrou du référentiel. L'`UNIQUE(referentiel_id, numero)`
     * reste le filet : si un chemin d'écriture futur oubliait le verrou, la base refuserait le
     * doublon plutôt que de laisser passer deux versions n°3.
     */
    private function prochainNumero(Referentiel $referentiel): int
    {
        return (int) $referentiel->versions()->max('numero') + 1;
    }
}
