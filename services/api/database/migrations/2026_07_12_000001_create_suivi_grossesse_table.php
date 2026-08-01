<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Module 5 / 5.5 — Table `suivi_grossesse` (CdC §8, FN4).
 *
 * Écart assumé vs CdC : PAS de colonne `semaine_actuelle`. Le CdC l'annote lui-même
 * « calculée automatiquement » : la stocker exigerait un cron quotidien et pourrait
 * afficher une semaine périmée. Elle est calculée à la volée (accessor du modèle)
 * depuis `date_debut_grossesse` — même principe que l'âge déduit de la date de naissance.
 *
 * `consultations_json` (forme CdC) n'est JAMAIS écrit tel quel par le client : un endpoint
 * dédié ajoute une consultation à la fois, validée champ par champ (append-only, cf. F2.12).
 * Pas de suppression de suivi : clôture par `statut` uniquement (rétention médicale).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suivi_grossesse', function (Blueprint $table) {
            $table->id();
            $table->foreignId('membre_id')->constrained('membres_famille')->cascadeOnDelete();

            $table->date('date_debut_grossesse');
            // Terme = DDG + 280 jours (40 SA). Toujours calculé par le serveur, jamais fourni.
            $table->date('date_terme_prevue');
            $table->json('consultations_json')->nullable();
            $table->enum('statut', ['en_cours', 'termine', 'interruption'])->default('en_cours');

            $table->timestamps();
            $table->index('membre_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('suivi_grossesse');
    }
};
