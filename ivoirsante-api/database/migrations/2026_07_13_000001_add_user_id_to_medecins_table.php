<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Module 5 / 5.6 — Voie 2 « médecin référent » (Sécurité §4.4 ; Note_Continuite §2).
 *
 * `medecins` était jusqu'ici un annuaire PUBLIC mort côté authentification (Module 3 / F3.5) :
 * une fiche nom/spécialité/tarif, sans lien vers un compte. Le référent, lui, doit pouvoir OUVRIR
 * un dossier depuis le portail — il lui faut donc un compte.
 *
 * D'où ce lien : `user_id` relie une fiche de l'annuaire à un compte portail (agent de garde).
 * Le patient continue de désigner son référent DANS L'ANNUAIRE PUBLIC qu'il connaît déjà (celui du
 * RDV) : on n'expose ainsi aucune liste des comptes du portail — même refus d'annuaire que pour le
 * bris de glace (5.3). Une fiche sans compte reste désignable, mais son titulaire ne verra rien
 * tant que le gestionnaire ne l'aura pas reliée (`consulte_en_ligne` = false côté mobile).
 *
 * NULLABLE (toutes les fiches n'ont pas de compte) et UNIQUE (un compte = au plus une fiche).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('medecins', function (Blueprint $table) {
            $table->foreignId('user_id')
                ->nullable()
                ->after('service_id')
                ->constrained('users')
                // Le compte supprimé ne doit pas emporter la fiche de l'annuaire : on la délie.
                ->nullOnDelete();

            $table->unique('user_id');
        });
    }

    public function down(): void
    {
        Schema::table('medecins', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
            $table->dropUnique(['user_id']);
            $table->dropColumn('user_id');
        });
    }
};
