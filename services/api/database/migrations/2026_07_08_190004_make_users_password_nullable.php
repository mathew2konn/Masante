<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Module 4 / 4.2 — Rend `users.password` nullable (CdC §5.4.1 : « le mot de passe temporaire n'existe pas »).
 *
 * Un compte staff est créé SANS mot de passe et le pose lui-même via son lien d'activation. Un mot de
 * passe NULL rend `Auth::attempt` toujours faux (bcrypt refuse un hash vide) : le compte est inutilisable
 * tant qu'il n'est pas activé. Les patients (mobile) ont toujours un mot de passe posé à l'inscription.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('password')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('password')->nullable(false)->change();
        });
    }
};
