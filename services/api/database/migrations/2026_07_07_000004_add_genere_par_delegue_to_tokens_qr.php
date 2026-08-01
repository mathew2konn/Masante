<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase B / B3 — Trace de la génération d'un QR par un délégué (Note_Continuite chap. 4.2 :
 * « chaque QR généré par un délégué est tracé comme tel »).
 *
 * NULL = QR généré par le titulaire lui-même. Renseigné = id du délégué générateur. Permettra,
 * quand la consommation (scan) sera branchée au Module 3, de marquer l'accès `type_acces='delegation'`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tokens_qr', function (Blueprint $table) {
            $table->foreignId('genere_par_delegue_id')
                ->nullable()
                ->after('membre_id')
                ->constrained('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('tokens_qr', function (Blueprint $table) {
            $table->dropConstrainedForeignId('genere_par_delegue_id');
        });
    }
};
