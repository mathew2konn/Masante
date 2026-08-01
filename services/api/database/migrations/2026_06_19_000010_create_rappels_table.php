<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Module 2 / étape 2A.4 — Table `rappels` (CdC §8.3, F2.7).
 *
 * Rappels médicaments / rendez-vous / vaccins / contrôles. CRUD seul à cette étape :
 * l'envoi des notifications push (Firebase FCM) est DIFFÉRÉ au Module 3/5 (cf. plan).
 * `last_sent_at` est prévu pour ce branchement ultérieur.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rappels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('membre_id')->constrained('membres_famille')->cascadeOnDelete();

            $table->enum('type', ['medicament', 'rendez_vous', 'vaccin', 'controle']);
            $table->string('titre', 200);
            $table->text('contenu');
            $table->enum('frequence', ['unique', 'quotidien', 'hebdomadaire', 'mensuel']);
            $table->time('heure');
            $table->date('date_debut');
            $table->date('date_fin')->nullable();
            $table->boolean('actif')->default(true);
            $table->timestamp('last_sent_at')->nullable();     // branchement push différé

            $table->timestamps();
            $table->index('membre_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rappels');
    }
};
