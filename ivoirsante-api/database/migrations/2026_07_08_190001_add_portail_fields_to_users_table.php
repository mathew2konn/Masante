<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Module 4 / 4.2 — Rattachement d'un compte STAFF à son établissement + interrupteur d'activité.
 *
 * `structure_id` (nullable) lie un gestionnaire/agent à SON établissement (cloisonnement CdC §5.4.2 :
 * « ne voit pas les autres établissements »). NULL pour les patients (mobile) et pour l'admin IVOIRSANTÉ
 * (qui n'appartient à aucun établissement). `actif` permet de suspendre un compte staff sans le supprimer
 * (désactivation d'un établissement → ses comptes ne peuvent plus se connecter).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('structure_id')
                ->nullable()
                ->after('id')
                ->constrained('structures_sanitaires')
                ->nullOnDelete();

            $table->boolean('actif')->default(true)->after('niveau_compte');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['structure_id']);
            $table->dropColumn(['structure_id', 'actif']);
        });
    }
};
