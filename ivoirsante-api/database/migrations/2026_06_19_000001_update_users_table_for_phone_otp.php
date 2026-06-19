<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Module 2 / étape 2A.1 — Adapte la table `users` à l'identification par téléphone + OTP
 * (doc Identification §5). Le téléphone devient l'identifiant principal ; l'e-mail
 * devient optionnel. Deux niveaux de compte (base / vérifié) protègent les données
 * médicales sensibles sans alourdir l'usage courant.
 *
 * Écart assumé vs Cahier des Charges v3.0 (qui imposait e-mail obligatoire + activation
 * par lien) : tranché par les docs Identification et Sécurité + décision projet.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // `name` (nom complet) → `nom` + `prenom` séparés (CdC §8.1 users).
            $table->renameColumn('name', 'nom');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('prenom', 100)->nullable()->after('nom');

            // Identifiant principal de connexion : téléphone mobile (format CI +225XXXXXXXXXX).
            $table->string('telephone', 20)->nullable()->unique()->after('prenom');
            $table->timestamp('telephone_verified_at')->nullable()->after('email_verified_at');

            // Deux niveaux de compte (doc Identification §3).
            $table->enum('niveau_compte', ['base', 'verifie'])->default('base')->after('password');
            $table->timestamp('compte_verifie_at')->nullable()->after('niveau_compte');
            $table->enum('methode_verification', ['cmu', 'cni'])->nullable()->after('compte_verifie_at');

            // Champs identité/urgence du CdC (utilisés par les modules SOS / épidémies).
            $table->string('commune', 100)->nullable()->after('methode_verification');
            $table->string('contact_urgence_nom', 200)->nullable()->after('commune');
            $table->string('contact_urgence_tel', 20)->nullable()->after('contact_urgence_nom');
        });

        // E-mail rendu optionnel (reste unique : MySQL autorise plusieurs NULL).
        Schema::table('users', function (Blueprint $table) {
            $table->string('email')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['telephone']);
            $table->dropColumn([
                'prenom', 'telephone', 'telephone_verified_at',
                'niveau_compte', 'compte_verifie_at', 'methode_verification',
                'commune', 'contact_urgence_nom', 'contact_urgence_tel',
            ]);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->renameColumn('nom', 'name');
            $table->string('email')->nullable(false)->change();
        });
    }
};
