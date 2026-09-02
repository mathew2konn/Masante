<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Facturation patient — l'établissement facture le patient (lot B, migration 6/8).
 *
 * CETTE TABLE FAIT FOI POUR LE RÈGLEMENT D'UN ACTE.
 * Le projet porte déjà une table `paiements`, née avec le flux rendez-vous du Module 3 : son
 * encaissement est SIMULÉ (statut « payé » d'office, `transaction_ref` factice). Deux réponses
 * possibles à « cet acte a-t-il été réglé ? » est un piège à retardement — d'où la décision :
 * `factures_patient` est la SEULE SOURCE DE VÉRITÉ, `paiements` devient un vestige qu'on
 * n'interroge plus pour cette question. Ce lot ne la modifie pas et ne migre rien.
 *
 * `rendez_vous_id` est le POINT D'ATTERRISSAGE de cette reprise, posé aujourd'hui pour qu'elle
 * n'exige pas plus tard une migration structurelle sur une table déjà remplie. Il est nullable :
 * une facture peut naître d'un acte sans rendez-vous (passage aux urgences, achat en officine).
 * Personne ne l'écrit encore.
 *
 * `beneficiaire_id` nullable : le titulaire du compte paie, mais l'acte peut concerner un membre de
 * sa famille (carnet familial). Distinguer les deux est nécessaire — le payeur n'est pas toujours
 * le soigné, et c'est la règle plutôt que l'exception ici.
 *
 * `paiement_en_ligne_autorise` : sous le plancher (5 000 FCFA, R17), le paiement en ligne est
 * refusé — les frais de passerelle mangeraient la transaction. Le règlement sur place reste
 * possible : ce n'est pas une facture bloquée, c'est un canal fermé.
 *
 * `relance_envoyee_le` : UNE SEULE relance, jamais deux (R18). L'horodatage EST le garde-fou —
 * un compteur autoriserait la deuxième par simple oubli de le lire.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('factures_patient', function (Blueprint $table) {
            $table->id();

            $table->foreignId('structure_sanitaire_id')->constrained('structures_sanitaires')->restrictOnDelete();

            // Titulaire du compte : celui qui doit l'argent.
            $table->foreignId('patient_id')->constrained('users')->restrictOnDelete();

            // Bénéficiaire de l'acte, s'il diffère du titulaire (carnet familial).
            $table->foreignId('beneficiaire_id')->nullable()->constrained('membres_famille')->restrictOnDelete();

            // Point d'atterrissage de la reprise du flux RDV (voir en-tête). Non utilisé par ce lot.
            $table->foreignId('rendez_vous_id')->nullable()->constrained('rendez_vous')->restrictOnDelete();

            $table->string('reference')->unique();

            $table->string('moment_paiement');   // AVANT_ACTE | APRES_ACTE

            $table->unsignedBigInteger('montant_brut');
            $table->unsignedBigInteger('montant_pris_en_charge_cmu')->default(0);
            $table->unsignedBigInteger('montant_reste_a_charge');
            $table->string('devise', 3)->default('XOF');

            // A_REGLER | PAYEE | PRISE_EN_CHARGE_TOTALE | ANNULEE | EXPIREE
            $table->string('statut');

            $table->boolean('paiement_en_ligne_autorise')->default(true);

            $table->timestamp('date_emission');
            $table->date('date_echeance')->nullable();
            $table->timestamp('date_reglement')->nullable();
            $table->timestamp('relance_envoyee_le')->nullable();

            $table->timestamps();

            $table->index(['patient_id', 'statut']);
            $table->index(['structure_sanitaire_id', 'date_emission']);
            $table->index('rendez_vous_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('factures_patient');
    }
};
