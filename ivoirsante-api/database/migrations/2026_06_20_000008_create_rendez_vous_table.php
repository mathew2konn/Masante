<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Module 3 / étape 3A.2 — Table `rendez_vous` (CdC §8.4, F3.6).
 *
 * Demande de RDV initiée par le patient (membre + structure + service + motif + date souhaitée,
 * fiche de triage F1.8 jointe en option). La VALIDATION par l'agent (date_confirmee, message,
 * passage à confirme/refuse) relève du portail admin → Module 4 ; ici on couvre le côté patient
 * (création, suivi, annulation). `statut` démarre à `en_attente`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rendez_vous', function (Blueprint $table) {
            $table->id();

            $table->foreignId('membre_id')->constrained('membres_famille')->cascadeOnDelete();
            $table->foreignId('structure_id')->constrained('structures_sanitaires')->cascadeOnDelete();
            $table->foreignId('service_id')->constrained('services_etablissement')->cascadeOnDelete();
            $table->foreignId('triage_id')->nullable()->constrained('triages')->nullOnDelete();

            $table->text('motif');
            $table->date('date_souhaitee');
            $table->dateTime('date_confirmee')->nullable();

            $table->enum('statut', ['en_attente', 'confirme', 'refuse', 'annule', 'honore'])->default('en_attente');
            $table->text('message_agent')->nullable();

            $table->timestamps();

            $table->index(['structure_id', 'statut']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rendez_vous');
    }
};
