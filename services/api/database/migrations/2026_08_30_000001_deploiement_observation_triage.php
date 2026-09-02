<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * P10c-3-ii — Déploiement en observation + captation des trois faits manquants.
 *
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 * 1) `predictions_ia.mode` REÇOIT `observation` — ET SURTOUT PAS `hybride` (F22)
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 *
 * `hybride` était réservé depuis P10c-2-i pour le jour où l'IA participerait à la décision. Ce jour
 * n'est pas celui-ci : CDC_08 §3 place le raisonnement IA au **sixième et dernier** rang, « jamais
 * pour contredire un protocole officiel », et le modèle de P10c-3-i ne prédit de toute façon pas un
 * niveau — il prédit le jugement qu'un soignant portera sur l'orientation.
 *
 * Écrire `hybride` affirmerait donc une participation qui n'a pas lieu. `observation` dit ce qui se
 * passe réellement : le modèle a répondu, sa réponse est enregistrée, elle n'a rien décidé.
 * `hybride` **reste dans l'ENUM et reste inatteignable** — même motif qu'`actif`/`archive` en
 * P10c-3-i : le contrat existe avant l'usage, aucune migration de donnée le jour venu.
 *
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 * 2) `predictions_ia` DEVIENT UNE CHAÎNE D'AUDIT (F28, décision propriétaire)
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 *
 * Sa propre migration l'annonçait : le durcissement était différé « quand un modèle réel écrira la
 * première explication ». Ce jour est celui-ci — une explication SHAP nomme les valeurs qui l'ont
 * produite, c'est du contenu clinique, et la table rejoint le régime de `protocole_applications`.
 *
 * ═══ LES 7 LIGNES ANTÉRIEURES NE SONT PAS SCELLÉES RÉTROACTIVEMENT, ET LE MOTIF LE DIT ═══
 *
 * `chaine` et `empreinte` sont NULLABLES, et les entrées écrites avant ce mécanisme restent à
 * `NULL`. Leur inventer une empreinte serait un mensonge d'archive (précédent L2, `protocole_code`
 * de P10b-1) ; les inclure dans la chaîne ferait crier une rupture permanente sur des lignes que
 * personne n'a touchées. `verifierUneChaine()` filtre sur `chaine`, elles en sortent donc
 * naturellement — et la déclaration d'origine porte leur nombre, pour que leur existence soit
 * écrite plutôt que déduite.
 *
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 * 3) `retours_cliniques_triage` — LE PREMIER FRAGMENT DE L'ÉPISODE CLINIQUE QUI MANQUAIT (F32→F34)
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 *
 * ═══ POURQUOI UNE TABLE NEUVE, ET NON TROIS COLONNES SUR `protocole_applications` ═══
 *
 * La raison est mécanique avant d'être conceptuelle : `JournalApplicationProtocole::charge()` fige
 * une liste de clés, et l'empreinte de chaque entrée est calculée dessus. Y ajouter les trois faits
 * **recalculerait l'empreinte des 37 entrées déjà écrites** — la chaîne entière crierait à
 * l'altération alors que rien n'aurait bougé. P10c-2-i avait déjà refusé une colonne « nature »
 * pour exactement ce motif.
 *
 * La raison conceptuelle la rejoint : le §10 journalise **l'évaluation d'un protocole et la
 * décision du soignant à son sujet**. Le diagnostic final n'en fait pas partie — c'est un fait
 * clinique d'une autre nature, celui que le §5.5.4 veut voir s'accumuler et que ce projet n'avait
 * jusqu'ici nulle part où mettre (aucune entité consultation/diagnostic, dette nommée en
 * P10c-2-i). Une chaîne séparée est d'ailleurs ce que le projet impose depuis P10b-1 : « mêler deux
 * journaux lierait la validité de l'un à celle de l'autre ».
 *
 * ═══ POURQUOI ELLE EST CHAÎNÉE, ELLE AUSSI ═══
 *
 * Elle porte un diagnostic posé par un médecin identifié. C'est précisément ce qu'un litige
 * discute. Et contrairement au socle à vide refusé en P6.3-D3, elle aura du contenu dès le premier
 * retour — le mécanisme n'y est pas posé « au cas où ».
 *
 * ═══ LIENS PAR IDENTIFIANT, JAMAIS PAR CLÉ ÉTRANGÈRE VIVANTE (ADR-042 D1) ═══
 *
 * `application_id`, `triage_id`, `soignant_id`, `maladie_id`, `specialite_id` : purger un compte ou
 * supprimer une ligne de référentiel ne doit pas faire crier une chaîne que personne n'a touchée.
 * C'est la leçon payée en P10b-2, et le motif pour lequel les libellés sont FIGÉS ici (P6.6b/P6.7b)
 * : une correction ultérieure du référentiel ne réécrit pas ce qu'un médecin a consigné ce jour-là.
 */
