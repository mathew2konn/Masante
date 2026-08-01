<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Module 2 / F2.12 — Table `notes_observations` (note libre sur le dossier d'un membre).
 *
 * Donnée sensible : `contenu` chiffré AES-256 (cast `encrypted` du modèle, §6 Sécurité) → `text`.
 * Entrée append-only tracée au journal d'audit (FT6) : seul `created_at`, pas d'`updated_at`.
 * `softDeletes` permet une rétractation sans perdre la trace d'audit.
 *
 * Auteur typé : `auteur_user_id` (FK users, nullOnDelete) quand l'auteur est un utilisateur de
 * l'app. `auteur_agent_id` (praticien / agent) est DIFFÉRÉ aux Modules 3/4 : la table `agents_garde`
 * n'existe pas encore — même convention que `membres_famille.medecin_referent_id`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notes_observations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('membre_id')->constrained('membres_famille')->cascadeOnDelete();

            $table->text('contenu');                                   // chiffré AES-256
            $table->enum('auteur_type', ['patient', 'medecin']);
            $table->foreignId('auteur_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('triage_id')->nullable()->constrained('triages')->nullOnDelete();

            $table->timestamp('created_at')->useCurrent();             // append-only : pas d'updated_at
            $table->softDeletes();

            $table->index(['membre_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notes_observations');
    }
};
