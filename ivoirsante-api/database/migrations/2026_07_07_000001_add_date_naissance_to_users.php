<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase B / B1 — Auth durci. Ajoute `date_naissance` au titulaire du compte.
 *
 * Nécessaire à la récupération de mot de passe DURCIE (note Securite_IVOIRSANTE_2, chap. 4) :
 * pour le palier « base », la preuve exigée en plus de l'OTP est la date de naissance exacte
 * du titulaire. Le champ sert aussi au futur profil enrichi du titulaire (modification.txt §1).
 * Nullable : l'inscription reste minimale (nom, prénom, téléphone, mot de passe).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->date('date_naissance')->nullable()->after('prenom');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('date_naissance');
        });
    }
};
