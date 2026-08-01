<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Module 3 / étape 3A.1 — Table `services_etablissement` (CdC §8.4).
 *
 * Services médicaux d'une structure (Médecine générale, ORL, Urgences…). Le champ
 * `specialite` sert au matching avec la spécialité déduite par le triage (F1.5).
 * La configuration par les gestionnaires d'établissement relève du Module 4 : ici les
 * services sont seedés et exposés en lecture seule.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('services_etablissement', function (Blueprint $table) {
            $table->id();

            $table->foreignId('structure_id')->constrained('structures_sanitaires')->cascadeOnDelete();

            $table->string('nom_service', 200);
            $table->string('specialite', 100); // code de spécialité (matching triage)
            $table->boolean('actif')->default(true);

            $table->timestamps();

            $table->index(['structure_id', 'specialite']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('services_etablissement');
    }
};
