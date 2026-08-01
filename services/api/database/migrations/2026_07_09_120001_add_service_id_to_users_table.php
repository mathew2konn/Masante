<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Module 4 / 4.3 — Assigne un agent de garde à SON service (CdC §5.4.1 étape 4 & §5.4.2 « accès limité
 * à son service »).
 *
 * `service_id` (nullable) est renseigné pour les AGENTS uniquement : NULL pour les patients, l'admin et
 * le gestionnaire (rattaché au niveau établissement via `structure_id`, cf. 4.2). `nullOnDelete` : si le
 * service est supprimé, l'agent n'est pas détruit — il reste rattaché à l'établissement, à réassigner.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('service_id')
                ->nullable()
                ->after('structure_id')
                ->constrained('services_etablissement')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['service_id']);
            $table->dropColumn('service_id');
        });
    }
};
