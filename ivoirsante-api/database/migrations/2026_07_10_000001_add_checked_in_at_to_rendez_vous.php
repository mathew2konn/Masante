<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Module 4 / 4.5 — Check-in à l'accueil (Analyse_Delta_RDV N6).
 *
 * Marque l'arrivée PHYSIQUE du patient, distincte de `confirme` (validé à l'avance, 4.4) et de
 * `honore` (constaté après la visite). Le doc recommande explicitement un champ horodaté plutôt
 * qu'une valeur d'énumération supplémentaire, afin de conserver l'historique : un RDV reste
 * `confirme` et porte en plus l'heure de son enregistrement à l'accueil.
 *
 * `checked_in_by_agent_id` : nullable sans FK, comme `acces_dossier.agent_id` (l'agent est un
 * `users` du guard web ; on trace sans contraindre la suppression du compte).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rendez_vous', function (Blueprint $table) {
            $table->timestamp('checked_in_at')->nullable()->after('statut');
            $table->unsignedBigInteger('checked_in_by_agent_id')->nullable()->after('checked_in_at');
        });
    }

    public function down(): void
    {
        Schema::table('rendez_vous', function (Blueprint $table) {
            $table->dropColumn(['checked_in_at', 'checked_in_by_agent_id']);
        });
    }
};
