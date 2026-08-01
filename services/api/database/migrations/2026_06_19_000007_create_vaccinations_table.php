<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Module 2 / étape 2A.4 — Table `vaccinations` (CdC §8.3, F2.7).
 *
 * Carnet de vaccination d'un membre (calendrier vaccinal ivoirien / OMS). Données non
 * classées sensibles au sens du §6 Sécurité (pas de chiffrement applicatif requis ici).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vaccinations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('membre_id')->constrained('membres_famille')->cascadeOnDelete();

            $table->string('vaccin_nom', 200);
            $table->boolean('obligatoire')->default(false);
            $table->date('date_administration')->nullable();
            $table->date('date_rappel')->nullable();
            $table->enum('statut', ['fait', 'a_faire', 'en_retard']);
            $table->string('centre_vaccination', 200)->nullable();
            $table->string('numero_lot', 100)->nullable();
            $table->string('medecin_nom', 200)->nullable();

            $table->timestamps();
            $table->index('membre_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vaccinations');
    }
};
