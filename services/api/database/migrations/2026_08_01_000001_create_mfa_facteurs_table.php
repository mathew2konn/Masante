<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P1 (Identité) — Facteurs MFA d'un compte (CDC_04 §« Identité et accès » : `mfa_facteurs`,
 * CDC_10 §3.5). Socle « prêt à activer » : le second facteur est enrôlé/confirmé par
 * l'utilisateur, mais l'EXIGENCE à la connexion reste gouvernée par `config('mfa.enforce')`
 * (désactivée par défaut en MVP). La DÉCISION « MFA requis » est backend, jamais déduite au front.
 *
 * MVP : un seul type réellement branché = `totp` (Authenticator, RFC 6238). Le type `sms`
 * réutiliserait l'OtpService existant (dimensionné, non branché ici). Un facteur par (compte, type).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mfa_facteurs', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->enum('type', ['totp', 'sms']);

            // Secret TOTP CHIFFRÉ au repos (cast 'encrypted' du modèle — jamais en clair en base).
            // NULLABLE : un facteur `sms` n'a pas de secret propre (il s'appuie sur le téléphone).
            $table->text('secret')->nullable();

            // Un facteur ne compte que CONFIRMÉ (premier code validé). NULL = enrôlement en attente.
            $table->timestamp('confirmed_at')->nullable();

            // Anti-rejeu TOTP : dernière tranche horaire consommée (aucun code réutilisable).
            $table->unsignedBigInteger('last_timeslice')->nullable();
            $table->timestamp('last_used_at')->nullable();

            $table->timestamps();

            // Un seul facteur par type et par compte (idempotence de l'enrôlement).
            $table->unique(['user_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mfa_facteurs');
    }
};
