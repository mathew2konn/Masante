<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Module 5 / 5.4 — Alertes épidémiques par commune (CdC FN3, §8 table `alertes_epidemiques`).
 *
 * Alertes sanitaires locales (paludisme, choléra, méningite, typhoïde…), publiées par l'admin
 * IVOIRSANTÉ d'après les bulletins OMS / Ministère de la Santé CI. Le mobile n'affiche à un
 * utilisateur que les alertes actives de SA commune (`users.commune`) plus les alertes nationales
 * (`commune = 'toutes_communes'`) — FN3 : « alerte uniquement les utilisateurs de la commune
 * concernée ».
 *
 * Schéma conforme au CdC §8. `date_fin` nullable = alerte en cours. `actif` permet de retirer une
 * alerte sans la supprimer (on conserve l'historique des épisodes).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('alertes_epidemiques', function (Blueprint $table) {
            $table->id();

            // Commune concernée, ou 'toutes_communes' pour une alerte nationale.
            $table->string('commune', 100)->index();

            $table->string('titre', 300);
            $table->text('description');
            $table->string('maladie', 100);
            $table->enum('niveau_alerte', ['information', 'vigilance', 'alerte']);
            $table->string('source', 200);

            $table->date('date_debut');
            $table->date('date_fin')->nullable();   // NULL = alerte en cours
            $table->boolean('actif')->default(true);

            // Traçabilité : quel compte admin a publié l'alerte (nullable sans casser l'historique).
            $table->foreignId('publie_par_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            // Index de la requête mobile : alertes actives d'une commune donnée.
            $table->index(['commune', 'actif']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alertes_epidemiques');
    }
};
