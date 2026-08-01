<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Module 3 / étape 3A.2 — Table `avis` (CdC §8.4, F3.9).
 *
 * Avis patients sur une structure (note 1-5 + commentaire). `consultation_verifiee` = TRUE si
 * l'auteur a un RDV confirmé/honoré dans cette structure (badge « vérifié », inspiré Doctolib).
 * `visible` = FALSE si modéré (mots interdits) ; `signale` si remonté comme inapproprié.
 *
 * Un avis par utilisateur et par structure (contrainte d'unicité) : la création met à jour
 * l'avis existant le cas échéant.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('avis', function (Blueprint $table) {
            $table->id();

            $table->foreignId('structure_id')->constrained('structures_sanitaires')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();

            $table->unsignedTinyInteger('note'); // 1 à 5 (validé côté requête)
            $table->text('commentaire')->nullable();

            $table->boolean('consultation_verifiee')->default(false);
            $table->boolean('signale')->default(false);
            $table->boolean('visible')->default(true);

            $table->timestamps();

            $table->unique(['structure_id', 'user_id']);
            $table->index(['structure_id', 'visible']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('avis');
    }
};
