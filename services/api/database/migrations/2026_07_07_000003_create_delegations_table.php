<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase B / B3 — Délégation d'accès (note Note_Continuite_Acces_Dossier, chap. 4, voie 3).
 *
 * Le titulaire désigne à l'avance un adulte de confiance (délégué, disposant de son propre compte)
 * autorisé UNIQUEMENT à générer le QR d'un ou plusieurs membres du carnet — jamais à modifier le
 * dossier ni à gérer le compte. Le délégué doit accepter depuis son application ; révocable à tout
 * moment par le titulaire (effet immédiat via `revoquee_at`).
 *
 * `droits` en ENUM laisse la porte ouverte à des droits futurs sans nouvelle table (chap. 4.3).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('delegations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('titulaire_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('delegue_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('membre_id')->constrained('membres_famille')->cascadeOnDelete();

            $table->enum('droits', ['qr_generation'])->default('qr_generation');

            $table->timestamp('invitee_at');               // envoi de l'invitation
            $table->timestamp('acceptee_at')->nullable();  // NULL = pas encore acceptée
            $table->timestamp('revoquee_at')->nullable();  // NULL = active

            $table->timestamps();

            // Une seule délégation par couple (délégué, membre) — chap. 4.3.
            $table->unique(['delegue_user_id', 'membre_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('delegations');
    }
};
