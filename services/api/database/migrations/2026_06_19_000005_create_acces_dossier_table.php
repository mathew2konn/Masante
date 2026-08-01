<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Module 2 / étape 2A.3 — Journal d'audit `acces_dossier` (CdC §8.1 ; Sécurité §10).
 *
 * Traçabilité imposée par la loi n°2013-450 : qui a consulté quel dossier, quand, comment.
 * La table est rendue IMMUABLE au niveau applicatif (modèle AccesDossier : updating/deleting
 * bloqués, §10.2). Pas de colonne `updated_at` : journal en ajout seul (append-only).
 *
 * `agent_id` est nullable SANS clé étrangère (agents au Module 3). Pour l'instant, les accès
 * journalisés proviennent du cycle QR côté patient (type `qr_scan`, agent non encore identifié).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('acces_dossier', function (Blueprint $table) {
            $table->id();

            $table->foreignId('membre_id')->constrained('membres_famille')->cascadeOnDelete();

            // Agent/médecin ayant accédé — différé au Module 3 (nullable sans FK).
            $table->unsignedBigInteger('agent_id')->nullable();

            // Token QR utilisé (NULL si accès médecin référent) — supprimé ≠ audit effacé.
            $table->foreignId('token_qr_id')->nullable()->constrained('tokens_qr')->nullOnDelete();

            $table->enum('type_acces', ['qr_scan', 'referent', 'admin']);
            $table->json('sections_consultees')->nullable();
            $table->json('donnees_ajoutees')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->integer('duree_minutes')->nullable();

            // Journal append-only : created_at uniquement (pas de updated_at).
            $table->timestamp('created_at')->useCurrent();

            $table->index('membre_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('acces_dossier');
    }
};
