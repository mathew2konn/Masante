<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Module 2 / F2.11 — Révision (2026-07-03) : un contact d'urgence n'a QU'UN SEUL numéro.
 *
 * On supprime `telephone_secondaire` : chaque contact = un nom + un téléphone (+ lien de parenté).
 * Migration réversible : `down()` recrée la colonne.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contacts_urgence', function (Blueprint $table) {
            $table->dropColumn('telephone_secondaire');
        });
    }

    public function down(): void
    {
        Schema::table('contacts_urgence', function (Blueprint $table) {
            $table->string('telephone_secondaire', 20)->nullable()->after('telephone');
        });
    }
};
