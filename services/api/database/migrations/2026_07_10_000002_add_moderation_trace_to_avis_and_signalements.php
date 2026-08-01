<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Module 4 / 4.6 — Traçabilité des décisions de modération (CdC §5.4.2).
 *
 * Le schéma du CdC §8.6 porte l'ÉTAT de modération (`visible`, `statut`, `visible_publiquement`)
 * mais pas la DÉCISION qui l'a produit. Or masquer un avis public, ou publier une accusation de
 * pot-de-vin sur la fiche d'un hôpital, doit rester imputable et justifié : on ajoute donc le
 * modérateur, l'instant et le motif — comme le motif de refus d'un RDV (4.4).
 *
 * `modere_par_user_id` : FK vers `users` (l'admin agit depuis le guard web), `nullOnDelete` afin
 * qu'un compte supprimé n'efface pas la décision elle-même.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['avis', 'signalements'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->foreignId('modere_par_user_id')->nullable()->constrained('users')->nullOnDelete();
                $t->timestamp('modere_at')->nullable();
                $t->string('motif_moderation', 500)->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (['avis', 'signalements'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->dropConstrainedForeignId('modere_par_user_id');
                $t->dropColumn(['modere_at', 'motif_moderation']);
            });
        }
    }
};
