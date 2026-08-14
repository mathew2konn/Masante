<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * L2 (ADR-025 §5) — l'estampille de version sur une décision clinique.
 *
 * CDC_09 §10 : « toute décision conserve la version du référentiel utilisée ». Une mesure qualifiée
 * « critique » est une décision : elle a été jugée par des seuils, et ces seuils changent. Sans
 * cette colonne, corriger un seuil rend inexplicable tout jugement antérieur — c'est le défaut que
 * le G0 de P6.3 avait nommé, et que la seule bascule de lecture (L1) ne referme pas.
 *
 * NULLABLE, ET JAMAIS REMPLIE RÉTROACTIVEMENT. Les mesures antérieures à la bascule n'ont eu
 * AUCUNE version : leur en attribuer une aujourd'hui serait un mensonge d'archive. Elles diront
 * « version inconnue », ce qui est la vérité. Même refus qu'en P6.3, où l'estampille avait été
 * livrée sans être apposée pour cette raison exacte.
 *
 * Pas de clé étrangère vers `referentiel_versions` : une version est un numéro dans le registre du
 * pays servi par cette instance, pas une ligne à joindre — et une contrainte référentielle
 * empêcherait d'archiver un référentiel dont des mesures citent une version.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mesures_sante', function (Blueprint $table) {
            $table->unsignedInteger('referentiel_version')
                ->nullable()
                ->after('statut_norme');
        });
    }

    public function down(): void
    {
        Schema::table('mesures_sante', function (Blueprint $table) {
            $table->dropColumn('referentiel_version');
        });
    }
};
