<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * P6.1 — Identifiant National de Santé (CDC_09 §3, ADR-001/ADR-021).
 *
 * Migration STRICTEMENT ADDITIVE : aucune colonne existante n'est modifiée ni supprimée.
 * P2 (carnet) et P4 (rendez-vous) sont validés G5 — « corrections chirurgicales uniquement ».
 *
 * `nis` est NULLABLE à dessein : les dossiers existants n'en ont pas encore (attribution par
 * la commande `masante:nis:backfill`). Une colonne NOT NULL casserait la migration en base
 * peuplée. L'unicité, elle, est garantie dès maintenant.
 *
 * `nis_journal` est le garant de la NON-RÉUTILISABILITÉ (CDC_09 §3.2) : `membre_id` y est
 * `nullOnDelete` et non `cascade`. Supprimer un dossier libère la ligne `membres_famille`
 * mais laisse le NIS inscrit au journal — il ne pourra donc jamais être réattribué.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── 1. Le NIS sur le dossier patient (= membres_famille, cf. G0 découverte D-1).
        Schema::table('membres_famille', function (Blueprint $table) {
            $table->string('nis', 15)->nullable()->unique()->after('matricule_ivs');
            $table->timestamp('nis_attribue_le')->nullable()->after('nis');

            // Multi-pays (CDC_09 §1.2 principe 5) : ajouter un pays = ajouter des données.
            $table->char('pays_code', 2)->default('CI')->after('nis_attribue_le');

            // Dossier du titulaire du compte (ADR-021, variante (c) : création différée à la
            // complétion du profil — l'inscription n'est pas touchée).
            $table->boolean('est_titulaire')->default(false)->after('pays_code');

            $table->index(['pays_code', 'nis'], 'idx_membres_pays_nis');
        });

        // Un seul dossier titulaire par compte, garanti DÉCLARATIVEMENT et non par l'applicatif.
        // Les deux moteurs expriment le même invariant, par des chemins différents.
        if ($this->surMysql()) {
            // MySQL n'a pas d'index unique partiel. La colonne GÉNÉRÉE serait la solution
            // naturelle, mais MySQL REFUSE une colonne générée STORED dérivée d'une colonne
            // portant une action référentielle : or `user_id` porte ON DELETE CASCADE
            // (erreur 1215, constatée en G2 live sur MySQL 8.4.7).
            //
            // On maintient donc la colonne applicativement (hook `saving` du modèle) tout en
            // conservant DEUX garanties en base :
            //   - UNIQUE → au plus un titulaire par compte ;
            //   - CHECK  → la colonne ne peut pas mentir sur `est_titulaire` / `user_id`.
            // L'invariant reste donc vérifié par le moteur, pas par la confiance.
            DB::statement('
                ALTER TABLE membres_famille
                ADD COLUMN titulaire_du_compte BIGINT UNSIGNED NULL,
                ADD CONSTRAINT uq_membres_un_seul_titulaire UNIQUE (titulaire_du_compte),
                ADD CONSTRAINT ck_membres_titulaire_coherent CHECK (
                    (est_titulaire = 0 AND titulaire_du_compte IS NULL)
                    OR (est_titulaire = 1 AND titulaire_du_compte = user_id)
                )
            ');
        } else {
            // SQLite (tests) supporte l'index unique partiel : forme la plus directe de
            // l'invariant, aucune colonne supplémentaire nécessaire.
            DB::statement('
                CREATE UNIQUE INDEX uq_membres_un_seul_titulaire
                ON membres_famille (user_id) WHERE est_titulaire = 1
            ');
        }

        // ── 2. Séquence nationale. Le compteur est lu et incrémenté sous verrou
        //       (SELECT … FOR UPDATE) : deux attributions simultanées ne peuvent pas collisionner.
        Schema::create('nis_compteurs', function (Blueprint $table) {
            $table->char('pays_code', 2);
            $table->unsignedSmallInteger('annee');          // année sur 2 chiffres (24, 25, 26…)
            $table->unsignedBigInteger('dernier')->default(0);
            $table->timestamps();

            $table->primary(['pays_code', 'annee']);
        });

        // ── 3. Journal d'attribution — append-only, prouve la non-réutilisation.
        Schema::create('nis_journal', function (Blueprint $table) {
            $table->id();
            $table->string('nis', 15)->unique();

            // nullOnDelete : le dossier peut disparaître, le NIS reste réservé À VIE.
            $table->foreignId('membre_id')->nullable()
                ->constrained('membres_famille')->nullOnDelete();

            $table->char('pays_code', 2);
            $table->timestamp('attribue_le');
            $table->string('motif', 40);                    // CREATION_DOSSIER | BACKFILL
            $table->foreignId('acteur_id')->nullable()
                ->constrained('users')->nullOnDelete();

            $table->index(['pays_code', 'attribue_le']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nis_journal');
        Schema::dropIfExists('nis_compteurs');

        if ($this->surMysql()) {
            DB::statement('ALTER TABLE membres_famille DROP CHECK ck_membres_titulaire_coherent');
            DB::statement('ALTER TABLE membres_famille DROP INDEX uq_membres_un_seul_titulaire');
            DB::statement('ALTER TABLE membres_famille DROP COLUMN titulaire_du_compte');
        } else {
            DB::statement('DROP INDEX IF EXISTS uq_membres_un_seul_titulaire');
        }

        Schema::table('membres_famille', function (Blueprint $table) {
            $table->dropIndex('idx_membres_pays_nis');
            $table->dropUnique(['nis']);
            $table->dropColumn(['nis', 'nis_attribue_le', 'pays_code', 'est_titulaire']);
        });
    }

    /** Le DDL de l'unicité du titulaire diffère entre MySQL (prod) et SQLite (tests). */
    private function surMysql(): bool
    {
        return DB::connection()->getDriverName() === 'mysql';
    }
};
