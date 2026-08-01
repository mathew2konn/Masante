<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Module 2 / étape 2A.4 — Pose la FK `triages.membre_id` → `membres_famille.id`.
 *
 * La colonne existait déjà (nullable, sans contrainte) depuis le Module 1, en attendant la
 * table `membres_famille` (créée en 2A.2). On ajoute maintenant la clé étrangère annoncée :
 * un triage rattaché à un membre supprimé voit son `membre_id` remis à NULL (nullOnDelete),
 * l'historique de triage restant conservé.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('triages', function (Blueprint $table) {
            $table->foreign('membre_id')->references('id')->on('membres_famille')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('triages', function (Blueprint $table) {
            $table->dropForeign(['membre_id']);
        });
    }
};
