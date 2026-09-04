<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * B4 (ADR-056, S3) — `commissions_transaction` gagne `frais_connus`.
 *
 * Le canal GeniusPay ne connaît pas toujours les frais réels au moment de la transition terminale
 * (dette nommée en P5.6b, R4) : le webhook nominal ne les porte pas toujours. Décision du
 * propriétaire du 2026-09-04 : calculer la commission avec des frais à 0 EXPLICITE plutôt que de
 * refuser, mais SANS laisser croire que 0 était une valeur connue — d'où cette colonne, qui dit
 * honnêtement si `frais_passerelle`/`frais_prestataire` étaient sus ou supposés.
 *
 * `default(true)` : toutes les commissions ANTÉRIEURES à cette colonne (aucune en base réelle —
 * `calculerEtEnregistrer()` n'avait aucun appelant avant B4) et tout appelant qui ne fournit pas
 * `fraisConnus` continuent de dire « frais connus », comportement inchangé pour eux (ADR-024 :
 * enrichissement additif, jamais un mensonge d'archive).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('commissions_transaction', function (Blueprint $table) {
            $table->boolean('frais_connus')->default(true)->after('frais_prestataire');
        });
    }

    public function down(): void
    {
        Schema::table('commissions_transaction', function (Blueprint $table) {
            $table->dropColumn('frais_connus');
        });
    }
};
