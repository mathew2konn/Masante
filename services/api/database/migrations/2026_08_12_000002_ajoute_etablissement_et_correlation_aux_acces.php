<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Carnet familial partagé / incrément D2 — ce qu'il manque au journal pour raconter un parcours.
 *
 * Le G0 de D2 a trouvé deux manques dans `acces_dossier`, tous deux invisibles tant que personne
 * ne lisait le journal — c'est-à-dire jusqu'à la fiche de parcours.
 *
 * 1. AUCUN ÉTABLISSEMENT. « Dans quel hôpital ? » est pourtant la première question d'un parent
 *    prévenu qu'on a ouvert le dossier de son enfant. Le nom n'existait que sur
 *    `tokens_qr.used_by_etablissement` — donc sur la seule voie du scan, et jamais affiché nulle
 *    part depuis le Module 2.
 *
 *    POURQUOI UNE COPIE ET NON UNE CLÉ ÉTRANGÈRE. Le nom est figé au moment de l'accès. Le déduire
 *    de `users.structure_id` ferait changer d'établissement toutes les visites passées d'un agent
 *    qui change d'hôpital : un journal qui se réécrit ne prouve rien. Même raison que
 *    `alertes_sos`, qui dénormalise déjà le contact prévenu « pour que la trace reste exacte même
 *    si le contact est modifié ensuite ».
 *
 * 2. AUCUN LIEN ENTRE L'OUVERTURE ET LA CLÔTURE. Une consultation s'écrit en deux lignes (le
 *    journal est immuable, on ne complète pas après coup — §10.2). Elles se retrouvaient par
 *    `token_qr_id`… qui est NULL en accès référent et en accès d'urgence vitale. Les rapprocher
 *    par proximité horaire aurait été une DEVINETTE ; ce module ne devine pas. La clôture désigne
 *    désormais son ouverture — `SessionDossierService::fermer()` détient déjà cet identifiant.
 *
 * ADDITIVE ET RÉTROCOMPATIBLE : les deux colonnes sont nullables et les lignes déjà écrites
 * restent telles quelles. La fiche affichera « établissement non enregistré » plutôt que
 * d'inventer un nom. C'est une limite datée, pas un mensonge.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('acces_dossier', function (Blueprint $table) {
            // Longueur alignée sur `tokens_qr.used_by_etablissement` (200) : même donnée.
            $table->string('etablissement', 200)->nullable()->after('motif_urgence');

            // `nullOnDelete` par principe : le journal est immuable, mais si une ligne
            // d'ouverture venait à disparaître, la clôture doit rester lisible plutôt que
            // d'empêcher l'opération.
            $table->foreignId('acces_ouverture_id')
                ->nullable()
                ->after('etablissement')
                ->constrained('acces_dossier')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('acces_dossier', function (Blueprint $table) {
            $table->dropConstrainedForeignId('acces_ouverture_id');
            $table->dropColumn('etablissement');
        });
    }
};
