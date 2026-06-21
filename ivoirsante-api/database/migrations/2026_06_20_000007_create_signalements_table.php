<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Module 3 / étape 3A.2 — Table `signalements` (CdC §8.4, F3.10).
 *
 * Signalements citoyens sur une structure (fermée, hors service, pot-de-vin…). ANONYME possible :
 * `user_id` nullable. Modération : `statut` en_attente → valide/rejete (par l'admin, Module 4) ;
 * seuls les signalements `visible_publiquement` apparaissent dans l'historique public.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('signalements', function (Blueprint $table) {
            $table->id();

            $table->enum('type', ['structure_fermee', 'hors_service', 'pot_de_vin', 'mauvais_traitement', 'autre']);
            $table->foreignId('structure_id')->constrained('structures_sanitaires')->cascadeOnDelete();

            // Anonyme : un signalement peut être déposé sans compte (user_id NULL).
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->text('description');
            $table->enum('statut', ['en_attente', 'valide', 'rejete'])->default('en_attente');
            $table->boolean('visible_publiquement')->default(false);

            $table->timestamps();

            $table->index(['structure_id', 'visible_publiquement']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('signalements');
    }
};
