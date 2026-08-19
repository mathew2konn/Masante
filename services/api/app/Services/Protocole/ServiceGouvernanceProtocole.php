<?php

namespace App\Services\Protocole;

use App\Models\Protocole;
use App\Models\ProtocoleJournal;
use App\Models\ProtocoleValidation;
use App\Models\ProtocoleVersion;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * P10b-1 — Le cycle de vie des protocoles (CDC_08 §6.2, §7, §10).
 *
 *     Rédaction → Relecture scientifique → Validation → Publication → Déploiement
 *     → Utilisation → Surveillance → Révision → Nouvelle version
 *
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 * LES QUATRE GARDES DE PUBLICATION — AUCUNE NE RATTRAPE LES AUTRES
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 *
 *  1. **Les quatre validations du §7**, présentes et favorables. Le refus NOMME celle qui manque :
 *     répondre « validation incomplète » obligerait à deviner laquelle, et un relecteur clinique
 *     n'a pas à chercher.
 *  2. **Le quatre-yeux** (§10 « double validation pour la publication ») : le publieur n'est pas le
 *     rédacteur. Vérifié ici ET par un déclencheur de base — le service peut être contourné par un
 *     import, la base non.
 *  3. **L'anti-substitution** : le contenu est ré-extrait et confronté à l'empreinte figée par
 *     chaque validation. Sans elle, on publierait des règles cliniques que **personne n'a relues** :
 *     il suffirait de faire signer un texte anodin puis d'en changer les seuils avant publication.
 *     Transposition du contrôle central de P6.3 et du « destination révoquée depuis le figeage »
 *     de P5.5b-2 — là il s'agissait d'argent, ici de conduites à tenir.
 *  4. **Les contrôles techniques du §7.4** ({@see ControleQualiteProtocole}), bloquants.
 *
 * ═══ L'HABILITATION EST VÉRIFIÉE ICI, DANS LE SERVICE ═══
 *
 * Et non par le middleware `permission:` de spatie : ces routes sont authentifiées par Sanctum
 * alors que les permissions du projet vivent sur le guard `web`. Le middleware refuserait sur un
 * désaccord de guard plutôt que sur un défaut de droit — piège rencontré en P4 sur `rdv.validate`,
 * puis en P6.3, P6.5a, P6.6a et P6.8. Le service reste de toute façon le bon endroit : il est
 * faisant autorité quel que soit l'appelant.
 *
 * ═══ MFA (§10) ═══
 *
 * Le §10 exige « MFA obligatoire » pour l'édition des protocoles. Le MFA TOTP existe depuis P1
 * derrière la porte `MFA_ENFORCE`, aujourd'hui fermée. Classé **« prêt à activer »** et dit comme
 * tel — pas déguisé en garantie active (classement ADR-014).
 */
final class ServiceGouvernanceProtocole
{
    public const PERMISSION_REDIGER = 'protocole.rediger';

    public const PERMISSION_PUBLIER = 'protocole.publier';

    /**
     * Une permission PAR TYPE DE VALIDATION du §7, et c'est la raison d'être de la découpe.
     *
     * Le §7 confie la validation clinique à des médecins spécialistes et la réglementaire au
     * Ministère : une permission unique laisserait un technicien signer les quatre couches, ce qui
     * viderait le §7 de son objet. Treizième occurrence du précédent « permission portée par aucun
     * rôle métier », et la plus littérale depuis `assurance.referentiel` (P6.8d).
     *
     * CE QUI N'EST DÉLIBÉRÉMENT PAS AJOUTÉ : l'interdiction pour un même agent de porter plusieurs
     * de ces permissions. Le §7 ne l'exige pas, et un garde-fou plus strict que sa propre règle est
     * un défaut, même quand il refuse par prudence (leçon de la collation en P6.8c). En revanche le
     * journal NOMME qui a signé quoi.
     */
    public const PERMISSIONS_VALIDATION = [
        ProtocoleValidation::CLINIQUE      => 'protocole.valider.clinique',
        ProtocoleValidation::REGLEMENTAIRE => 'protocole.valider.reglementaire',
        ProtocoleValidation::SCIENTIFIQUE  => 'protocole.valider.scientifique',
        ProtocoleValidation::TECHNIQUE     => 'protocole.valider.technique',
    ];

