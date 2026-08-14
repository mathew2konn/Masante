<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P6.7b — Référentiel des laboratoires (CDC_09 §7.1/§7.2) et liens d'un résultat.
 *
 * ═══ ADDITIF, ET LA MOITIÉ DU §7.2 EXISTAIT DÉJÀ ═══
 *
 * P6.4a a doté `structures_sanitaires` de l'identité administrative : identifiant national, nom
 * officiel, statut juridique, adresse, GPS, contacts, `agrements_json`, `certifications_json`,
 * `horaires_json`. Le §7.2 est donc déjà couvert pour tout ce qui vaut de n'importe quel
 * établissement. On n'ajoute ici que ce qui est **propre au laboratoire**.
 *
 * `type_laboratoire` est un SECOND AXE, comme `statut_juridique` l'était vis-à-vis de `type` en
 * P6.4a : `type = 'laboratoire'` dit la catégorie d'établissement, `type_laboratoire` dit lequel
 * (hospitalier, privé, santé publique, universitaire, spécialisé — §7.1). Les fondre rendrait
 * insoluble « combien de laboratoires de santé publique dans ce district ? ».
 *
 * ═══ LES LIENS D'UN RÉSULTAT SONT DES DÉCLARATIONS SUR DES TIERS ═══
 *
 * `medecin_prescripteur_id` et `laboratoire_id` sont NULLABLE et le resteront : un patient qui
 * recopie un compte rendu papier n'a pas de liste sous les yeux. Ce qu'ils apportent n'est pas la
 * certitude, c'est la VÉRIFIABILITÉ — quand la déclaration est faite, elle est contrôlée.
 *
 * `nullOnDelete` sur les deux : une fiche de professionnel ou un laboratoire supprimé ne doit pas
 * emporter le résultat d'un patient. Le nom figé reste, et c'est lui qui garde le sens.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── §7.1 / §7.2 : ce qui est propre au laboratoire ───────────────────────────────────────
        Schema::table('structures_sanitaires', function (Blueprint $table) {
            $table->enum('type_laboratoire', [
                'hospitalier', 'prive', 'sante_publique', 'universitaire', 'specialise',
            ])->nullable()->after('niveau_soins');

            // Rôle LÉGAL distinct du directeur : le responsable scientifique engage la validité
            // biologique des résultats rendus, le directeur engage l'établissement.
            $table->string('responsable_scientifique', 200)->nullable()->after('directeur');
            $table->string('responsable_scientifique_titre', 120)->nullable()->after('responsable_scientifique');

            $table->text('equipements')->nullable()->after('description');

            // Délai moyen affiché au patient. Le délai par analyse vit au catalogue (P6.7a) : celui
            // d'ici est celui de CE laboratoire, et il peut en différer.
            $table->unsignedSmallInteger('delai_rendu_moyen_heures')->nullable()->after('equipements');

            // §7.2 « connexion au SI national » — une donnée d'état, pas une promesse : elle dit ce
            // qui est branché aujourd'hui, et rien de plus (l'API d'intégration est ADR-030,
            // classée « conçue », sans une ligne de code).
            $table->boolean('connecte_si_national')->default(false)->after('delai_rendu_moyen_heures');

            $table->index('type_laboratoire');
        });

        // ── §7.2 « analyses disponibles » : laboratoire ⇄ catalogue ──────────────────────────────
        //
        // TABLE OPÉRATIONNELLE, PAS GOUVERNÉE. Le critère de P6.4a est refait ici, pas recopié :
        // une accréditation est délivrée par une autorité (donc gouvernée, et elle vit déjà dans
        // `certifications_json`), tandis que la liste des analyses qu'un laboratoire réalise change
        // avec ses automates et son personnel. La soumettre au quatre-yeux national transformerait
        // l'arrivée d'un appareil en décision ministérielle.
        Schema::create('laboratoire_analyses', function (Blueprint $table) {
            $table->id();

            $table->foreignId('structure_id')->constrained('structures_sanitaires')->cascadeOnDelete();
            $table->foreignId('analyse_id')->constrained('analyses')->cascadeOnDelete();

            // Le délai de CE laboratoire pour CETTE analyse, quand il diffère du délai national.
            $table->unsignedSmallInteger('delai_rendu_heures')->nullable();
            $table->boolean('disponible')->default(true);
            $table->string('methode', 200)->nullable();

            $table->timestamps();

            // Un laboratoire ne déclare pas deux fois la même analyse.
            $table->unique(['structure_id', 'analyse_id'], 'uq_laboratoire_analyse');
        });

        // ── Les liens d'un résultat ──────────────────────────────────────────────────────────────
        Schema::table('resultats_analyses', function (Blueprint $table) {
            $table->foreignId('medecin_prescripteur_id')->nullable()
                ->after('medecin_prescripteur')->constrained('medecins')->nullOnDelete();

            $table->foreignId('laboratoire_id')->nullable()
                ->after('laboratoire')->constrained('structures_sanitaires')->nullOnDelete();

            // Les noms FIGÉS. Ils survivent à la suppression de la fiche, et surtout à son
            // renommage : un compte rendu doit continuer de dire ce qu'il disait le jour où il a
            // été rendu (précédent P7-D2, où l'établissement est recopié à l'écriture).
            $table->string('medecin_prescripteur_nom', 200)->nullable()->after('medecin_prescripteur_id');
            $table->string('laboratoire_nom', 200)->nullable()->after('laboratoire_id');
            $table->string('laboratoire_code', 20)->nullable()->after('laboratoire_nom');
        });
    }

    public function down(): void
    {
        Schema::table('resultats_analyses', function (Blueprint $table) {
            $table->dropConstrainedForeignId('medecin_prescripteur_id');
            $table->dropConstrainedForeignId('laboratoire_id');
            $table->dropColumn(['medecin_prescripteur_nom', 'laboratoire_nom', 'laboratoire_code']);
        });

        Schema::dropIfExists('laboratoire_analyses');

        Schema::table('structures_sanitaires', function (Blueprint $table) {
            $table->dropIndex(['type_laboratoire']);
            $table->dropColumn([
                'type_laboratoire', 'responsable_scientifique', 'responsable_scientifique_titre',
                'equipements', 'delai_rendu_moyen_heures', 'connecte_si_national',
            ]);
        });
    }
};
