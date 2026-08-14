<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * P6.6a — Référentiel National des Médicaments (CDC_09 §6).
 *
 * ═══ ADDITIF, JAMAIS DE REMPLACEMENT (ADR-024) ═══
 *
 * `medicaments` est lue par la recherche publique, le comparateur de prix citoyen et le stock des
 * pharmacies — trois usages du Module 5, validés. Aucune colonne existante n'est retirée ni
 * renommée : `nom_generique` reste la DCI, `categorie` reste la classe thérapeutique,
 * `prix_reference_cfa` reste le prix homologué. On complète, on ne réécrit pas.
 *
 * ═══ TOUT EST NULLABLE, ET C'EST UN CHOIX ═══
 *
 * Les 20 lignes seedées n'ont ni forme, ni dosage, ni voie. Leur inventer une valeur par défaut
 * produirait une donnée FAUSSE là où il n'y a qu'une donnée MANQUANTE — et un référentiel national
 * qui affirme « comprimé » sur un sirop est pire qu'un référentiel qui dit ne pas savoir. Même
 * raisonnement qu'en P6.4a et P6.5a. Le contrôle qualité du référentiel signale ces manques ; c'est
 * son rôle, pas celui d'une valeur par défaut.
 *
 * ═══ LES ÉNUMÉRATIONS SONT RECOPIÉES ICI, DÉLIBÉRÉMENT ═══
 *
 * Une migration est un ACTE D'ARCHIVE : elle doit décrire le schéma tel qu'il a été créé ce jour-là,
 * et rester lisible quand `App\Support\Medicaments` aura évolué. La source unique reste cette
 * classe ; un test de parité casse le build si les deux listes divergent. C'est la convention posée
 * en P6.5a pour l'ENUM `profession`.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── Compteur du code national, un par pays ───────────────────────────────────────────────
        Schema::create('medicament_compteurs', function (Blueprint $table) {
            $table->string('pays_code', 2)->primary();
            $table->unsignedInteger('dernier')->default(0);
            $table->timestamps();
        });

        // ── Les données du §6.2 qui manquaient ───────────────────────────────────────────────────
        Schema::table('medicaments', function (Blueprint $table) {
            // Identité nationale. Hors `$fillable` : un client ne choisit pas son numéro national.
            $table->string('code', 12)->nullable()->after('id');
            $table->string('pays_code', 2)->default('CI')->after('code');

            $table->string('laboratoire', 200)->nullable()->after('nom_commercial');

            $table->enum('forme', [
                'comprime', 'gelule', 'sirop', 'suspension', 'solution_inj', 'poudre_inj',
                'suppositoire', 'creme', 'pommade', 'gel', 'collyre', 'goutte', 'patch',
                'aerosol', 'ovule', 'sachet',
            ])->nullable()->after('laboratoire');

            // Texte libre : « 500 mg », « 1 g / 5 mL », « 40 UI/mL ». Aucune énumération ne couvre
            // les dosages réels, et en inventer une obligerait à migrer à chaque nouveau produit.
            $table->string('dosage', 100)->nullable()->after('forme');

            $table->enum('voie_administration', [
                'orale', 'injectable', 'cutanee', 'oculaire', 'auriculaire', 'nasale',
                'inhalee', 'rectale', 'vaginale', 'sublinguale',
            ])->nullable()->after('dosage');

            $table->text('indications')->nullable()->after('categorie');
            $table->text('contre_indications')->nullable()->after('indications');
            $table->text('effets_secondaires')->nullable()->after('contre_indications');

            $table->enum('statut_marche', ['autorise', 'suspendu', 'retire'])
                ->default('autorise')->after('effets_secondaires');

            // À NE PAS CONFONDRE avec `disponible_generique`, conservée : celle-ci dit « un générique
            // de ce produit existe », celle-là dit « ce produit EST un générique ».
            $table->enum('statut_generique', ['princeps', 'generique', 'biosimilaire'])
                ->nullable()->after('disponible_generique');

            // Le pays QUALIFIE le code, il ne s'écrit pas dedans : CI et SN peuvent tous deux avoir
            // un MED000458 (précédents `ETS` en P6.4a et `PRO` en P6.5a).
            $table->unique(['pays_code', 'code'], 'uq_medicament_code_pays');
            $table->index('statut_marche');
        });

        // ── Interactions : une RELATION entre deux médicaments, pas une propriété de l'un d'eux ──
        //
        // Une colonne JSON par médicament aurait dit deux fois la même chose (X→Y et Y→X) et les
        // deux copies auraient pu diverger — deux vérités. Le couple est ORDONNÉ à l'écriture
        // (`medicament_a_id < medicament_b_id`, garanti par le service) : c'est ce qui rend
        // l'unicité déclarative possible et empêche de déclarer deux fois la même interaction en
        // l'écrivant dans l'autre sens.
        Schema::create('interactions_medicamenteuses', function (Blueprint $table) {
            $table->id();

            $table->foreignId('medicament_a_id')->constrained('medicaments')->cascadeOnDelete();
            $table->foreignId('medicament_b_id')->constrained('medicaments')->cascadeOnDelete();

            $table->enum('niveau', ['precaution', 'association_deconseillee', 'contre_indication']);

            // Ce que le référentiel AFFIRME, dans les mots de l'autorité qui le déclare.
            $table->text('description');
            $table->text('conduite_a_tenir')->nullable();

            // D'où vient l'affirmation. Une interaction sans source est une rumeur : le contrôle
            // qualité la signale.
            $table->string('source', 200)->nullable();

            $table->timestamps();

            $table->unique(['medicament_a_id', 'medicament_b_id'], 'uq_interaction_couple');
            $table->index('niveau');
        });

        $this->poserLaGardeDOrdre();
    }

    /**
     * L'ORDRE DU COUPLE DEVIENT UNE GARANTIE DU MOTEUR, PAS SEULEMENT DU SERVICE.
     *
     * Le G2 live a montré le trou : `uq_interaction_couple` ne protège que le couple **déjà
     * ordonné**. Une insertion SQL directe écrivant (B, A) alors que (A, B) existe est acceptée —
     * le référentiel porterait alors deux affirmations sur le même fait clinique, possiblement de
     * niveaux différents. `ServiceInteractions` ordonne, mais une garantie qui repose sur le seul
     * chemin applicatif n'en est pas une : il suffit d'un import, d'un seeder ou d'une correction
     * à la main pour la contourner.
     *
     * UN `CHECK` ÉTAIT LE PREMIER CHOIX, ET MySQL LE REFUSE. Les deux colonnes sont
     * `cascadeOnDelete`, donc soumises à une action référentielle : erreur **3823**, exactement le
     * mur rencontré en P6.3 (cousin du 1215 de P6.1). D'où des triggers dans les deux dialectes,
     * le recours que CDC_04 §139 prévoit pour un « contrôle d'intégrité métier ne pouvant être
     * garanti autrement ».
     *
     * `COALESCE(cond, 0) = 0` et non `NOT(cond)` : une comparaison impliquant NULL ne déclencherait
     * rien, et la violation passerait **sans bruit** (leçon de P6.3).
     */
    private function poserLaGardeDOrdre(): void
    {
        $mysql = DB::getDriverName() === 'mysql';
        $condition = 'NEW.medicament_a_id < NEW.medicament_b_id';
        $nom = 'ck_interaction_couple_ordonne';

        foreach (['INSERT', 'UPDATE'] as $evenement) {
            $trigger = $nom.'_'.strtolower($evenement);

            DB::unprepared($mysql
                ? "CREATE TRIGGER {$trigger}
                   BEFORE {$evenement} ON interactions_medicamenteuses
                   FOR EACH ROW
                   BEGIN
                       IF COALESCE(({$condition}), 0) = 0 THEN
                           SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$nom}';
                       END IF;
                   END"
                : "CREATE TRIGGER {$trigger}
                   BEFORE {$evenement} ON interactions_medicamenteuses
                   WHEN COALESCE(({$condition}), 0) = 0
                   BEGIN SELECT RAISE(ABORT, '{$nom}'); END"
            );
        }
    }

    public function down(): void
    {
        foreach (['insert', 'update'] as $evenement) {
            DB::unprepared("DROP TRIGGER IF EXISTS ck_interaction_couple_ordonne_{$evenement}");
        }

        Schema::dropIfExists('interactions_medicamenteuses');

        Schema::table('medicaments', function (Blueprint $table) {
            $table->dropUnique('uq_medicament_code_pays');
            $table->dropIndex(['statut_marche']);
            $table->dropColumn([
                'code', 'pays_code', 'laboratoire', 'forme', 'dosage', 'voie_administration',
                'indications', 'contre_indications', 'effets_secondaires',
                'statut_marche', 'statut_generique',
            ]);
        });

        Schema::dropIfExists('medicament_compteurs');
    }
};
