<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Facturation partenaire — facture mensuelle adressée à l'établissement (lot A, migration 4/8).
 *
 * LA RÈGLE DU SOLDE UNIQUE (décision D-E3), et c'est la raison d'être de cette table.
 * La facture porte UN SEUL montant à payer. `montant_abonnement` et `montant_commissions` sont des
 * colonnes de VENTILATION COMPTABLE — elles disent d'où vient le total, elles ne sont pas deux
 * soldes qu'on pourrait éteindre séparément. Il n'existe aucun moyen de régler l'abonnement en
 * laissant la commission en suspens : le partenaire n'a qu'un total en face de lui. C'est ainsi
 * qu'est mise en œuvre la décision « les deux ou rien ».
 *
 * `montant_regle` N'EST PAS LA SOURCE DE VÉRITÉ. La vérité, ce sont les lignes de
 * `reglements_facture_partenaire` ; cette colonne est un cumul dénormalisé, entretenu pour la
 * lecture. Un test (`test_montant_regle_egale_somme_des_reglements`) vérifie qu'elle ne dérive pas.
 * Si les deux divergent un jour, ce sont les lignes qui ont raison.
 *
 * AUCUNE COLONNE `solde`. Le solde est DÉRIVÉ : `montant_total - montant_regle`. Le stocker
 * créerait une troisième valeur à maintenir, donc une troisième occasion de se contredire.
 *
 * `date_bascule_palier0` n'est PAS ici : la sanction porte sur le contrat, donc sur
 * `abonnements_structure` (voir sa migration et docs/REGLES_RECOUVREMENT_PARTENAIRE.md).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('factures_partenaire', function (Blueprint $table) {
            $table->id();

            $table->foreignId('structure_sanitaire_id')->constrained('structures_sanitaires')->restrictOnDelete();

            $table->string('reference')->unique();

            $table->date('periode_debut');
            $table->date('periode_fin');

            // Ventilation comptable — jamais des soldes indépendants (voir en-tête).
            $table->unsignedBigInteger('montant_abonnement')->default(0);
            $table->unsignedBigInteger('montant_commissions')->default(0);
            $table->unsignedBigInteger('montant_total');

            // Cumul dénormalisé des règlements imputés. Source de vérité = les lignes de règlement.
            $table->unsignedBigInteger('montant_regle')->default(0);

            $table->string('devise', 3)->default('XOF');

            // BROUILLON | EMISE | PARTIELLEMENT_REGLEE | PAYEE | IMPAYEE
            $table->string('statut');

            $table->date('date_emission')->nullable();
            $table->date('date_echeance')->nullable();
            $table->date('date_paiement')->nullable();   // date du règlement qui solde la facture

            $table->timestamps();

            // Une seule facture par établissement et par période : le rejeu d'une génération
            // mensuelle ne doit pas produire un doublon facturé deux fois.
            $table->unique(['structure_sanitaire_id', 'periode_debut', 'periode_fin'], 'uq_facture_partenaire_periode');

            // Sert le balayage du recouvrement : « quelles factures sont échues et non soldées ? »
            $table->index(['statut', 'date_echeance']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('factures_partenaire');
    }
};
