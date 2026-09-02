<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Facturation partenaire — abonnement d'un établissement à un plan (lot A, migration 2/8).
 *
 * DURÉE D'ESSAI : 30 JOURS POUR TOUS (règle R2 amendée le 26/08/2026). L'offre de lancement à
 * 90 jours pour les vingt premiers partenaires est SUPPRIMÉE.
 *
 * `duree_essai_jours` reste une COLONNE, avec 30 pour défaut : la durée est historisée PAR
 * abonnement. Un changement futur de politique ne doit jamais rallonger ni raccourcir un essai
 * déjà consenti — ce qui arriverait fatalement si la durée vivait dans une constante lue au
 * moment du calcul. Ne code 30 nulle part ailleurs que dans ce défaut.
 *
 * `rang_signature` EST CONSERVÉE, et ce n'est pas un oubli : elle garde l'ordre d'arrivée des
 * partenaires pour l'audit et pour d'éventuelles offres futures. Mais elle NE DÉTERMINE PLUS
 * AUCUNE DURÉE. Quiconque la relie de nouveau à `duree_essai_jours` réintroduit la règle abrogée.
 *
 * `date_bascule_palier0` et `motif_suspension` vivent ICI, sur l'abonnement, et JAMAIS sur
 * `structures_sanitaires`. Deux raisons, toutes deux dirimantes :
 *  1. l'état commercial d'un contrat n'est pas une propriété de l'établissement — il change avec
 *     le contrat, pas avec l'hôpital ;
 *  2. `structures_sanitaires.actif` est le commutateur ADMINISTRATIF (fermeture, fraude, décision
 *     d'un administrateur). Y écrire une sanction d'impayé mêlerait deux décisions qui n'ont ni le
 *     même auteur ni les mêmes conséquences : une structure fermée doit disparaître, une structure
 *     suspendue pour impayé doit RESTER VISIBLE (décision D-E1, Palier 0). Voir
 *     docs/REGLES_RECOUVREMENT_PARTENAIRE.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('abonnements_structure', function (Blueprint $table) {
            $table->id();

            // restrictOnDelete : on ne supprime pas un établissement qui porte un historique
            // financier. La désactivation est le chemin prévu, pas la suppression.
            $table->foreignId('structure_sanitaire_id')->constrained('structures_sanitaires')->restrictOnDelete();
            $table->foreignId('plan_tarifaire_id')->constrained('plans_tarifaires')->restrictOnDelete();

            // Ordre d'arrivée du partenaire, figé. Historique et audit UNIQUEMENT (voir en-tête).
            $table->unsignedInteger('rang_signature');

            // 30 jours pour tous. Historisé par abonnement (voir en-tête).
            $table->unsignedSmallInteger('duree_essai_jours')->default(30);

            $table->date('date_debut');
            $table->date('date_fin_essai');
            $table->date('date_fin')->nullable();

            $table->string('statut');                          // ESSAI | ACTIF | SUSPENDU | RESILIE
            $table->string('motif_suspension')->nullable();    // IMPAYE | DEMANDE_PARTENAIRE | AUTRE
            $table->timestamp('date_bascule_palier0')->nullable();

            $table->timestamps();

            $table->index(['structure_sanitaire_id', 'statut']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('abonnements_structure');
    }
};
