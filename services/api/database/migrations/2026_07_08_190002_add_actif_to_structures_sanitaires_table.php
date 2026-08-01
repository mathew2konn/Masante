<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Module 4 / 4.2 — Interrupteur d'activité d'un établissement (CdC §5.4.2 : l'admin peut « supprimer »).
 *
 * On DÉSACTIVE plutôt qu'on ne SUPPRIME : la structure est référencée par des RDV, avis, services et
 * disponibilités (intégrité + historique médical). `actif=false` la retire de l'annuaire public (mobile)
 * et bloque ses comptes staff, sans casser les données liées. Réversible.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('structures_sanitaires', function (Blueprint $table) {
            $table->boolean('actif')->default(true)->after('partenaire_ivoirsante');
            $table->index('actif');
        });
    }

    public function down(): void
    {
        Schema::table('structures_sanitaires', function (Blueprint $table) {
            $table->dropIndex(['actif']);
            $table->dropColumn('actif');
        });
    }
};
