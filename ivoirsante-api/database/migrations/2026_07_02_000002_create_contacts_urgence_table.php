<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Module 2 / F2.11 — Table `contacts_urgence` (personne à prévenir PAR membre).
 *
 * Alimente la carte vitale d'urgence (Module 5) et est lisible lors d'un accès dossier autorisé.
 * `est_principal` désigne le contact prioritaire (au plus un par membre — règle appliquée au
 * FormRequest/Service). `telephone_secondaire` et `email` renforcent la joignabilité (notifs M5).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contacts_urgence', function (Blueprint $table) {
            $table->id();
            $table->foreignId('membre_id')->constrained('membres_famille')->cascadeOnDelete();

            $table->string('nom', 200);
            $table->string('lien_parente', 100)->nullable();
            $table->string('telephone', 20);
            $table->string('telephone_secondaire', 20)->nullable();
            $table->string('email', 150)->nullable();
            $table->boolean('est_principal')->default(false);

            $table->timestamps();

            $table->index(['membre_id', 'est_principal']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contacts_urgence');
    }
};
