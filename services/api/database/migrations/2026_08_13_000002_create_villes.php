<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P6.4b — Villes couvertes et géolocalisation (CDC_09 §4.2 « ville » ; CDC_11 §3.1).
 *
 * Comble le champ `ville` que le G0 de P6.4a avait signalé comme absent (ADR-026, limite M6) —
 * masqué jusqu'ici parce que le catalogue ne couvrait qu'Abidjan, où la commune tient lieu de ville.
 *
 * TROIS COLONNES PORTENT DES DÉCISIONS, PAS DE LA DESCRIPTION :
 *
 *  · `latitude`/`longitude`/`rayon_km` — la ville où se trouve l'utilisateur est déterminée par le
 *    BACKEND à partir de sa position (règle de frontière : un rattachement géographique est un
 *    calcul, pas un affichage). Le centre et le rayon sont des DONNÉES : ajouter Korhogo demain
 *    est une ligne à insérer, aucune ligne de code à écrire (§1.2.5).
 *
 *  · `affiche_communes` — « à Abidjan on montre les communes, ailleurs non ». Écrit en dur, ce
 *    serait un `if ville === 'Abidjan'` dans le front, c'est-à-dire exactement la règle métier
 *    codée en dur que CDC_04 §20 interdit. En donnée, une ville qui se subdivise demain n'exige
 *    aucun déploiement.
 *
 *  · `ordre` — l'ordre d'affichage du sélecteur de repli, décidé par le métier et non par l'ordre
 *    d'insertion en base.
 *
 * `ville_id` est NULLABLE : une structure hors des villes couvertes doit rester enregistrable.
 * Le référentiel dira qu'elle n'a pas de ville ; il ne lui en inventera pas une.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('villes', function (Blueprint $table) {
            $table->id();
            $table->char('pays_code', 2)->default('CI');
            $table->string('code', 20);
            $table->string('nom', 120);

            // Centre géographique et rayon de couverture — la base du rattachement (décision V2).
            $table->decimal('latitude', 10, 8);
            $table->decimal('longitude', 11, 8);
            $table->unsignedSmallInteger('rayon_km');

            // Décision V3 : la subdivision en communes est une donnée de la ville.
            $table->boolean('affiche_communes')->default(false);

            $table->unsignedTinyInteger('ordre')->default(0);
            $table->boolean('actif')->default(true);

            $table->timestamps();

            $table->unique(['pays_code', 'code'], 'uq_ville_pays_code');
            $table->index(['pays_code', 'actif'], 'idx_ville_actif');
        });

        Schema::table('structures_sanitaires', function (Blueprint $table) {
            $table->foreignId('ville_id')->nullable()->after('commune')
                ->constrained('villes')->nullOnDelete();

            $table->index(['ville_id', 'type'], 'idx_structure_ville_type');
        });
    }

    public function down(): void
    {
        Schema::table('structures_sanitaires', function (Blueprint $table) {
            $table->dropIndex('idx_structure_ville_type');
            $table->dropConstrainedForeignId('ville_id');
        });

        Schema::dropIfExists('villes');
    }
};
