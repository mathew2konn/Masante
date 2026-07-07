<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase B / B1 — Jetons de réinitialisation de mot de passe (modification.txt, « Mot de passe oublié »).
 *
 * Franchir l'étape OTP + preuve durcie délivre un jeton INTERMÉDIAIRE à usage unique (~10 min) :
 * il prouve que ces deux barrières ont été passées sans permettre de changer le mot de passe dans
 * la même requête que la saisie du code (séparation verify-otp / reset).
 *
 * Le jeton (haut degré d'entropie, 64 caractères aléatoires) est stocké HACHÉ en SHA-256 — jamais
 * en clair. SHA-256 (et non bcrypt) car le jeton est recherché directement par son empreinte ;
 * son entropie rend la recherche par empreinte sûre (contrairement à l'OTP à 6 chiffres, lui en bcrypt).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('password_reset_grants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('telephone', 20)->index();
            $table->string('token_hash', 64)->unique();   // sha256 hex
            $table->timestamp('expires_at');               // created_at + 10 minutes
            $table->timestamp('used_at')->nullable();      // NULL = pas encore consommé
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('password_reset_grants');
    }
};