return new class extends Migration
{
    /** Les entrées antérieures au mécanisme, comptées à la migration pour être DITES. */
    private int $predictionsAnterieures = 0;

    public function up(): void
    {
        $this->predictionsAnterieures = (int) DB::table('predictions_ia')->count();

        $this->elargirModeObservation();
        $this->chainerPredictions();
        $this->tracerActivation();
        $this->creerRetoursCliniques();
        $this->elargirJeuApprentissage();
        $this->poserGardesAppendOnly();
        $this->declarerOrigines();
    }

    public function down(): void
    {
        $this->retirerGardesAppendOnly();

        DB::table('audit_chaines')
            ->whereIn('journal', ['predictions_ia', 'retours_cliniques_triage'])
            ->delete();

        Schema::dropIfExists('retours_cliniques_triage');

        Schema::table('jeux_donnees_entrainement', function (Blueprint $table): void {
            $table->dropColumn(['niveau_reel', 'maladie_code', 'specialite_code']);
        });

        Schema::table('versions_modeles', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('activee_par');
            $table->dropColumn('activee_le');
        });

        Schema::table('predictions_ia', function (Blueprint $table): void {
            $table->dropColumn(['chaine', 'empreinte_precedente', 'empreinte']);
        });

        // `mode` n'est PAS ramené à deux valeurs : une ligne `observation` déjà écrite deviendrait
        // invalide, et un `down()` qui détruit de la donnée écrite n'est pas une annulation.
    }

    /**
     * ═══ DÉFAUT ÉVITÉ DE JUSTESSE, ET IL EST DE LA FAMILLE QUE CE PROJET TRAQUE ═══
     *
     * La première écriture de cette méthode sortait sur « SQLite ne contraint pas les ENUM ».
     * C'est FAUX : Laravel traduit `enum()` en **contrainte CHECK** sous SQLite. La migration
     * n'aurait donc rien fait en test, où `observation` serait resté refusé, pendant qu'il passait
     * en production — une garantie **plus permissive en prod qu'en test**, exactement la divergence
     * refusée en P6.8c (collation) et P6.8e (REGEXP), ici dans le sens le plus trompeur puisque la
     * suite serait restée rouge sans qu'on sache pourquoi.
     *
     * `->change()` est natif depuis Laravel 11 sur les deux pilotes, et ce projet l'utilise déjà
     * ainsi pour élargir `triages.niveau` (P10b-1). On élargit, on ne réécrit jamais : les valeurs
     * existantes restent valides.
     */
    private function elargirModeObservation(): void
    {
        Schema::table('predictions_ia', function (Blueprint $table): void {
            $table->enum('mode', ['hybride', 'degrade', 'observation'])->change();
        });
    }

    private function chainerPredictions(): void
    {
        Schema::table('predictions_ia', function (Blueprint $table): void {
            // NULLABLE — voir l'en-tête : les entrées antérieures ne sont pas scellées après coup.
            $table->unsignedInteger('chaine')->nullable()->after('id');
            $table->string('empreinte_precedente', 64)->nullable()->after('cree_le');
            $table->string('empreinte', 64)->nullable()->after('empreinte_precedente');
            $table->index(['chaine', 'id'], 'idx_predictions_ia_chaine');
        });
    }

    /**
     * Qui a mis un modèle EN SERVICE, et quand (F24).
     *
     * `versions_modeles` portait déjà qui a entraîné et qui a validé cliniquement (§8 : « date de
     * validation clinique, responsable »). L'activation est un acte distinct — c'est elle qui fait
     * qu'un modèle répond à de vrais triages — et un registre qui ne dirait pas qui l'a posée
     * laisserait la question sans réponse le jour où elle se pose.
     *
     * `nullOnDelete` : identifiant d'audit, jamais une relation vivante (ADR-042 D1).
     */
    private function tracerActivation(): void
    {
        Schema::table('versions_modeles', function (Blueprint $table): void {
            $table->foreignId('activee_par')->nullable()->after('valide_par')
                ->constrained('users')->nullOnDelete();
            $table->timestamp('activee_le')->nullable()->after('date_validation_clinique');
        });
    }

    private function creerRetoursCliniques(): void
    {
        Schema::create('retours_cliniques_triage', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('chaine')->default(1);

            // Identifiants, jamais des relations vivantes (ADR-042 D1) — voir l'en-tête.
            $table->unsignedBigInteger('application_id')
                ->comment("L'entrée du journal §10 que ce retour complète (identifiant, pas FK).");
            $table->unsignedBigInteger('triage_id');
            $table->unsignedBigInteger('soignant_id')->nullable();
            $table->string('soignant_nom', 200);

            // ═══ LE NIVEAU RÉEL — FACULTATIF, ET C'EST DÉLIBÉRÉ (F33) ═══
            //
            // Le rendre obligatoire changerait le contrat d'un module validé G5 et bloquerait un
            // retour par ailleurs valide. Le vocabulaire est celui du §5.3 côté patient, déjà porté
            // par l'ENUM de `triages.niveau` — on n'en invente pas un second.
            $table->string('niveau_reel', 30)->nullable();

            // ═══ LE DIAGNOSTIC : UN LIEN, JAMAIS DU TEXTE LIBRE (F34) ═══
            //
            // Un texte libre rendrait insoluble « combien de paludismes parmi les triages
            // sous-évalués ? » et ferait d'une faute de frappe une catégorie (motif ADR-037). Le
            // code et le libellé sont FIGÉS : une correction du référentiel ne réécrit pas ce qu'un
            // médecin a consigné.
            $table->unsignedBigInteger('maladie_id')->nullable();
            $table->string('maladie_code', 20)->nullable();
            $table->string('maladie_libelle', 200)->nullable();

            // Même forme pour la spécialité qui a RÉELLEMENT pris en charge — à distinguer de
            // l'orientation PROPOSÉE par P10a. Ce sont deux faits, et leur écart est précisément ce
            // qu'on veut pouvoir mesurer.
            $table->unsignedBigInteger('specialite_id')->nullable();
            $table->string('specialite_code', 60)->nullable();
            $table->string('specialite_libelle', 200)->nullable();

            $table->timestamp('cree_le')->useCurrent();
            $table->string('empreinte_precedente', 64)->nullable();
            $table->string('empreinte', 64);

            $table->index(['chaine', 'id'], 'idx_retours_cliniques_chaine');
            $table->index('triage_id', 'idx_retours_cliniques_triage');
            // Une entrée du journal §10 est complétée AU PLUS UNE FOIS : le retour et sa précision
            // sont un seul acte, écrits dans la même transaction. Deux lignes voudraient dire que
            // quelqu'un a complété deux fois le même verdict, ce qui n'a pas de sens — un second
            // avis est un second retour, donc une seconde entrée de journal.
            $table->unique('application_id', 'uq_retour_clinique_application');
        });
    }

    /**
     * Les trois faits entrent aussi dans le jeu d'apprentissage (F32) — comme CIBLES.
     *
     * ═══ POURQUOI DEUX ENDROITS, ET POURQUOI CE N'EST PAS UNE SECONDE VÉRITÉ ═══
     *
     * `retours_cliniques_triage` est le JOURNAL : immuable, chaîné, nominatif, il dit ce qu'un
     * médecin a consigné ce jour-là. `jeux_donnees_entrainement` est un INSTANTANÉ d'apprentissage,
     * plat et jetable, reconstruit à chaque retour (P10c-2-i : « un instantané d'apprentissage
     * n'est pas un journal clinique »). Le second est une projection du premier, jamais une
     * seconde source — comme `label` l'est déjà de `decision_finale` depuis P10c-2-i.
     *
     * Nullable et JAMAIS rétroactif : les lignes antérieures n'ont eu aucun diagnostic, leur en
     * inventer un serait un mensonge d'archive (précédent L2).
     */
    private function elargirJeuApprentissage(): void
    {
        Schema::table('jeux_donnees_entrainement', function (Blueprint $table): void {
            $table->string('niveau_reel', 30)->nullable()->after('label');
            $table->string('maladie_code', 20)->nullable()->after('niveau_reel');
            $table->string('specialite_code', 60)->nullable()->after('maladie_code');
        });
    }

    private function poserGardesAppendOnly(): void
    {
        $mysql = DB::connection()->getDriverName() === 'mysql';

        foreach (['predictions_ia', 'retours_cliniques_triage'] as $table) {
            foreach (['UPDATE', 'DELETE'] as $evenement) {
                $nom = 'ck_'.$table.'_append_only_'.strtolower($evenement);
                $message = $table.'_append_only';

                DB::unprepared($mysql
                    ? "CREATE TRIGGER {$nom}
                       BEFORE {$evenement} ON {$table}
                       FOR EACH ROW
                       BEGIN
                           SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$message}';
                       END"
                    : "CREATE TRIGGER {$nom}
                       BEFORE {$evenement} ON {$table}
                       BEGIN SELECT RAISE(ABORT, '{$message}'); END"
                );
            }
        }
    }

    private function retirerGardesAppendOnly(): void
    {
        foreach (['predictions_ia', 'retours_cliniques_triage'] as $table) {
            foreach (['UPDATE', 'DELETE'] as $evenement) {
                DB::unprepared('DROP TRIGGER IF EXISTS ck_'.$table.'_append_only_'.strtolower($evenement));
            }
        }
    }

    /**
     * Déclarer l'origine (ADR-042) — sans quoi une chaîne tronquée par la tête serait indétectable.
     *
     * ═══ LES MOTIFS TIENNENT DANS 300 CARACTÈRES, ET IL A FALLU LA BASE RÉELLE POUR LE VOIR ═══
     *
     * `audit_chaines.motif` est un `string(300)`. La première rédaction dépassait — et la suite
     * était VERTE : **SQLite n'impose pas la longueur d'un `VARCHAR`, MySQL si**. La migration a
     * donc échoué au premier contact avec la base réelle (`1406 Data too long`), après avoir posé
     * une partie du schéma, le DDL MySQL n'étant pas transactionnel.
     *
     * Même famille que l'élargissement de l'ENUM plus haut : une garantie qui ne vaut que d'un
     * côté. Ici dans le sens le plus coûteux — aucun test ne pouvait la voir, seule une base réelle
     * le pouvait.
     */
    private function declarerOrigines(): void
    {
        $maintenant = now();

        DB::table('audit_chaines')->insert([
            [
                'journal' => 'predictions_ia',
                'numero' => 1,
                // Le motif dit EXACTEMENT ce que cette chaîne ne couvre pas. Un journal qui
                // commence sans dire ce qui le précède laisserait croire qu'il n'y avait rien.
                'motif' => "Ouverture à la mise en observation (P10c-3-ii). {$this->predictionsAnterieures} "
                    .'entrée(s) antérieure(s) restent à `chaine = NULL` : sans contenu clinique et '
                    ."jamais scellées. Leur inventer une empreinte serait un mensonge d'archive ; "
                    .'cette chaîne ne témoigne que de ce qui suit.',
                'acteur_nom' => 'Système (migration)',
                'empreinte_premiere' => null,
                'cree_le' => $maintenant,
            ],
            [
                'journal' => 'retours_cliniques_triage',
                'numero' => 1,
                'motif' => 'Ouverture à la création de la table (P10c-3-ii) : le journal est vide, et '
                    .'il le sera resté jusqu\'ici puisqu\'il naît avec cette migration.',
                'acteur_nom' => 'Système (migration)',
                'empreinte_premiere' => null,
                'cree_le' => $maintenant,
            ],
        ]);
    }
};
