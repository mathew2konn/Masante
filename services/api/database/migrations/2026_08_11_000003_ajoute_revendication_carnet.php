<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Carnet familial partagé / incrément B — revendication d'un carnet.
 *
 * DEUX OBJETS, DEUX RÔLES DISTINCTS :
 *
 * 1. `delegations.est_le_dossier_du_delegue` — l'ASSERTION du responsable au moment du partage :
 *    « ce carnet est celui de la personne que j'invite ». C'est le premier des deux actes humains
 *    sur lesquels repose la revendication (plan G1 §6.2). Sans lui, n'importe quel délégué
 *    pourrait s'approprier n'importe quel carnet qu'on lui a confié — y compris celui d'un enfant.
 *
 * 2. `carnet_transferts` — la TRACE, en ajout seul. Un carnet qui change de propriétaire est un
 *    dossier médical qui change de mains : il faut pouvoir dire qui l'a cédé, à qui, et quand.
 *
 * CE QUI N'EST PAS TOUCHÉ, ET C'EST L'ESSENTIEL : la ligne `membres_famille` garde son `id`. Le
 * NIS, le matricule, le journal du NIS et les dix-neuf tables qui référencent le dossier suivent
 * sans un seul UPDATE. C'est exactement ce qu'une fusion ne pouvait pas offrir.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('delegations', function (Blueprint $table) {
            $table->boolean('est_le_dossier_du_delegue')
                ->default(false)
                ->after('droits');
        });

        Schema::create('carnet_transferts', function (Blueprint $table) {
            $table->id();

            // `nullOnDelete` et non `cascade` : la trace doit survivre à la suppression du dossier
            // ou d'un compte. Un transfert effacé serait un transfert niable.
            $table->foreignId('membre_id')->nullable()
                ->constrained('membres_famille')->nullOnDelete();
            $table->foreignId('ancien_user_id')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->foreignId('nouveau_user_id')->nullable()
                ->constrained('users')->nullOnDelete();

            // La délégation qui portait l'assertion — d'où vient le droit de revendiquer.
            $table->unsignedBigInteger('delegation_id')->nullable();

            $table->string('motif', 40)->default('revendication');

            // Journal en ajout seul : pas de `updated_at`.
            $table->timestamp('created_at')->useCurrent();

            $table->index('membre_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('carnet_transferts');

        Schema::table('delegations', function (Blueprint $table) {
            $table->dropColumn('est_le_dossier_du_delegue');
        });
    }
};
