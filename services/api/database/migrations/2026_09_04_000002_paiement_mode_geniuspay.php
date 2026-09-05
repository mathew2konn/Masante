<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * B4-b (ADR-056 §9) — `paiements.mode` gagne `geniuspay`.
 *
 * ═══ TROUVÉ AU G3, PAS AU G1 ═══ `Paiement::MODES` (constante applicative) avait été étendue,
 * mais la colonne, elle, reste un ENUM au niveau du MOTEUR (`$table->enum('mode', [...])`,
 * migration 2026-07-08) — un CHECK sous SQLite, un vrai ENUM sous MySQL. Le premier test qui a
 * réellement inséré un `Paiement` en mode `geniuspay` a levé `SQLSTATE[23000]` : la garde du
 * moteur, plus stricte que la constante applicative qu'elle est censée refléter, refusait
 * l'insertion. Exactement le défaut qu'ADR-021/P6.4a nomment ailleurs sous une autre forme —
 * **l'ENUM EST ÉLARGI, JAMAIS RÉÉCRIT** (patron `triages.niveau`, P10b-1) : les trois valeurs
 * simulées restent, `geniuspay` s'ajoute — aucun `Paiement` historique ne change de sens.
 */
return new class extends Migration
{
    private const MODES = ['mobile_money', 'especes', 'carte', 'geniuspay'];

    public function up(): void
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement(sprintf(
                'ALTER TABLE paiements MODIFY mode ENUM(%s) NOT NULL',
                implode(',', array_map(static fn (string $v): string => "'".$v."'", self::MODES))
            ));

            return;
        }

        Schema::table('paiements', function (Blueprint $table) {
            $table->enum('mode', self::MODES)->change();
        });
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE paiements MODIFY mode ENUM('mobile_money','especes','carte') NOT NULL");

            return;
        }

        Schema::table('paiements', function (Blueprint $table) {
            $table->enum('mode', ['mobile_money', 'especes', 'carte'])->change();
        });
    }
};
