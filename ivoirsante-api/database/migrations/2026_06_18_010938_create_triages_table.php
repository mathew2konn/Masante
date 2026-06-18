<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Table `triages` — historique des sessions de triage (Cahier des Charges §8.2).
 *
 * Note de séquencement : les FK `membre_id` (Module 2 / membres_famille) et
 * `structure_visitee_id` (Module 3 / structures_sanitaires) ne sont PAS posées ici
 * car ces tables n'existent pas encore. Les colonnes restent nullable ; les contraintes
 * seront ajoutées par les migrations des modules concernés.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('triages', function (Blueprint $table) {
            $table->id();

            // Utilisateur ayant initié le triage (FK vers users, table existante).
            // Nullable tant que l'authentification (téléphone+OTP) n'est pas branchée.
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();

            // Membre concerné (Module 2). Colonne seule, FK ajoutée au Module 2.
            $table->unsignedBigInteger('membre_id')->nullable()->index();

            // Snapshot du patient (en attendant le carnet/membre du Module 2) :
            // nécessaire à l'algorithme (pédiatrie <15 ans, gynécologie femme) et à la fiche F1.8.
            $table->string('patient_nom', 200)->nullable();
            $table->unsignedTinyInteger('patient_age')->nullable();
            $table->enum('patient_sexe', ['M', 'F'])->nullable();

            // Symptômes sélectionnés avec leur poids : [{id, nom, poids}]
            $table->json('symptomes_json');

            // Réponses aux questions complémentaires : [{symptome_id, cle, valeur, valeur_impact}]
            $table->json('reponses_json');

            // Score final calculé (0 à 100).
            $table->unsignedTinyInteger('score_severite');

            // Niveau de soin recommandé.
            $table->enum('niveau', ['leger', 'modere', 'urgent']);

            // Spécialité médicale déduite automatiquement (§5.1.3) — ORL, Dentisterie, Cardiologie...
            $table->string('specialite_requise', 100)->nullable();

            // Texte complet de la recommandation affichée au patient.
            $table->text('recommandation_texte');

            // Indique si la fiche partageable F1.8 a été générée/consultée.
            $table->boolean('fiche_generee')->default(false);

            // Structure finalement choisie (Module 3). Colonne seule, FK ajoutée au Module 3.
            $table->unsignedBigInteger('structure_visitee_id')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('triages');
    }
};
