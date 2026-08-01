<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Module 3 / N1 (Analyse_Delta_RDV) — Table `paiements` du flux RDV.
 *
 * ⚠️ PAIEMENT SIMULÉ. Aucune passerelle Mobile Money réelle (Orange/MTN/Moov) n'est accessible à
 * ce projet (cf. FT5 « simulé en dev » + limite CNAM du doc CMU). On MODÉLISE l'encaissement :
 * le statut passe directement à `paye` et `transaction_ref` est factice. Ne jamais présenter ceci
 * comme un règlement réel. Le rôle Caisse (N7) et l'encaissement agent relèvent du Module 4.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('paiements', function (Blueprint $table) {
            $table->id();

            $table->foreignId('rendez_vous_id')->constrained('rendez_vous')->cascadeOnDelete();

            $table->unsignedInteger('montant');                                 // FCFA
            $table->enum('mode', ['mobile_money', 'especes', 'carte']);
            $table->enum('statut', ['en_attente', 'paye', 'echoue'])->default('paye'); // simulé
            $table->string('transaction_ref', 64)->nullable();                  // factice (simulé)

            $table->timestamps();

            $table->index('rendez_vous_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('paiements');
    }
};
