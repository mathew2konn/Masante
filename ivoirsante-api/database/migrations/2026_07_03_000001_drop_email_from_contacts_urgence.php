<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Module 2 / F2.11 — Révision (modification.txt, 2026-07-03).
 *
 * L'e-mail n'est plus collecté pour un contact d'urgence : le contact se limite désormais au
 * téléphone (décision produit — un contact d'urgence se joint par appel, pas par e-mail).
 * Migration réversible : `down()` recrée la colonne à l'identique.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contacts_urgence', function (Blueprint $table) {
            $table->dropColumn('email');
        });
    }

    public function down(): void
    {
        Schema::table('contacts_urgence', function (Blueprint $table) {
            $table->string('email', 150)->nullable()->after('telephone_secondaire');
        });
    }
};
