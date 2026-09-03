<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * B2-a — LA CONSULTATION (CDC_11 §5.2, CDC_04 §12 étape 7).
 *
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 * POURQUOI UNE TABLE, ALORS QUE `acces_dossier` PORTE DÉJÀ PRESQUE TOUT
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 *
 * Le G0 a trouvé que `acces_dossier` porte déjà `membre_id`, `agent_id`, `etablissement`,
 * `triage_id` (P10c-2-i), `rendez_vous_id` (B1-c), `donnees_ajoutees` (P7-D0), `duree_minutes` et
 * la corrélation ouverture/clôture — et que le commentaire de la migration de P10c-2-i emploie
 * littéralement le mot « consultation ». Y ajouter trois colonnes aurait été beaucoup plus court.
 *
 * Quatre raisons de ne pas le faire, dont deux sont des décisions déjà prises par ce projet :
 *
 *  1. DEUX NATURES DE VÉRITÉ DANS LA MÊME TABLE. `acces_dossier` est un journal d'accès régi par
 *     la loi 2013-450 ; P7-D2 a décidé que le journal brut reste réservé au propriétaire du
 *     dossier, parce qu'il porte l'adresse IP et TOUTES les lectures familiales. Y verser le
 *     contenu clinique mêlerait un registre de surveillance et un acte de soin — le refus que
 *     P6.6a a opposé aux interactions, et P6.3 au journal de gouvernance.
 *  2. UN JOURNAL EST IMMUABLE, UNE CONSULTATION SE RÉDIGE. Une ligne d'accès est écrite une fois,
 *     à l'ouverture puis à la clôture. Une consultation se complète pendant qu'elle a lieu.
 *     Rendre éditable une ligne d'audit détruirait ce que ce journal existe pour prouver.
 *  3. LES CARDINALITÉS NE COÏNCIDENT PAS. Un accès existe sans consultation (lecture familiale,
 *     bris de glace, un médecin qui ouvre puis referme sans acte).
 *  4. PRÉCÉDENT DIRECT, PRIS CINQ JOURS PLUS TÔT. P10c-3-ii a créé `retours_cliniques_triage` en
 *     table séparée plutôt que d'ajouter trois colonnes à `protocole_applications`.
 *
 * ═══ CE QUI EST UN IDENTIFIANT, ET CE QUI EST UNE RELATION VIVANTE ═══
 *
 * `acces_dossier_id`, `rendez_vous_id`, `triage_id` et `soignant_user_id` sont des IDENTIFIANTS
 * SANS CLÉ ÉTRANGÈRE (ADR-042 D1, et `acces_dossier.agent_id` fait déjà exactement cela) :
 * supprimer un compte ou une ligne de journal ne doit pas effacer l'acte de soin, ni la vider de
 * son auteur. Une consultation a une valeur médico-légale : elle doit dire qui l'a menée, pour
 * toujours — d'où `soignant_nom`, FIGÉ à l'ouverture par le serveur (patron P6.6b/P6.7b).
 *
 * `medecin_id` et `structure_id`, eux, sont de vraies relations en `nullOnDelete` : c'est le geste
 * EXACT de P6.7b sur `resultats_analyses` (`medecin_prescripteur_id`, `laboratoire_id`), et il
 * répond au constat Y2 du G0 — « toutes les consultations du Dr X » devient soluble.
 *
 * ═══ CE QUI EST CHIFFRÉ, ET POURQUOI PAS LE RESTE ═══
 *
 * Ligne déjà tranchée par le projet et constatée au G0 (Y9) : `resultats_analyses` laisse en clair
 * `intitule`, `date_analyse`, `laboratoire`, et ne chiffre que `resultats_json`. Les identifiants
 * et métadonnées sont en clair, le contenu clinique est chiffré. `motif` suit cette ligne.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('consultations', function (Blueprint $table) {
            $table->id();

            $table->foreignId('membre_id')->constrained('membres_famille')->cascadeOnDelete();

            // QUI A MENÉ L'ACTE. Identifiant sans FK : le compte peut être supprimé (droit de la
            // loi 2013-450), l'acte reste et doit continuer de nommer son auteur.
            $table->unsignedBigInteger('soignant_user_id')->nullable();
            $table->string('soignant_nom', 200);

            // La fiche professionnelle, quand elle existe (`medecins.user_id` la relie au compte).
            // Vraie relation, patron P6.7b : un praticien retiré du référentiel ne doit pas
            // emporter les consultations qu'il a menées.
            $table->foreignId('medecin_id')->nullable()->constrained('medecins')->nullOnDelete();

            $table->foreignId('structure_id')->nullable()
                ->constrained('structures_sanitaires')->nullOnDelete();
            $table->string('structure_nom', 200)->nullable();

            // Le rattachement au journal d'accès qui a rendu l'acte possible. UNIQUE : une session
            // d'accès porte AU PLUS une consultation. Plusieurs écritures dans une même session
            // sont plusieurs actes de la MÊME consultation, pas plusieurs consultations.
            // (MySQL comme SQLite autorisent plusieurs NULL dans un index unique : une consultation
            // sans accès reste possible.)
            $table->unsignedBigInteger('acces_dossier_id')->nullable();

            $table->unsignedBigInteger('rendez_vous_id')->nullable();
            $table->unsignedBigInteger('triage_id')->nullable();

            $table->enum('statut', ['en_cours', 'cloturee'])->default('en_cours');

            // Contenu clinique → chiffré, comme `antecedents.description` et
            // `notes_observations.contenu`. Non interrogeable, et c'est le prix assumé.
            $table->text('motif')->nullable();

            $table->timestamp('debutee_le')->useCurrent();
            $table->timestamp('cloturee_le')->nullable();

            $table->timestamps();

            $table->unique('acces_dossier_id', 'uq_consultation_acces');
            $table->index(['membre_id', 'debutee_le'], 'idx_consultation_membre');
            $table->index('soignant_user_id', 'idx_consultation_soignant');
            $table->index('rendez_vous_id', 'idx_consultation_rdv');
            $table->index('triage_id', 'idx_consultation_triage');
        });

        // ═══ Z-a — LA TABLE DES OBSERVATIONS EXISTE DÉJÀ, ON NE LA RECRÉE PAS ═══
        //
        // Le G0 d'implémentation a trouvé `notes_observations`, créée le 2026-07-02 : elle est
        // exactement les `observations` du CDC_04 §103 (contenu chiffré, append-only, auteur,
        // lien triage), et son propre commentaire annonçait le rattachement au praticien comme
        // « différé aux Modules 3/4 ». En créer une seconde aurait mis deux natures du même fait
        // dans deux tables — ce que P6.6a refuse. Enrichissement additif (ADR-024).
        Schema::table('notes_observations', function (Blueprint $table) {
            $table->unsignedBigInteger('consultation_id')->nullable()->after('triage_id');
            $table->index('consultation_id', 'idx_note_consultation');
        });

        // ═══ GARDE DU MOTEUR ═══
        //
        // `statut = 'cloturee'` ⟺ `cloturee_le IS NOT NULL`. Une consultation close sans heure de
        // clôture, ou une consultation en cours qui en porte une, sont des lignes qui ne veulent
        // rien dire — et c'est en base qu'on les relira dans dix ans.
        //
        // Un `CHECK` serait ici POSSIBLE (ni `statut` ni `cloturee_le` ne subissent d'action
        // référentielle, donc pas d'erreur 3823), mais SQLite refuse
        // `ALTER TABLE … ADD CONSTRAINT` : la garantie ne vaudrait qu'en production. Déclencheurs
        // dans les DEUX dialectes, divergence refusée depuis P6.8c (collation) et P6.8e (REGEXP).
        $this->poserGardes();
    }

    public function down(): void
    {
        $this->retirerGardes();

        Schema::table('notes_observations', function (Blueprint $table) {
            $table->dropIndex('idx_note_consultation');
            $table->dropColumn('consultation_id');
        });

        Schema::dropIfExists('consultations');
    }

    private function poserGardes(): void
    {
        $pilote = Schema::getConnection()->getDriverName();

        if ($pilote === 'mysql') {
            foreach (['INSERT', 'UPDATE'] as $moment) {
                $nom = 'trg_consultation_'.strtolower($moment);
                DB::unprepared("
                    CREATE TRIGGER {$nom} BEFORE {$moment} ON consultations
                    FOR EACH ROW
                    BEGIN
                        IF NEW.statut = 'cloturee' AND NEW.cloturee_le IS NULL THEN
                            SIGNAL SQLSTATE '45000'
                              SET MESSAGE_TEXT = 'Une consultation cloturee doit porter son heure de cloture.';
                        END IF;
                        IF NEW.statut = 'en_cours' AND NEW.cloturee_le IS NOT NULL THEN
                            SIGNAL SQLSTATE '45000'
                              SET MESSAGE_TEXT = 'Une consultation en cours ne peut pas porter une heure de cloture.';
                        END IF;
                    END
                ");
            }

            return;
        }

        foreach (['INSERT', 'UPDATE'] as $moment) {
            $nom = 'trg_consultation_'.strtolower($moment);
            DB::unprepared("
                CREATE TRIGGER {$nom} BEFORE {$moment} ON consultations
                FOR EACH ROW
                WHEN (NEW.statut = 'cloturee' AND NEW.cloturee_le IS NULL)
                   OR (NEW.statut = 'en_cours' AND NEW.cloturee_le IS NOT NULL)
                BEGIN
                    SELECT RAISE(ABORT, 'Une consultation cloturee doit porter son heure de cloture, une consultation en cours ne peut pas en porter.');
                END
            ");
        }
    }

    private function retirerGardes(): void
    {
        foreach (['insert', 'update'] as $moment) {
            DB::unprepared("DROP TRIGGER IF EXISTS trg_consultation_{$moment}");
        }
    }
};
