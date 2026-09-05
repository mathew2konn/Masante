<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * B5-b — LE PRÉLÈVEMENT ET SON CYCLE (CDC_09 §7.4, CDC_04 §109).
 *
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 * SIX ÉTATS, DONT DEUX NE SERONT ATTEIGNABLES QU'EN B5-c — ET C'EST ASSUMÉ (L6)
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 *
 * `preleve → [expedie] → recu → en_analyse → valide → publie`. Les colonnes des deux derniers
 * états (`valide_le`, `valide_par_user_id`, `valide_par_nom`, `publie_le`, `resultat_analyse_id`)
 * sont posées ICI, dans la MÊME table — parce que c'est une seule table à un seul cycle de vie —
 * mais aucun service de B5-b ne les atteint : la validation biologique suppose un résultat, qui
 * n'existe pas encore. C'est la même situation que `StatutDemandeAnalyse::SERVIE`/`ANNULEE` en
 * B5-a, transposée un cran plus loin dans le même circuit.
 *
 * ═══ CE QUI EST UN IDENTIFIANT, ET CE QUI EST UNE RELATION VIVANTE (ADR-042 D1) ═══
 *
 * `resultat_analyse_id` est SANS contrainte : le patient peut supprimer la ligne de son carnet, le
 * prélèvement — pièce médico-légale du circuit — doit y survivre. B3-c a payé le prix exact de
 * l'oubli inverse (une FK `nullOnDelete` sur une table append-only empêchant de supprimer le
 * parent) ; `execute_par_user_id`/`valide_par_user_id` sont eux aussi de simples identifiants : un
 * compte supprimé ne doit pas effacer qui a exécuté ou validé, d'où les colonnes `*_par_nom` FIGÉES
 * à côté.
 *
 * ═══ POURQUOI `preleve_par_nom` N'A PAS DE `_user_id`, ALORS QUE LES AUTRES EN ONT ═══
 *
 * Le prélèvement physique (l'étape 2 du §7.4) est parfois fait par quelqu'un qui n'a pas de session
 * portail ouverte à cet instant précis (un préleveur au lit du patient). Son nom est déclaré et
 * conservé ; l'identité complète du geste (qui, quand, par quelle action) vit de toute façon dans
 * `journal_laboratoire` (L13), qui trace CHAQUE acte — y compris ceux que cette table ne détaille
 * pas sur sa propre ligne (`expedie_le`/`recu_le` n'ont d'ailleurs aucune colonne d'acteur du tout).
 *
 * ═══ LES QUATRE GARDES DU MOTEUR (dual-dialecte, mur de P6.3 : `CHECK` impossible, erreur 3823) ═══
 *
 * 1. `valide` exige `valide_par_user_id` ET `valide_le` (les deux ou aucun).
 * 2. `publie` exige `resultat_analyse_id` ET `publie_le`.
 * 3. Un `identifiant` vide est refusé — c'est l'étiquette, elle ne peut jamais être blanche.
 * 4. Les états NE REMONTENT PAS (comparaison sur le RANG du statut, table de correspondance dans
 *    le déclencheur lui-même, PHP et SQL devant s'accorder sur le même ordre — `StatutPrelevement::rang()`
 *    en est le miroir applicatif, une vérification de plus, jamais la seule).
 *
 * ═══ `journal_laboratoire` : LE MÊME NIVEAU D'EXIGENCE QUE LE JOURNAL DU MÉDECIN, SANS EN ÊTRE LE
 *     MÉCANISME (L13, demande explicite du propriétaire) ═══
 *
 * `acces_dossier` journalise l'ouverture d'une fenêtre sur un dossier ; L3 pose qu'un laboratoire
 * n'en ouvre AUCUNE. Vérifié plutôt que supposé : `ServiceFicheParcours` apparie les lignes
 * d'`acces_dossier` en ouvertures/clôtures sur `duree_minutes !== null` — une ligne de dépôt isolée
 * s'y afficherait « consultation non clôturée », une phrase fausse dans le document même qui existe
 * pour dire au patient ce qui s'est passé. D'où une table À PART, append-only (modèle ET
 * déclencheurs, patron `protocole_applications`/`traces_dispensation`), qui trace CHAQUE acte du
 * circuit — y compris ceux qui ne touchent aucun carnet.
 *
 * L'ENUM `action` NE PORTE ICI QUE LES ACTES DE B5-b (`jeton_consulte`, `prelevement_enregistre`,
 * `expedie`, `recu`, `mis_en_analyse`). `validation`/`rejet`/`publication`/`import_automate`
 * n'y figurent PAS : les y ajouter maintenant recréerait le défaut que B5-a vient de corriger sur
 * `source` — une clé dormante SANS ÉMETTEUR (K5). L'enum sera étendu de façon ADDITIVE quand B5-c
 * livrera ces actes, jamais par anticipation.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('prelevements', function (Blueprint $table) {
            $table->id();

            $table->foreignId('demande_id')->constrained('demandes_analyses')->cascadeOnDelete();

            // L'ÉTIQUETTE (L5) — opaque, non séquentielle, distincte du jeton de la demande.
            $table->string('identifiant', 20)->unique();

            $table->foreignId('laboratoire_structure_id')->nullable()
                ->constrained('structures_sanitaires')->nullOnDelete();

            $table->enum('statut', [
                'preleve', 'expedie', 'recu', 'en_analyse', 'valide', 'publie',
            ])->default('preleve');

            // Étape 2 — le prélèvement lui-même.
            $table->timestamp('preleve_le')->useCurrent();
            $table->string('preleve_par_nom', 200)->nullable();

            // Étape 4 — le transport, FACULTATIF (L6).
            $table->timestamp('expedie_le')->nullable();

            // Étape 5 — la réception/accession au laboratoire.
            $table->timestamp('recu_le')->nullable();

            // Étape 6 — la mise en analyse.
            $table->timestamp('analyse_le')->nullable();
            $table->unsignedBigInteger('execute_par_user_id')->nullable();

            // Étape 7 — la validation biologique (B5-c pour le service, colonnes ici).
            $table->timestamp('valide_le')->nullable();
            $table->unsignedBigInteger('valide_par_user_id')->nullable();
            $table->string('valide_par_nom', 200)->nullable();

            // Étape 8 — la publication au dossier patient (B5-c).
            $table->timestamp('publie_le')->nullable();
            $table->unsignedBigInteger('resultat_analyse_id')->nullable();

            $table->timestamps();

            $table->index('demande_id', 'idx_prelevement_demande');
            $table->index('laboratoire_structure_id', 'idx_prelevement_laboratoire');
            $table->index('statut', 'idx_prelevement_statut');
        });

        Schema::create('journal_laboratoire', function (Blueprint $table) {
            $table->id();

            // Identifiants SANS contrainte (ADR-042 D1) : un journal ne se laisse pas modifier par
            // la suppression d'un compte, d'une demande ou d'un prélèvement.
            $table->unsignedBigInteger('demande_id')->nullable();
            $table->unsignedBigInteger('prelevement_id')->nullable();
            $table->unsignedBigInteger('acteur_user_id')->nullable();
            $table->string('acteur_nom', 200)->nullable();

            $table->foreignId('laboratoire_structure_id')->nullable()
                ->constrained('structures_sanitaires')->nullOnDelete();

            // B5-b SEUL : voir le commentaire de classe sur l'extension additive future.
            $table->enum('action', [
                'jeton_consulte', 'prelevement_enregistre', 'expedie', 'recu', 'mis_en_analyse',
            ]);

            $table->timestamp('cree_le')->useCurrent();

            $table->index('demande_id', 'idx_journal_labo_demande');
            $table->index('prelevement_id', 'idx_journal_labo_prelevement');
            $table->index(['laboratoire_structure_id', 'cree_le'], 'idx_journal_labo_structure');
        });

        $this->poserGardes();
    }

    public function down(): void
    {
        $this->retirerGardes();

        Schema::dropIfExists('journal_laboratoire');
        Schema::dropIfExists('prelevements');
    }

    private function poserGardes(): void
    {
        $pilote = Schema::getConnection()->getDriverName();

        // Rangs des états — MÊME ORDRE que `StatutPrelevement::rang()`, dupliqué ici parce qu'un
        // déclencheur ne peut pas appeler du PHP : la garde du moteur doit rester autonome.
        $rangs = 'CASE statut '
            ."WHEN 'preleve' THEN 1 WHEN 'expedie' THEN 2 WHEN 'recu' THEN 3 "
            ."WHEN 'en_analyse' THEN 4 WHEN 'valide' THEN 5 WHEN 'publie' THEN 6 END";
        $rangsNew = str_replace('statut', 'NEW.statut', $rangs);
        $rangsOld = str_replace('statut', 'OLD.statut', $rangs);

        if ($pilote === 'mysql') {
            foreach (['INSERT', 'UPDATE'] as $moment) {
                $nom = 'trg_prelevement_'.strtolower($moment);
                $verifOld = $moment === 'UPDATE'
                    ? "IF ({$rangsNew}) < ({$rangsOld}) THEN
                            SIGNAL SQLSTATE '45000'
                              SET MESSAGE_TEXT = 'Un prelevement ne peut pas revenir a un etat anterieur.';
                        END IF;"
                    : '';

                DB::unprepared("
                    CREATE TRIGGER {$nom} BEFORE {$moment} ON prelevements
                    FOR EACH ROW
                    BEGIN
                        IF NEW.identifiant IS NULL OR NEW.identifiant = '' THEN
                            SIGNAL SQLSTATE '45000'
                              SET MESSAGE_TEXT = 'Un prelevement doit porter un identifiant.';
                        END IF;
                        IF NEW.statut = 'valide' AND (NEW.valide_par_user_id IS NULL OR NEW.valide_le IS NULL) THEN
                            SIGNAL SQLSTATE '45000'
                              SET MESSAGE_TEXT = 'Un prelevement valide doit porter son valideur et sa date.';
                        END IF;
                        IF NEW.statut = 'publie' AND (NEW.resultat_analyse_id IS NULL OR NEW.publie_le IS NULL) THEN
                            SIGNAL SQLSTATE '45000'
                              SET MESSAGE_TEXT = 'Un prelevement publie doit porter son resultat et sa date.';
                        END IF;
                        {$verifOld}
                    END
                ");
            }

            foreach (['UPDATE', 'DELETE'] as $moment) {
                $nom = 'trg_journal_labo_'.strtolower($moment);
                DB::unprepared("
                    CREATE TRIGGER {$nom} BEFORE {$moment} ON journal_laboratoire
                    FOR EACH ROW
                    BEGIN
                        SIGNAL SQLSTATE '45000'
                          SET MESSAGE_TEXT = 'Une entree du journal du laboratoire ne se modifie ni ne s''efface : append-only.';
                    END
                ");
            }

            return;
        }

        // SQLite (suite de tests) : même garantie, dialecte différent — divergence refusée depuis
        // P6.8c (collation) et P6.8e (REGEXP).
        foreach (['UPDATE', 'DELETE'] as $moment) {
            $nom = 'trg_journal_labo_'.strtolower($moment);
            DB::unprepared("
                CREATE TRIGGER {$nom} BEFORE {$moment} ON journal_laboratoire
                FOR EACH ROW
                BEGIN
                    SELECT RAISE(ABORT, 'Une entree du journal du laboratoire ne se modifie ni ne s''efface : append-only.');
                END
            ");
        }

        foreach (['INSERT', 'UPDATE'] as $moment) {
            $nomBase = 'trg_prelevement_'.strtolower($moment);

            DB::unprepared("
                CREATE TRIGGER {$nomBase}_forme BEFORE {$moment} ON prelevements
                FOR EACH ROW
                WHEN NEW.identifiant IS NULL OR NEW.identifiant = ''
                   OR (NEW.statut = 'valide' AND (NEW.valide_par_user_id IS NULL OR NEW.valide_le IS NULL))
                   OR (NEW.statut = 'publie' AND (NEW.resultat_analyse_id IS NULL OR NEW.publie_le IS NULL))
                BEGIN
                    SELECT RAISE(ABORT, 'Prelevement invalide : identifiant, validation ou publication incomplete.');
                END
            ");

            if ($moment === 'UPDATE') {
                DB::unprepared("
                    CREATE TRIGGER {$nomBase}_rang BEFORE UPDATE ON prelevements
                    FOR EACH ROW
                    WHEN ({$rangsNew}) < ({$rangsOld})
                    BEGIN
                        SELECT RAISE(ABORT, 'Un prelevement ne peut pas revenir a un etat anterieur.');
                    END
                ");
            }
        }
    }

    private function retirerGardes(): void
    {
        foreach (['insert', 'update'] as $moment) {
            DB::unprepared("DROP TRIGGER IF EXISTS trg_prelevement_{$moment}");
            DB::unprepared("DROP TRIGGER IF EXISTS trg_prelevement_{$moment}_forme");
        }
        DB::unprepared('DROP TRIGGER IF EXISTS trg_prelevement_update_rang');

        foreach (['update', 'delete'] as $moment) {
            DB::unprepared("DROP TRIGGER IF EXISTS trg_journal_labo_{$moment}");
        }
    }
};
