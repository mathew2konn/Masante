<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Module 5 / 5.5 — Rattache les rappels CPN générés automatiquement à leur suivi de grossesse.
 *
 * FN4 réutilise la table `rappels` (F2.7) au lieu d'un second mécanisme de rappel. Cette FK
 * nullable distingue les rappels AUTO-GÉRÉS (régénérés si la DDG est ajustée, supprimés à la
 * clôture) des rappels créés à la main par l'utilisateur (`NULL`, jamais touchés par FN4).
 * Repérer les rappels CPN par leur titre aurait été fragile — la FK est la seule marque fiable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rappels', function (Blueprint $table) {
            $table->foreignId('suivi_grossesse_id')
                ->nullable()
                ->after('membre_id')
                ->constrained('suivi_grossesse')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('rappels', function (Blueprint $table) {
            $table->dropConstrainedForeignId('suivi_grossesse_id');
        });
    }
};
