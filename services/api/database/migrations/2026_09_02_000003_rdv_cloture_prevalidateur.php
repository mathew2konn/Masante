<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * B1-d — Clôture du rendez-vous (D10) + prévalidateur distinct du check-in (D11).
 *
 * `prevalide_par_agent_id` : jusqu'ici, RIEN ne capturait qui avait pré-validé un RDV — seul le
 * check-in (`checked_in_by_agent_id`, Module 4) était tracé. Distinct à dessein : le prévalidateur
 * et l'agent qui enregistre l'arrivée physique ne sont pas forcément la même personne, et les
 * confondre priverait la traçabilité de la moitié de son sens. Même style que
 * `checked_in_by_agent_id` — nullable, SANS clé étrangère (l'agent est un `User`, motif
 * `acces_dossier.agent_id` : le journal ne doit pas se casser si un compte est supprimé).
 *
 * `termine_le`/`termine_par_agent_id` : le RDV COMPLET (`honore`) n'avait aucune trace de clôture
 * — `RendezVousValidationService::STATUTS` déclare `honore` depuis B1-a, mais AUCUNE transition ne
 * l'atteint (clé morte, précédent `RendezVousStatut` de B1-a). Cette migration ne fait que poser
 * les colonnes ; `terminer()` (B1-d) est la première à les écrire.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rendez_vous', function (Blueprint $table) {
            $table->unsignedBigInteger('prevalide_par_agent_id')->nullable()->after('checked_in_by_agent_id');
            $table->timestamp('termine_le')->nullable()->after('prevalide_par_agent_id');
            $table->unsignedBigInteger('termine_par_agent_id')->nullable()->after('termine_le');
        });
    }

    public function down(): void
    {
        Schema::table('rendez_vous', function (Blueprint $table) {
            $table->dropColumn(['prevalide_par_agent_id', 'termine_le', 'termine_par_agent_id']);
        });
    }
};
