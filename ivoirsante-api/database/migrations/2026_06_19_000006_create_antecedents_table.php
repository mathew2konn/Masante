<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Module 2 / étape 2A.4 — Table `antecedents` (CdC §8.3, F2.4).
 *
 * Antécédents médicaux d'un membre (maladie chronique, allergie, chirurgie, hospitalisation).
 * `description` et `traitement_actuel` sont chiffrés AES-256 (cast `encrypted` du modèle,
 * §6 Sécurité) → colonnes `text` (le chiffré est plus long que le clair).
 *
 * `impact_triage` (0-20) alimente le score de triage (F1.3) : la somme des impacts des
 * antécédents du membre est plafonnée à 20 par TriageService.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('antecedents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('membre_id')->constrained('membres_famille')->cascadeOnDelete();

            $table->enum('type', ['maladie_chronique', 'allergie', 'chirurgie', 'hospitalisation', 'autre']);
            $table->text('description');                       // chiffré AES-256
            $table->date('date_diagnostic')->nullable();
            $table->string('medecin_nom', 200)->nullable();
            $table->string('structure_sanitaire', 200)->nullable();
            $table->text('traitement_actuel')->nullable();     // chiffré AES-256

            // Impact sur le score de triage (plafond global de 20 appliqué côté service).
            $table->unsignedTinyInteger('impact_triage')->default(0);
            $table->enum('added_by', ['patient', 'medecin'])->default('patient');

            $table->timestamps();
            $table->index('membre_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('antecedents');
    }
};
