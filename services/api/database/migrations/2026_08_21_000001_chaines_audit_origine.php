<?php

use App\Services\Audit\ChaineAudit;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Chaînes d'audit : origine déclarée, et identifiants qui cessent d'être des relations vivantes.
 *
 * ═══ DEUX DÉFAUTS MESURÉS, PAS SUPPOSÉS ═══
 *
 * 1. `acteur_id` (et `medecin_id` pour la PKI) sont des clés étrangères `nullOnDelete` **et**
 *    entrent dans la charge hachée. Supprimer un compte — un acte ordinaire, et un droit
 *    (loi 2013-450) — modifie donc la charge et fait crier « entrée modifiée » sur un journal que
 *    personne n'a touché. C'est arrivé : 16 des 34 entrées de `protocole_journal` portent
 *    `acteur_id = NULL` avec leur `acteur_nom` intact, et la chaîne est rompue depuis.
 *
 * 2. La vérification partait de `$attendue = null` : elle acceptait n'importe quelle première
 *    entrée. `referentiel_journal` (3 entrées, ids 98→100, compteur à 101) et `signature_journal`
 *    (vide, compteur à 6) répondaient donc **`intacte: true`** alors que leur histoire avait
 *    disparu.
 *
 * ═══ CE QUE CETTE MIGRATION NE FAIT PAS ═══
 *
 * Elle ne répare rien. Aucune empreinte n'est recalculée, aucune entrée n'est corrigée, complétée
 * ou supprimée. **Recalculer serait réécrire l'histoire — précisément ce qu'une chaîne existe pour
 * rendre impossible.** Les ruptures constatées restent constatées ; elles deviennent seulement
 * nommables et scellables.
 *
 * ═══ POURQUOI ELLE ÉCRIT DES DONNÉES, ET SEULEMENT DANS UN CAS ═══
 *
 * Un journal **vide** au moment de la migration commence honnêtement ici : sa chaîne 1 est
 * déclarée. Un journal qui porte déjà des entrées ne l'est pas — nous ne pouvons pas affirmer que
 * rien ne lui a été retiré en tête, et prétendre le contraire serait le silence même qu'on corrige.
 * Ceux-là restent « origine non déclarée » jusqu'à un scellement explicite.
 */
