<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Module 2 / étape 2A.3 — Table `tokens_qr` (CdC §8.1, F2.2 ; Sécurité §5).
 *
 * Token QR dynamique à usage unique donnant un accès temporaire au dossier d'un membre.
 * On stocke le HASH du token (jamais le token en clair) : même en cas de vol de la table,
 * un attaquant ne peut pas reconstituer un QR valide (§5.2 Sécurité). Expiration 10 min,
 * invalidé au premier scan.
 *
 * `used_by_agent_id` est nullable SANS clé étrangère : la table `agents_garde` n'existe pas
 * encore (Module 3). La consommation côté agent (scan) sera branchée à ce moment-là ; pour
 * l'instant, seule la génération côté patient est exposée.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tokens_qr', function (Blueprint $table) {
            $table->id();

            // Membre dont le dossier est partagé : QR révoqué si le membre est supprimé.
            $table->foreignId('membre_id')->constrained('membres_famille')->cascadeOnDelete();

            // Hash SHA-256 du token public (UUID v4 + timestamp + membre, signé HMAC).
            $table->string('token_hash', 255)->unique();

            $table->timestamp('expires_at');                 // created_at + 10 minutes
            $table->timestamp('used_at')->nullable();         // NULL = pas encore scanné

            // Contexte du scan (rempli à la consommation) — agent différé au Module 3.
            $table->unsignedBigInteger('used_by_agent_id')->nullable();
            $table->string('used_by_etablissement', 200)->nullable();

            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tokens_qr');
    }
};
