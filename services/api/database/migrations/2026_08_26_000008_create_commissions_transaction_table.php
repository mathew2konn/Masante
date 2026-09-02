<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Facturation partenaire — commission prélevée sur une transaction patient (lot A, migration 8/8).
 *
 * ⚠️ CETTE MIGRATION EST VOLONTAIREMENT LA DERNIÈRE DU LOT, ET CE N'EST PAS UN CLASSEMENT ARBITRAIRE.
 * Elle référence `factures_patient` (lot B, migration 6) ET `factures_partenaire` (lot A,
 * migration 4). Une clé étrangère exige que sa cible existe déjà : la placer avec le reste du lot A
 * la ferait pointer vers deux tables non encore créées et `migrate` échouerait sur `errno 150`.
 * `--pretend` ne l'aurait pas vu — il n'exécute rien.
 * NE LA « REMETS PAS À SA PLACE ». Son appartenance logique est le lot A ; seul son ordre
 * d'EXÉCUTION est contraint.
 *
 * L'ÉGALITÉ QUI FAIT LE REÇU TRANSPARENT :
 *   montant_brut = frais_passerelle + frais_prestataire + montant_commission + montant_net_structure
 * Un test la vérifie. C'est elle qu'on montre à un partenaire qui demande où est passé son argent :
 * chaque franc entre l'encaissement et son net est nommé.
 *
 * `taux_bps_applique` et `volume_cumule_au_calcul` sont FIGÉS à l'instant du calcul. On ne
 * recalcule jamais une commission passée à partir du barème courant : le barème a pu changer, le
 * volume du mois a continué de monter, et la commission cesserait d'être reproductible.
 *
 * `frais_prestataire` est le montant RÉEL restitué par le microservice de paiement — jamais une
 * reconstitution locale du type « 100 F + 1 % ». Reconstituer produirait des écarts au franc que
 * personne ne saurait expliquer, et casserait précisément le reçu transparent ci-dessus.
 *
 * `reference_interne_paiement` PORTE L'IDEMPOTENCE de la notification venue du microservice Java
 * (format `MS-{structure}-{ULID}`). Sa contrainte UNIQUE est le garde-fou : un webhook rejoué, une
 * relance réseau ou un renvoi du prestataire ne doivent pas créer une seconde commission sur le
 * même encaissement. Ne la retire sous aucun prétexte.
 *
 * NOTE D'ÉVOLUTION, à ne PAS implémenter aujourd'hui : si GeniusPay ouvre un jour le paiement
 * fractionné, `statut` gagnera la valeur `PRELEVEE_A_LA_SOURCE` et la commission cessera d'être
 * portée par la facture partenaire. Cette valeur n'est pas créée maintenant — aucun code ne la
 * produirait, et un état que rien n'atteint est un état qu'on finit par croire atteignable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commissions_transaction', function (Blueprint $table) {
            $table->id();

            $table->foreignId('structure_sanitaire_id')->constrained('structures_sanitaires')->restrictOnDelete();

            // La transaction patient qui a produit cette commission.
            $table->foreignId('facture_patient_id')->nullable()->constrained('factures_patient')->restrictOnDelete();

            // La facture partenaire qui la porte. Nullable : renseignée à la génération mensuelle.
            // Un statut FACTUREE sans cette valeur rendrait tout rapprochement invérifiable.
            $table->foreignId('facture_partenaire_id')->nullable()->constrained('factures_partenaire')->restrictOnDelete();

            $table->string('reference_geniuspay')->nullable()->unique();

            // Clé d'idempotence de la notification du microservice Java (voir en-tête).
            $table->string('reference_interne_paiement')->nullable()->unique();

            $table->unsignedBigInteger('montant_brut');
            $table->unsignedBigInteger('frais_passerelle')->default(0);    // Wave, Orange Money…
            $table->unsignedBigInteger('frais_prestataire')->default(0);   // montant RÉEL restitué

            $table->unsignedSmallInteger('taux_bps_applique');             // figé au calcul
            $table->unsignedBigInteger('volume_cumule_au_calcul');         // figé au calcul

            $table->unsignedBigInteger('montant_commission');
            $table->unsignedBigInteger('montant_net_structure');

            $table->string('devise', 3)->default('XOF');

            $table->string('statut');                                      // CALCULEE | FACTUREE | ANNULEE

            $table->timestamp('date_transaction');

            $table->timestamps();

            // Nom EXPLICITE : le nom auto-généré ferait 69 caractères (limite MySQL : 64, erreur 1059).
            $table->index(['structure_sanitaire_id', 'date_transaction'], 'idx_commission_structure_date');
            $table->index(['statut', 'date_transaction']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commissions_transaction');
    }
};
