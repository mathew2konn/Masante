<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Module 3 / étape 3A.1 — Table `disponibilites_jour` (CdC §8.4, F3.3).
 *
 * Disponibilité quotidienne d'un service → alimente la pastille de la carte (DesignSystem §5.5)
 * VERT (disponible) / ORANGE (disponible_apres_14h) / ROUGE (complet) / gris (ferme).
 *
 * Séquencement : l'écriture par les `agents_garde` et la synchro temps réel Firebase
 * arrivent au Module 4. `updated_by_agent_id` reste donc nullable SANS clé étrangère
 * (table `agents_garde` non encore créée). En 3A.1, ces lignes sont seedées.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('disponibilites_jour', function (Blueprint $table) {
            $table->id();

            $table->foreignId('service_id')->constrained('services_etablissement')->cascadeOnDelete();
            $table->date('date');

            $table->enum('statut', ['disponible', 'complet', 'disponible_apres_14h', 'ferme']);
            $table->unsignedInteger('nb_places_restantes')->nullable();
            $table->time('heure_debut_dispo')->nullable();
            $table->string('note', 500)->nullable();

            // Agent ayant mis à jour (Module 4) — colonne seule, FK ajoutée à ce moment-là.
            $table->unsignedBigInteger('updated_by_agent_id')->nullable();

            $table->timestamps();

            // Une seule disponibilité par service et par jour.
            $table->unique(['service_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('disponibilites_jour');
    }
};
