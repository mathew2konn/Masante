<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Module 2 / F2.13 — Traçabilité de la provenance des données.
 *
 * Ajoute une colonne `source ENUM('patient','medecin','structure')` aux tables de dossier
 * existantes (`antecedents`, `ordonnances`, `resultats_analyses`), conformément au §2.2 :
 * distingue le dossier hospitalier (source de vérité) du carnet auto-renseigné par le patient.
 * Placée après `added_by`, avec défaut `patient`. Réversible : `down()` supprime la colonne.
 */
return new class extends Migration
{
    /** Tables dossier recevant la provenance F2.13. */
    private array $tables = ['antecedents', 'ordonnances', 'resultats_analyses'];

    public function up(): void
    {
        foreach ($this->tables as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->enum('source', ['patient', 'medecin', 'structure'])
                    ->default('patient')
                    ->after('added_by');
            });
        }
    }

    public function down(): void
    {
        foreach ($this->tables as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->dropColumn('source');
            });
        }
    }
};