    public function __construct(
        private readonly CompilateurProtocole $compilateur,
        private readonly ControleQualiteProtocole $qualite,
        private readonly JournalProtocole $journal,
    ) {}

    /**
     * Enregistre un protocole au registre (§4.1). Il naît SANS version : il n'a donc rien à
     * appliquer, ce qui est l'état correct — le §1.6 veut qu'aucun protocole ne soit utilisable
     * avant validation.
     *
     * @param  array<string, mixed>  $attributs
     */
    public function creer(string $code, string $paysCode, User $auteur, array $attributs): Protocole
    {
        $this->exigerHabilitation($auteur, self::PERMISSION_REDIGER);

        return DB::transaction(function () use ($code, $paysCode, $auteur, $attributs): Protocole {
            if (Protocole::query()->where('pays_code', $paysCode)->where('code', $code)->exists()) {
                throw new ProtocoleException(
                    "Un protocole « {$code} » existe déjà pour le pays « {$paysCode} ».",
                );
            }

            $protocole = new Protocole($attributs);

            // `code` et `pays_code` sont hors `$fillable` : ils ne peuvent pas venir d'une
            // assignation de masse, donc pas d'un client. Précédent `identifiant_national` en
            // P6.4a et `numero_professionnel` en P6.5a.
            $protocole->code = $code;
            $protocole->pays_code = $paysCode;
            $protocole->save();

            $this->journal->inscrire($protocole, ProtocoleJournal::CREATION, $auteur, null, [
                'titre'         => $protocole->titre,
                'domaine'       => $protocole->domaine,
                'niveau_source' => $protocole->niveau_source,
            ]);

            return $protocole;
        });
    }

    /**
     * Ouvre un brouillon (§6.2 « Rédaction »).
     *
     * Au plus UN brouillon par protocole — garanti par `uq_protocole_version_verrou`. Deux
     * brouillons rendraient « lequel valide-t-on ? » ambigu au moment précis où le §7 demande une
     * signature nominative.
     */
    public function ouvrirBrouillon(
        Protocole $protocole,
        User $auteur,
        string $libelleVersion,
        string $motif,
        array $attributs = [],
    ): ProtocoleVersion {
        $this->exigerHabilitation($auteur, self::PERMISSION_REDIGER);

        return DB::transaction(function () use ($protocole, $auteur, $libelleVersion, $motif, $attributs): ProtocoleVersion {
            $protocole = $this->verrouiller($protocole);

            if ($protocole->brouillon() !== null) {
                throw new ProtocoleException(
                    "Un brouillon est déjà ouvert sur « {$protocole->code} » : il faut le publier "
                    .'ou l\'abandonner avant d\'en ouvrir un autre.',
                );
            }

            $version = ProtocoleVersion::create([
                'protocole_id'   => $protocole->id,
                'numero'         => $this->prochainNumero($protocole),
                'libelle'        => $libelleVersion,
                'etat'           => ProtocoleVersion::BROUILLON,
                'verrou_unicite' => ProtocoleVersion::verrouPour(ProtocoleVersion::BROUILLON, $protocole->id),
                'motif'          => $motif,
                'redige_par'     => $auteur->id,
                'redige_le'      => Carbon::now(),
                ...$attributs,
            ]);

            $this->journal->inscrire(
                $protocole,
                ProtocoleJournal::BROUILLON_OUVERT,
                $auteur,
                $version->numero,
                ['version' => $version->libelle, 'motif' => $motif],
            );

            return $version;
        });
    }

