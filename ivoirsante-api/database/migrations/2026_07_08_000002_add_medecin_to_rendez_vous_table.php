<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Module 3 / étape F3.5 — Choix du médecin au RDV (Analyse_Delta_RDV N5).
 *
 * `medecin_id` NULLABLE : renseigné quand le patient choisit un praticien précis, NULL quand il
 * laisse l'établissement attribuer (le médecin sera fixé par l'agent au Module 4). `mode_attribution`
 * trace le chemin suivi (2 valeurs, décision du 2026-07-08) : le « mode mixte » de N5 = une
 * préférence patient (medecin_id renseigné) que l'agent pourra réassigner au Module 4 — pas besoin
 * d'un 3ᵉ état stocké.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rendez_vous', function (Blueprint $table) {
            $table->foreignId('medecin_id')
                ->nullable()
                ->after('service_id')
                ->constrained('medecins')
                ->nullOnDelete();

            $table->enum('mode_attribution', ['patient_choisit', 'etablissement_attribue'])
                ->default('etablissement_attribue')
                ->after('medecin_id');
        });
    }

    public function down(): void
    {
        Schema::table('rendez_vous', function (Blueprint $table) {
            $table->dropConstrainedForeignId('medecin_id');
            $table->dropColumn('mode_attribution');
        });
    }
};
