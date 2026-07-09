<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Module 4 / 4.2 — Liens d'activation des comptes staff du portail (CdC §5.4.1, étapes 2-3).
 *
 * « Le mot de passe temporaire n'existe pas — sécurité renforcée » : quand l'admin crée un gestionnaire
 * (ou un gestionnaire un agent, en 4.3), le compte naît SANS mot de passe. Un jeton à USAGE UNIQUE,
 * valable 24h, est émis ; le titulaire clique le lien et pose lui-même son mot de passe.
 *
 * On ne stocke que le HASH du jeton (jamais la valeur en clair — même logique que les OTP / grants).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activations_portail', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('token_hash', 64)->unique();   // sha-256 hex du jeton en clair
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();      // horodaté à la consommation (usage unique)
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activations_portail');
    }
};
