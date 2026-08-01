<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Table `symptomes` — référentiel des symptômes reconnus par l'algorithme de triage.
 * Cahier des Charges §8.2 (Groupe 2). Les règles métier (poids, questions, drapeau rouge)
 * vivent en base pour être modifiables sans redéployer (F1.3).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('symptomes', function (Blueprint $table) {
            $table->id();
            $table->string('nom_fr', 200);

            // Catégorie clinique (sert au regroupement F1.1 et à la déduction de spécialité).
            $table->enum('categorie', [
                'fievre', 'douleur', 'respiratoire', 'digestif', 'neurologique',
                'dermatologique', 'ophtalmologique', 'orl', 'dentaire', 'cardiaque',
                'gynecologique', 'urinaire', 'traumatologique', 'general',
            ])->index();

            // Poids de sévérité de base du symptôme (0-100) — contribue au score.
            $table->unsignedTinyInteger('poids_severite')->default(0);

            // Indice de spécialité médicale (§5.1.3). Ex : ORL, Dentisterie, Cardiologie...
            $table->string('specialite_hint', 100)->nullable();

            // Drapeau rouge : un symptôme critique force immédiatement le niveau URGENT (§5.1.2).
            $table->boolean('drapeau_rouge')->default(false);

            // 3 à 5 questions complémentaires (F1.2), avec leur impact sur le score (JSON).
            $table->json('questions_complementaires_json')->nullable();

            // Maladies probables associées (F1.7 / fiche), ex : paludisme, typhoïde (JSON).
            $table->json('maladies_probables_json')->nullable();

            // Permet de désactiver un symptôme sans le supprimer.
            $table->boolean('actif')->default(true)->index();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('symptomes');
    }
};
