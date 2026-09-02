<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * B1-c — Sixième voie d'accès au dossier : `rdv_partage` (D8, CDC_11 §9).
 *
 * `change()` plutôt qu'un DDL brut, précédent exact de la migration qui a ajouté `bris_de_glace` :
 * MySQL réécrit l'ENUM, SQLite (tests) recrée la table avec sa contrainte CHECK — SQLite CONTRAINT
 * bien les enums de Laravel, un `ALTER TABLE ... MODIFY` réservé à MySQL laisserait les tests
 * échouer sans le dire.
 *
 * `rendez_vous_id` : un IDENTIFIANT, sans clé étrangère — précédent `triage_id` (ADR-042 D1). Le
 * journal d'audit est immuable et append-only ; le coupler par une clé étrangère au RDV (table
 * mutable, supprimable) romprait la même garantie qu'une FK sur un compte a rompue en P10b-1/P10b-2
 * (identifiants pris dans une action référentielle). Posé sur les DEUX lignes d'un accès partagé
 * (ouverture ET clôture), comme `etablissement`/`triage_id` : les deux lignes doivent désigner le
 * même rendez-vous.
 */
return new class extends Migration
{
    /** Les six voies d'accès au dossier après extension. */
    private const VOIES = ['qr_scan', 'referent', 'delegation', 'bris_de_glace', 'admin', 'rdv_partage'];

    public function up(): void
    {
        Schema::table('acces_dossier', function (Blueprint $table) {
            $table->enum('type_acces', self::VOIES)->change();
            $table->unsignedBigInteger('rendez_vous_id')->nullable()->after('acces_ouverture_id');

            $table->index('rendez_vous_id');
        });
    }

    public function down(): void
    {
        Schema::table('acces_dossier', function (Blueprint $table) {
            $table->dropIndex(['rendez_vous_id']);
            $table->dropColumn('rendez_vous_id');
            $table->enum('type_acces', ['qr_scan', 'referent', 'delegation', 'bris_de_glace', 'admin'])->change();
        });
    }
};
