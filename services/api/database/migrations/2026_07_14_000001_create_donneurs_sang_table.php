<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Module 5 / 5.7 — Donneurs volontaires (CdC FN6, « s'inscrire comme donneur volontaire »).
 *
 * Le CdC ne fournit AUCUNE table pour FN6 : le schéma est à concevoir. On rattache le donneur au
 * MEMBRE du carnet, et non au compte : c'est le membre qui porte `groupe_sanguin` (indispensable au
 * matching) et `date_naissance` (donc l'éligibilité par l'âge). Le compte titulaire, lui, reste le
 * canal de contact (téléphone) et donne la commune de ciblage — exactement comme les alertes
 * épidémiques (FN3).
 *
 * L'inscription est un CONSENTEMENT : elle se donne membre par membre, et se retire d'un geste
 * (`disponible = false` — on ne supprime pas la ligne, pour garder la date du dernier don, qui
 * conditionne l'éligibilité au prochain).
 *
 * Aucune donnée nouvelle n'est créée ici : le groupe sanguin existait déjà au carnet. Ce qui change,
 * c'est qu'il devient interrogeable POUR ALERTER — d'où le consentement explicite.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('donneurs_sang', function (Blueprint $table) {
            $table->id();

            // Un membre est donneur ou ne l'est pas : une seule ligne par membre.
            $table->foreignId('membre_id')->unique()->constrained('membres_famille')->cascadeOnDelete();

            $table->timestamp('inscrit_at');
            // Retrait du consentement : la ligne demeure (le dernier don doit rester connu).
            $table->boolean('disponible')->default(true);

            // Date du dernier don déclaré : impose le délai de carence avant le suivant.
            $table->date('dernier_don_at')->nullable();

            $table->timestamps();

            $table->index('disponible');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('donneurs_sang');
    }
};