    /**
     * Enregistre une validation du §7.
     *
     * ═══ L'EMPREINTE EST FIGÉE ICI, ET C'EST TOUT L'ENJEU ═══
     *
     * On enregistre l'empreinte du contenu **au moment de la signature**. C'est elle que la
     * publication confrontera. Le validateur signe donc un texte précis, pas un protocole en
     * général — ce que « opposable » (§7) suppose.
     *
     * La ligne est APPEND-ONLY : une nouvelle relecture pose une nouvelle validation, elle
     * n'écrase pas la précédente. Effacer une signature pour en poser une autre effacerait
     * précisément ce qui est opposable.
     */
    public function valider(
        ProtocoleVersion $version,
        User $validateur,
        string $type,
        string $avis,
        string $role,
        ?string $commentaires = null,
    ): ProtocoleValidation {
        if (! isset(self::PERMISSIONS_VALIDATION[$type])) {
            throw new ProtocoleException(
                "Type de validation inconnu « {$type} ». Le §7 en définit quatre : "
                .implode(', ', array_keys(self::PERMISSIONS_VALIDATION)).'.',
                422,
            );
        }

        $this->exigerHabilitation($validateur, self::PERMISSIONS_VALIDATION[$type]);

        return DB::transaction(function () use ($version, $validateur, $type, $avis, $role, $commentaires): ProtocoleValidation {
            $version->refresh();

            // On ne valide qu'un brouillon : signer une version déjà publiée laisserait croire
            // qu'on peut ajouter une caution après coup à un texte déjà appliqué à des patients.
            if ($version->etat !== ProtocoleVersion::BROUILLON) {
                throw new ProtocoleException(
                    'Seul un brouillon se valide : cette version est déjà '.$version->etat.'.',
                );
            }

            $protocole = $this->verrouiller($version->protocole);

            $validation = ProtocoleValidation::create([
                'version_id'        => $version->id,
                'type'              => $type,
                'validateur_id'     => $validateur->id,
                // Dénormalisés : le §7 exige « validateur, rôle », et la pièce doit rester lisible
                // après la suppression d'un compte.
                'validateur_nom'    => $validateur->nomLisible(),
                'validateur_role'   => $role,
                'avis'              => $avis,
                'commentaires'      => $commentaires,
                'empreinte_contenu' => $this->compilateur->empreinte($version),
                'valide_le'         => Carbon::now(),
            ]);

            $this->journal->inscrire($protocole, ProtocoleJournal::VALIDATION, $validateur, $version->numero, [
                'type'      => $type,
                'avis'      => $avis,
                'role'      => $role,
                'empreinte' => $validation->empreinte_contenu,
            ]);

            return $validation;
        });
    }

    /**
     * Publie une version (§6.2 « Publication → Déploiement »).
     *
     * La version précédemment active est ARCHIVÉE, jamais supprimée : §6.1 « un protocole archivé
     * reste consultable indéfiniment », et les décisions qui la citent doivent rester explicables.
     */
    public function publier(ProtocoleVersion $version, User $publieur): ProtocoleVersion
    {
        $this->exigerHabilitation($publieur, self::PERMISSION_PUBLIER);

        return DB::transaction(function () use ($version, $publieur): ProtocoleVersion {
            $version->refresh();

            if ($version->etat !== ProtocoleVersion::BROUILLON) {
                throw new ProtocoleException(
                    'Seul un brouillon se publie : cette version est déjà '.$version->etat.'.',
                );
            }

            $protocole = $this->verrouiller($version->protocole);

            // ═══ GARDE 2 — QUATRE-YEUX (§10) ═══
            //
            // 409 et non 403 : le publieur a bien le droit de publier, c'est CETTE publication-là
            // qu'il ne peut pas faire (précédent P7-C).
            if ($version->redige_par !== null && $version->redige_par === $publieur->id) {
                throw new ProtocoleException(
                    'Le rédacteur d\'une version ne peut pas la publier lui-même : le §10 exige une '
                    .'double validation. Un autre agent habilité doit décider.',
                );
            }

            $contenu = $this->compilateur->extraire($version);
            $empreinte = \App\Services\Referentiel\EmpreinteReferentiel::duContenu($contenu);

            // ═══ GARDE 4 — CONTRÔLES TECHNIQUES §7.4 ═══
            //
            // Avant l'anti-substitution : un contenu incohérent doit être signalé pour ce qu'il
            // est, pas rejeté sous prétexte qu'il a bougé depuis la relecture.
            $anomalies = $this->qualite->controler($contenu);

            if ($anomalies !== []) {
                throw ProtocoleException::qualite($anomalies);
            }

            // ═══ GARDES 1 ET 3 — LES QUATRE VALIDATIONS, ET LEUR FRAÎCHEUR ═══
            $this->exigerValidationsCompletes($version, $empreinte);

            // La version en vigueur cède la place. `saveAndFlush` plutôt qu'un `save()` ordinaire :
            // Hibernate n'est pas en cause ici, mais Eloquent réordonne lui aussi les écritures
            // d'une transaction, et le déclencheur d'unicité refuserait deux lignes portant
            // `A:<id>` si l'archivage passait après la publication (bug attrapé au G2 de P5.5b-1,
            // même famille).
            $active = $protocole->versionActive();

            if ($active !== null) {
                $active->etat = ProtocoleVersion::ARCHIVE;
                $active->verrou_unicite = null;
                $active->save();
                $active->refresh();
            }

            $version->contenu_json = $contenu;
            $version->empreinte = $empreinte;
            $version->etat = ProtocoleVersion::ACTIF;
            $version->verrou_unicite = ProtocoleVersion::verrouPour(ProtocoleVersion::ACTIF, $protocole->id);
            $version->publie_par = $publieur->id;
            $version->publie_le = Carbon::now();
            $version->save();

            $this->journal->inscrire($protocole, ProtocoleJournal::PUBLICATION, $publieur, $version->numero, [
                'version'          => $version->libelle,
                'empreinte'        => $empreinte,
                'nb_regles'        => count($contenu['regles']),
                'version_archivee' => $active?->libelle,
            ]);

            if ($active !== null) {
                $this->journal->inscrire($protocole, ProtocoleJournal::ARCHIVAGE, $publieur, $active->numero, [
                    'version'    => $active->libelle,
                    'remplacee_par' => $version->libelle,
                ]);
            }

            return $version;
        });
    }

