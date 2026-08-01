<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Module 5 / 5.5 — Référentiel des 8 contacts prénatals OMS/PSN-CI (FN4).
 *
 * Le calendrier prénatal et les conseils (dont nutrition adaptée CI) vivent EN BASE,
 * comme les règles du triage (F1.3, table `symptomes`) : modifiables par un simple
 * UPDATE, sans redéployer l'application. Le mobile ne fait qu'afficher ce contenu.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('etapes_prenatales', function (Blueprint $table) {
            $table->id();

            // Numéro du contact OMS (1 → 8), stable : sert de clé de mise à jour au seeder.
            $table->unsignedTinyInteger('numero')->unique();
            // Semaine d'aménorrhée recommandée pour ce contact (borne le calendrier).
            $table->unsignedTinyInteger('semaine_recommandee');

            $table->string('libelle', 200);
            $table->text('description');           // objet du contact : examens, gestes clés
            $table->text('conseils_nutrition');    // axe nutrition/hygiène adapté CI

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('etapes_prenatales');
    }
};