return new class extends Migration
{
    /** Colonnes à libérer de leur clé étrangère : elles sont des identifiants, pas des relations. */
    private const CLES_A_RETIRER = [
        'referentiel_journal' => ['acteur_id'],
        'protocole_journal' => ['acteur_id'],
        'signature_journal' => ['acteur_id', 'medecin_id'],
    ];

    public function up(): void
    {
        Schema::create('audit_chaines', function (Blueprint $table) {
            $table->id();

            // Pas de clé étrangère, et pas d'ENUM : le nom du journal est gouverné par la liste
            // blanche fermée `ChaineAudit::JOURNAUX`, en PHP, où il est relisible.
            $table->string('journal', 40);
            $table->unsignedInteger('numero');

            $table->string('motif', 300);
            $table->string('acteur_nom', 150);

            // L'empreinte de la PREMIÈRE entrée de cette chaîne, ancrée une seule fois. Sans elle,
            // une chaîne déclarée puis vidée et réalimentée se revérifierait « intacte » — le
            // scénario exact qui s'est produit sur `referentiel_journal`.
            $table->char('empreinte_premiere', 64)->nullable();

            // L'état de la chaîne scellée, figé au moment du scellement.
            $table->unsignedInteger('entrees_scellees')->default(0);
            $table->char('empreinte_scellee', 64)->nullable();
            $table->json('verdict_scelle_json')->nullable();

            $table->timestamp('cree_le');

            $table->unique(['journal', 'numero'], 'uq_audit_chaine');
        });

        foreach (array_keys(ChaineAudit::JOURNAUX) as $journal) {
            Schema::table($journal, function (Blueprint $table) use ($journal) {
                $table->unsignedInteger('chaine')->default(1)->after('id');
                $table->index(['chaine', 'id'], 'idx_'.$this->court($journal).'_chaine');
            });
        }

        foreach (self::CLES_A_RETIRER as $table => $colonnes) {
            $this->retirerClesEtrangeres($table, $colonnes);
        }

        $this->declarerLesJournauxVides();
    }

    public function down(): void
    {
        foreach (array_keys(ChaineAudit::JOURNAUX) as $journal) {
            Schema::table($journal, function (Blueprint $table) use ($journal) {
                $table->dropIndex('idx_'.$this->court($journal).'_chaine');
                $table->dropColumn('chaine');
            });
        }

        Schema::dropIfExists('audit_chaines');

        // Les clés étrangères ne sont volontairement pas rétablies : les remettre réintroduirait le
        // défaut, et `down()` sert à revenir en arrière, pas à réinstaller un piège.
    }

    /**
     * Un journal vide au moment de l'installation peut déclarer son origine sans mentir.
     */
    private function declarerLesJournauxVides(): void
    {
        $maintenant = now();

        foreach (array_keys(ChaineAudit::JOURNAUX) as $journal) {
            if (DB::table($journal)->exists()) {
                continue;
            }

            DB::table('audit_chaines')->insert([
                'journal' => $journal,
                'numero' => 1,
                'empreinte_premiere' => null,
                'motif' => ChaineAudit::MOTIF_INSTALLATION,
                'acteur_nom' => 'Système',
                'entrees_scellees' => 0,
                'empreinte_scellee' => null,
                'verdict_scelle_json' => null,
                'cree_le' => $maintenant,
            ]);
        }
    }

    /**
     * ═══ POURQUOI SQLITE EXIGE UNE RECONSTRUCTION, ET POURQUOI ON LA FAIT ═══
     *
     * MySQL sait retirer une contrainte ; SQLite ne le sait pas, et Laravel lève sur `dropForeign`.
     * La tentation serait de ne le faire qu'en MySQL — mais les tests tournent sur SQLite avec
     * `foreign_key_constraints` à `true` : la garantie « supprimer un compte ne casse pas la
     * chaîne » serait alors **vraie en production et fausse en test**, exactement la divergence
     * relevée en P6.8c (collation) et refusée en P6.8e (REGEXP).
     *
     * On reconstruit donc la table : capture du schéma, renommage, recréation sans la clause,
     * copie, suppression, recréation des index. Les journaux ne sont référencés par aucune autre
     * table, la manœuvre ne peut donc violer aucune contrainte entrante.
     */
    private function retirerClesEtrangeres(string $table, array $colonnes): void
    {
        if (DB::getDriverName() === 'mysql') {
            Schema::table($table, function (Blueprint $blueprint) use ($colonnes) {
                foreach ($colonnes as $colonne) {
                    $blueprint->dropForeign([$colonne]);
                }
            });

            return;
        }

        if (DB::getDriverName() !== 'sqlite') {
            return;
        }

        $creation = DB::selectOne(
            "SELECT sql FROM sqlite_master WHERE type = 'table' AND name = ?",
            [$table]
        )?->sql;

        if ($creation === null) {
            return;
        }

        $nouvelle = $creation;

        foreach ($colonnes as $colonne) {
            $nouvelle = preg_replace(
                '/,\s*foreign key\s*\(\s*"?'.preg_quote($colonne, '/').'"?\s*\)'
                .'\s*references\s*"?\w+"?\s*\(\s*"?\w+"?\s*\)'
                .'(\s+on\s+delete\s+[a-z ]+?)?(\s+on\s+update\s+[a-z ]+?)?(?=\s*[,)])/i',
                '',
                $nouvelle
            );
        }

        if ($nouvelle === $creation) {
            // Rien n'a été retiré : mieux vaut échouer bruyamment que laisser croire que la
            // contrainte a sauté alors qu'elle est toujours là.
            throw new RuntimeException(
                "Reconstruction SQLite de « {$table} » : aucune clause de clé étrangère retirée."
            );
        }

        $index = DB::select(
            "SELECT sql FROM sqlite_master WHERE type = 'index' AND tbl_name = ? AND sql IS NOT NULL",
            [$table]
        );

        $nomsIndex = DB::select(
            "SELECT name FROM sqlite_master WHERE type = 'index' AND tbl_name = ? AND sql IS NOT NULL",
            [$table]
        );

        DB::statement("ALTER TABLE \"{$table}\" RENAME TO \"{$table}_avant_fk\"");

        foreach ($nomsIndex as $ligne) {
            DB::statement("DROP INDEX IF EXISTS \"{$ligne->name}\"");
        }

        DB::statement($nouvelle);
        DB::statement("INSERT INTO \"{$table}\" SELECT * FROM \"{$table}_avant_fk\"");
        DB::statement("DROP TABLE \"{$table}_avant_fk\"");

        foreach ($index as $ligne) {
            DB::statement($ligne->sql);
        }
    }

    /** Les noms d'index MySQL sont bornés à 64 caractères. */
    private function court(string $journal): string
    {
        return str_replace(['referentiel', 'protocole', 'signature', '_journal'], ['ref', 'proto', 'sig', ''], $journal);
    }
};
