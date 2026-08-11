<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Carnet familial partagé / incrément A — la délégation peut porter la LECTURE du dossier.
 *
 * Jusqu'ici, `delegations.droits` ne connaissait qu'une valeur : `qr_generation`. Un délégué
 * pouvait présenter le QR d'un membre mais ne voyait rien du carnet — c'était le périmètre de
 * la voie 3 (Note_Continuite chap. 4).
 *
 * Le partage familial (plan G1 du 2026-08-11) demande davantage : un proche à qui l'on confie
 * un carnet doit pouvoir le LIRE. On ajoute donc deux niveaux, dans une hiérarchie stricte :
 *
 *     qr_generation  <  lecture  <  lecture_ecriture
 *
 * `lecture_ecriture` est posé DÈS MAINTENANT bien qu'aucun droit d'écriture ne soit encore
 * accordé : l'écriture arrive à l'incrément C, avec son circuit de brouillon et de validation.
 * Poser la valeur ici évite un second ALTER sur une table de production, et une valeur qu'aucun
 * code n'attribue ne peut être exploitée.
 *
 * ADDITIVE ET RÉTROCOMPATIBLE : le défaut reste `qr_generation`, donc les délégations existantes
 * conservent EXACTEMENT le droit qu'elles avaient. Personne ne gagne un accès au dossier par
 * l'effet de cette migration — seules les délégations créées ensuite portent `lecture`.
 */
return new class extends Migration
{
    /** Valeurs de l'énumération après migration. */
    private const DROITS = ['qr_generation', 'lecture', 'lecture_ecriture'];

    public function up(): void
    {
        if ($this->surMysql()) {
            DB::statement(sprintf(
                "ALTER TABLE delegations MODIFY droits ENUM(%s) NOT NULL DEFAULT 'qr_generation'",
                $this->enumSql(self::DROITS)
            ));

            return;
        }

        // SQLite (suite de tests) : la liste autorisée est figée dans une contrainte CHECK du
        // CREATE TABLE. Laravel reconstruit la table pour appliquer le changement.
        Schema::table('delegations', function (Blueprint $table) {
            $table->enum('droits', self::DROITS)->default('qr_generation')->change();
        });
    }

    public function down(): void
    {
        // Une ligne portant un droit qui n'existera plus violerait l'énumération restaurée :
        // on la ramène au droit historique avant de rétrécir la colonne.
        DB::table('delegations')
            ->whereIn('droits', ['lecture', 'lecture_ecriture'])
            ->update(['droits' => 'qr_generation']);

        if ($this->surMysql()) {
            DB::statement(
                "ALTER TABLE delegations MODIFY droits ENUM('qr_generation') NOT NULL DEFAULT 'qr_generation'"
            );

            return;
        }

        Schema::table('delegations', function (Blueprint $table) {
            $table->enum('droits', ['qr_generation'])->default('qr_generation')->change();
        });
    }

    private function surMysql(): bool
    {
        return DB::connection()->getDriverName() === 'mysql';
    }

    /** @param array<int, string> $valeurs */
    private function enumSql(array $valeurs): string
    {
        return implode(',', array_map(fn (string $v) => "'".$v."'", $valeurs));
    }
};
