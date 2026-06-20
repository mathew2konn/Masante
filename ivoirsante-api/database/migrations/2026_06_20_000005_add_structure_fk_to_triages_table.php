<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Module 3 / étape 3A.1 — Pose la FK `triages.structure_visitee_id` → `structures_sanitaires.id`.
 *
 * La colonne existait déjà (nullable, sans contrainte) depuis le Module 1, en attendant la
 * table `structures_sanitaires` (créée en 3A.1). Un triage dont la structure est supprimée
 * voit son `structure_visitee_id` remis à NULL (nullOnDelete) — l'historique est conservé.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('triages', function (Blueprint $table) {
            $table->foreign('structure_visitee_id')->references('id')->on('structures_sanitaires')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('triages', function (Blueprint $table) {
            $table->dropForeign(['structure_visitee_id']);
        });
    }
};
