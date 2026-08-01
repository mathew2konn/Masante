<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Table `codes_otp` — codes de vérification à usage unique (doc Identification §5.2).
 *
 * Le code à 6 chiffres n'est JAMAIS stocké en clair : seule sa version hachée est en base
 * (comme un mot de passe). Expiration 5 min, usage unique, max 5 tentatives (§4.2).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('codes_otp', function (Blueprint $table) {
            $table->id();

            // Rattaché à un user si déjà créé (inscription), sinon NULL (récupération).
            $table->foreignId('user_id')->nullable()->constrained('users')->cascadeOnDelete();

            // Numéro cible (on indexe pour retrouver le code actif d'un téléphone).
            $table->string('telephone', 20)->index();

            // Code 6 chiffres HACHÉ (bcrypt) — jamais en clair.
            $table->string('code_hash');

            // Finalité du code.
            $table->enum('but', ['inscription', 'connexion', 'recuperation']);

            $table->timestamp('expires_at');                 // created_at + 5 minutes
            $table->timestamp('used_at')->nullable();        // NULL = pas encore utilisé
            $table->unsignedTinyInteger('tentatives')->default(0); // max 5

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('codes_otp');
    }
};
