<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * P6.4a — Référentiel national des établissements de santé (CDC_09 §4 ; ADR-024, ADR-026).
 *
 * STRICTEMENT ADDITIVE, et c'est une exigence et non un confort : `structures_sanitaires` est la
 * table la plus référencée du projet après `membres_famille` — 40 fichiers, dont P3 (annuaire,
 * carte) et P4 (rendez-vous), tous deux validés G5. Aucune colonne existante n'est modifiée ni
 * supprimée ; la seule retouche est l'ÉLARGISSEMENT de l'énumération `type`, qui n'invalide
 * aucune valeur en place.
 *
 * CE QUE LA MIGRATION APPORTE :
 *  1. `regions` et `districts_sanitaires` — le découpage sanitaire devient une DONNÉE DE
 *     RÉFÉRENCE et non un texte libre (§1.2.4 : « aucune donnée de référence saisie librement
 *     dans un module métier »).
 *  2. `etablissement_compteurs` — la séquence de l'identifiant national, par pays.
 *  3. L'identité administrative, les coordonnées complètes et les informations légales sur
 *     `structures_sanitaires` (CDC_09 §4.2 + CDC_11 §3.1).
 *
 * DEUX AXES QUE LE CDC SÉPARE ET QUE LA TABLE CONFONDAIT (décision G1 D3) : `type` est de facto
 * la CATÉGORIE (Hôpital, Clinique, Laboratoire… — c'est ce que filtrent P3 et P4), on l'assume et
 * on l'étend au périmètre §4.1 ; le STATUT JURIDIQUE (public/privé/universitaire/militaire) prend
 * sa propre colonne. `clinique_privee` est conservée telle quelle : elle mélange les deux axes,
 * mais la renommer casserait des données et des filtres prouvés pour le même contenu.
 *
 * TOUT EST NULLABLE, ET C'EST VOULU : la base porte 12 structures qui n'ont aucune de ces
 * informations. Une colonne `NOT NULL` casserait la migration, et une valeur par défaut
 * inventée serait pire — « statut juridique : public » sur une clinique privée est une donnée
 * fausse, pas une donnée manquante. L'absence se dit, elle ne se comble pas.
 */
return new class extends Migration
{
    /**
     * L'énumération `type` élargie au périmètre du §4.1.
     *
     * Les sept premières valeurs sont l'existant, dans l'ordre : aucune ne change de sens, aucune
     * donnée en place ne devient invalide. Les cinq suivantes comblent ce qui manquait —
     * §4.1 nomme explicitement les centres d'imagerie, de dialyse et de vaccination, ainsi que la
     * distinction urbain/rural des centres de santé.
     */
    private const TYPES = [
        'chu', 'chr', 'clinique_privee', 'cabinet', 'pharmacie', 'laboratoire', 'centre_sante',
        'hopital_general', 'centre_sante_urbain', 'centre_sante_rural',
        'centre_imagerie', 'centre_dialyse', 'centre_vaccination',
    ];

    private const TYPES_HISTORIQUES = [
        'chu', 'chr', 'clinique_privee', 'cabinet', 'pharmacie', 'laboratoire', 'centre_sante',
    ];

    public function up(): void
    {
        // ─────────────────────────────────────────────────────────────────────────────
        // 1. Découpage sanitaire — régions et districts (CDC_09 §4.2, §8).
        // ─────────────────────────────────────────────────────────────────────────────
        Schema::create('regions', function (Blueprint $table) {
            $table->id();
            $table->char('pays_code', 2)->default('CI');
            $table->string('code', 20);
            $table->string('nom', 120);
            $table->timestamps();

            $table->unique(['pays_code', 'code'], 'uq_region_pays_code');
        });

        // Le district sanitaire est l'échelon que §4.2 exige nommément. Il appartient à une
        // région : la hiérarchie est portée par la FK, pas par une convention de nommage.
        Schema::create('districts_sanitaires', function (Blueprint $table) {
            $table->id();
            $table->foreignId('region_id')->constrained('regions')->cascadeOnDelete();
            $table->char('pays_code', 2)->default('CI');
            $table->string('code', 20);
            $table->string('nom', 120);
            $table->timestamps();

            $table->unique(['pays_code', 'code'], 'uq_district_pays_code');
            $table->index('region_id', 'idx_district_region');
        });

        // ─────────────────────────────────────────────────────────────────────────────
        // 2. Séquence de l'identifiant national (§4.3 : ETS000152).
        // ─────────────────────────────────────────────────────────────────────────────
        //
        // Une ligne par pays. Pas d'année dans la clé, contrairement au NIS : un établissement
        // n'est pas daté par son identifiant, et le §4.3 n'en montre aucune trace.
        Schema::create('etablissement_compteurs', function (Blueprint $table) {
            $table->char('pays_code', 2)->primary();
            $table->unsignedBigInteger('dernier')->default(0);
            $table->timestamps();
        });

        // ─────────────────────────────────────────────────────────────────────────────
        // 3. L'identité administrative sur `structures_sanitaires`.
        // ─────────────────────────────────────────────────────────────────────────────
        Schema::table('structures_sanitaires', function (Blueprint $table) {
            // Identifiant national — `ETS` + 6 chiffres, littéral (décision G1 D2). NULLABLE :
            // les 12 structures existantes n'en ont pas encore ; la commande de backfill les
            // sert. L'unicité, elle, est garantie dès maintenant.
            $table->string('identifiant_national', 12)->nullable()->after('id');

            // Multi-pays (§1.2.5). Le pays QUALIFIE l'identifiant, il ne s'écrit pas dedans :
            // c'est ce qui permet de respecter l'exemple impose `ETS000152` sans renoncer à
            // discriminer deux pays. D'où l'unicité sur le COUPLE, plus bas.
            $table->char('pays_code', 2)->default('CI')->after('identifiant_national');

            // Le second axe du §4.1 / CDC_11 §3.1, que `type` ne portait pas.
            $table->enum('statut_juridique', ['public', 'prive', 'universitaire', 'militaire'])
                ->nullable()->after('type');

            // Pyramide sanitaire. Des NOMS et non des numéros : « niveau 2 » ne veut rien dire
            // hors contexte, et inventer une échelle chiffrée que le CDC ne donne pas serait
            // ajouter une convention de plus à celles qu'on doit déjà réconcilier.
            $table->enum('niveau_soins', ['primaire', 'secondaire', 'tertiaire'])
                ->nullable()->after('statut_juridique');

            $table->string('nom_officiel', 200)->nullable()->after('nom');

            // Découpage sanitaire par référence, jamais par texte libre (§1.2.4).
            $table->foreignId('region_id')->nullable()->after('commune')
                ->constrained('regions')->nullOnDelete();
            $table->foreignId('district_id')->nullable()->after('region_id')
                ->constrained('districts_sanitaires')->nullOnDelete();
            $table->string('quartier', 100)->nullable()->after('district_id');

            $table->string('email', 190)->nullable()->after('whatsapp');
            $table->string('site_web', 190)->nullable()->after('email');
            $table->string('directeur', 150)->nullable()->after('site_web');

            $table->unsignedInteger('capacite_accueil')->nullable()->after('directeur');
            $table->unsignedInteger('nombre_lits')->nullable()->after('capacite_accueil');

            // Informations légales (CDC_11 §3.1 « formulaire dédié »). Regroupées à dessein :
            // elles forment un bloc administratif que la liste publique n'a pas à transporter.
            $table->string('numero_autorisation', 60)->nullable();
            $table->string('numero_fiscal', 60)->nullable();
            $table->string('registre_commerce', 60)->nullable();
            $table->date('date_creation')->nullable();
            $table->string('licence_exploitation', 60)->nullable();
            $table->string('autorite_tutelle', 150)->nullable();

            // Agréments et certifications : listes datées, donc JSON plutôt que colonnes plates.
            $table->json('agrements_json')->nullable();
            $table->json('certifications_json')->nullable();

            $table->text('description')->nullable();

            // L'unicité porte sur le COUPLE : deux pays peuvent tous deux avoir un ETS000152,
            // et c'est cohérent — l'identifiant est national, pas mondial.
            $table->unique(['pays_code', 'identifiant_national'], 'uq_etablissement_identifiant');
            $table->index(['pays_code', 'district_id'], 'idx_etablissement_district');
        });

        $this->redefinirTypes(self::TYPES);
    }

    public function down(): void
    {
        // Ramener les types neufs vers la valeur historique la plus proche AVANT de rétrécir
        // l'énumération : une ligne portant une valeur qui n'existe plus la violerait.
        DB::table('structures_sanitaires')
            ->whereIn('type', ['hopital_general', 'centre_sante_urbain', 'centre_sante_rural'])
            ->update(['type' => 'centre_sante']);
        DB::table('structures_sanitaires')
            ->whereIn('type', ['centre_imagerie', 'centre_dialyse', 'centre_vaccination'])
            ->update(['type' => 'centre_sante']);

        $this->redefinirTypes(self::TYPES_HISTORIQUES);

        Schema::table('structures_sanitaires', function (Blueprint $table) {
            $table->dropUnique('uq_etablissement_identifiant');
            $table->dropIndex('idx_etablissement_district');
            $table->dropConstrainedForeignId('region_id');
            $table->dropConstrainedForeignId('district_id');
            $table->dropColumn([
                'identifiant_national', 'pays_code', 'statut_juridique', 'niveau_soins',
                'nom_officiel', 'quartier', 'email', 'site_web', 'directeur',
                'capacite_accueil', 'nombre_lits', 'numero_autorisation', 'numero_fiscal',
                'registre_commerce', 'date_creation', 'licence_exploitation', 'autorite_tutelle',
                'agrements_json', 'certifications_json', 'description',
            ]);
        });

        Schema::dropIfExists('etablissement_compteurs');
        Schema::dropIfExists('districts_sanitaires');
        Schema::dropIfExists('regions');
    }

    /**
     * Redéfinit l'énumération `type`. Motif éprouvé en P7-A sur `delegations.droits` : MySQL
     * accepte un `MODIFY` direct, SQLite fige la liste dans un `CHECK` du `CREATE TABLE` et
     * exige que Laravel reconstruise la table.
     *
     * @param  array<int, string>  $valeurs
     */
    private function redefinirTypes(array $valeurs): void
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement(sprintf(
                'ALTER TABLE structures_sanitaires MODIFY type ENUM(%s) NOT NULL',
                implode(',', array_map(static fn (string $v): string => "'".$v."'", $valeurs))
            ));

            return;
        }

        Schema::table('structures_sanitaires', function (Blueprint $table) use ($valeurs) {
            $table->enum('type', $valeurs)->change();
        });
    }
};
