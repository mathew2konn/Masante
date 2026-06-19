<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Module 2 / étape 2A.2 — Table `membres_famille` (CdC §8.1, F2.1).
 *
 * Un compte (`users`) regroupe jusqu'à 5 membres (règle métier F2.2, appliquée au
 * niveau requête). Chaque membre porte un `matricule_ivs` interne unique (IVS-AAAA-XX-NNNNN)
 * qui sert de base au QR Code dynamique (2A.3) mais n'est JAMAIS exposé (§1 Sécurité —
 * minimisation). Le numéro CMU est chiffré au repos via le cast `encrypted` du modèle
 * (§6.1 Sécurité), d'où le type `text` ici (le chiffré est plus long que l'original).
 *
 * `medecin_referent_id` est nullable SANS clé étrangère : la table `agents_garde` n'existe
 * pas encore (Modules 3/4). La contrainte FK sera ajoutée à ce moment-là.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('membres_famille', function (Blueprint $table) {
            $table->id();

            // Compte propriétaire : un membre supprimé avec son compte (cascade).
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();

            // Identifiant interne permanent du dossier — jamais affiché ni encodé dans le QR.
            $table->string('matricule_ivs', 30)->unique();

            $table->string('nom', 100);
            $table->string('prenom', 100);
            $table->date('date_naissance');
            $table->enum('sexe', ['M', 'F']);
            $table->enum('groupe_sanguin', ['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'])->nullable();
            $table->string('photo_url', 500)->nullable();

            // Données CMU : numéro chiffré AES-256 (cast `encrypted`) → `text` (chiffré plus long).
            $table->text('cmu_numero')->nullable();
            $table->enum('cmu_statut', ['actif', 'expire', 'non_inscrit'])->default('non_inscrit');
            $table->date('cmu_validite')->nullable();

            // Médecin référent (accès permanent au dossier) — branché au Module 3/4 (sans FK ici).
            $table->unsignedBigInteger('medecin_referent_id')->nullable();

            $table->timestamps();

            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('membres_famille');
    }
};