    /**
     * Les quatre validations du §7 sont-elles présentes, favorables, ET portées sur CE contenu ?
     *
     * Le message NOMME ce qui manque et POURQUOI. Un refus « validation incomplète » obligerait le
     * rédacteur à deviner laquelle des quatre — et un refus qui ne dit pas quoi corriger ramène la
     * faute qu'il prétend fermer (raisonnement du formulaire de P6.8a, dont le message nomme les
     * termes admis).
     */
    private function exigerValidationsCompletes(ProtocoleVersion $version, string $empreinteCourante): void
    {
        $courantes = $version->validationsCourantes();
        $manquantes = [];
        $caduques = [];
        $defavorables = [];

        foreach (array_keys(self::PERMISSIONS_VALIDATION) as $type) {
            $validation = $courantes[$type] ?? null;

            if ($validation === null) {
                $manquantes[] = $type;

                continue;
            }

            if ($validation->avis !== ProtocoleValidation::FAVORABLE) {
                $defavorables[] = "{$type} (avis « {$validation->avis} » de {$validation->validateur_nom})";

                continue;
            }

            if (! $validation->autorisePublication($empreinteCourante)) {
                $caduques[] = "{$type} (signée par {$validation->validateur_nom})";
            }
        }

        if ($manquantes !== []) {
            throw new ProtocoleException(
                'Validation incomplète (§7) : il manque la validation '
                .implode(', ', $manquantes).'. Aucun protocole n\'est utilisable sans ses quatre '
                .'validations (§1.6).',
            );
        }

        if ($defavorables !== []) {
            throw new ProtocoleException(
                'Validation non favorable (§7) : '.implode(' ; ', $defavorables).'.',
            );
        }

        // ═══ L'ANTI-SUBSTITUTION, ET LE MESSAGE DIT CE QUI S'EST PASSÉ ═══
        if ($caduques !== []) {
            throw new ProtocoleException(
                'Le contenu du protocole a été modifié depuis sa relecture : les validations '
                .implode(', ', $caduques).' ne portent plus sur ce texte. Publier maintenant '
                .'mettrait en vigueur des règles cliniques que personne n\'a relues. Faites '
                .'re-signer les relecteurs concernés.',
            );
        }
    }

    private function exigerHabilitation(User $utilisateur, string $permission): void
    {
        if (! $utilisateur->can($permission)) {
            throw new ProtocoleException(
                "Cette action exige l'habilitation « {$permission} », accordée nominativement "
                .'(CDC_08 §10 : accès à l\'édition des protocoles strictement réservé aux rôles '
                .'habilités).',
                403,
            );
        }
    }

    /** Le protocole, verrouillé pour la durée de la transaction. */
    private function verrouiller(Protocole $protocole): Protocole
    {
        return Protocole::query()->whereKey($protocole->id)->lockForUpdate()->firstOrFail();
    }

    /**
     * Le numéro suivant, calculé sous le verrou du protocole. L'`UNIQUE(protocole_id, numero)`
     * reste le filet : si un chemin d'écriture futur oubliait le verrou, la base refuserait le
     * doublon plutôt que de laisser passer deux versions n°3.
     */
    private function prochainNumero(Protocole $protocole): int
    {
        return (int) $protocole->versions()->max('numero') + 1;
    }
}
