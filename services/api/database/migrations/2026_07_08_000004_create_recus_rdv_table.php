<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Module 3 / N2 (Analyse_Delta_RDV) — Table `recus_rdv` (reçu numérique de RDV).
 *
 * Un reçu présentable à l'accueil, distinct de la fiche triage F1.8. Porte une `reference` unique
 * (MS-RECU-…), le lien vers le paiement (N1), un statut de validité et une date d'expiration. Le QR
 * de check-in (N3) n'est PAS stocké ici : c'est un token signé autonome régénéré à la demande
 * (RecuRdvService), à secret CLOISONNÉ, qui n'ouvre jamais le dossier médical (≠ QR carnet).
 * Le scan/validation à l'accueil relève du Module 4.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recus_rdv', function (Blueprint $table) {
            $table->id();

            $table->foreignId('rendez_vous_id')->unique()->constrained('rendez_vous')->cascadeOnDelete();
            $table->foreignId('paiement_id')->nullable()->constrained('paiements')->nullOnDelete();

            $table->string('reference', 32)->unique();
            $table->enum('statut', ['reserve', 'paye', 'confirme', 'utilise', 'annule', 'expire'])
                ->default('paye');
            $table->dateTime('expires_at')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recus_rdv');
    }
};
