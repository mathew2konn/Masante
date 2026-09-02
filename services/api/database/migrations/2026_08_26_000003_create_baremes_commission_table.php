<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Facturation partenaire — barème de commission par palier de volume (lot A, migration 3/8).
 *
 * Le taux appliqué à une transaction dépend du volume mensuel déjà réalisé par l'établissement :
 * plus il encaisse, moins il paie. Les paliers sont des DONNÉES, jamais un `if` — changer un taux
 * ne doit pas demander un déploiement.
 *
 * TAUX EN POINTS DE BASE ENTIERS : 250 = 2,50 %. Un pourcentage en flottant appliqué à un montant
 * produit des arrondis qui divergent d'une machine à l'autre ; sur une commission, cet écart se
 * retrouve dans une facture partenaire et se discute.
 *
 * UN BARÈME NE SE MODIFIE JAMAIS EN PLACE. On ferme la ligne courante (`date_fin`) et on en insère
 * une nouvelle. C'est ce qui permet de rejouer une commission de l'an dernier avec le barème de
 * l'an dernier — `commissions_transaction` conserve d'ailleurs le taux appliqué, précisément pour
 * n'avoir jamais à relire cette table pour un calcul passé.
 *
 * `volume_mensuel_max` nullable = dernier palier, sans plafond.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('baremes_commission', function (Blueprint $table) {
            $table->id();

            $table->unsignedTinyInteger('palier_ordre');
            $table->unsignedBigInteger('volume_mensuel_min');            // FCFA entiers
            $table->unsignedBigInteger('volume_mensuel_max')->nullable(); // null = pas de plafond
            $table->unsignedSmallInteger('taux_bps');                     // 250 = 2,50 %

            $table->date('date_effet');
            $table->date('date_fin')->nullable();

            $table->timestamps();

            $table->index(['date_effet', 'date_fin']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('baremes_commission');
    }
};
