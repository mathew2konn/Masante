<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Module 2 / étape 2A.4 — Table `resultats_analyses` (CdC §8.3, F2.6).
 *
 * Résultats biologiques/radiologiques. `resultats_json` (valeurs détaillées) est chiffré
 * AES-256 (cast `encrypted:array`, §6 Sécurité) → colonne `text`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('resultats_analyses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('membre_id')->constrained('membres_famille')->cascadeOnDelete();

            $table->enum('type_analyse', ['biologique', 'radiologique', 'cardiologique', 'autre']);
            $table->string('intitule', 200);
            $table->date('date_analyse');
            $table->string('laboratoire', 200)->nullable();
            $table->string('medecin_prescripteur', 200)->nullable();
            $table->text('resultats_json')->nullable();        // chiffré AES-256 (JSON)
            $table->string('fichier_url', 500)->nullable();
            $table->enum('added_by', ['patient', 'medecin'])->default('patient');

            $table->timestamps();
            $table->index('membre_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('resultats_analyses');
    }
};
