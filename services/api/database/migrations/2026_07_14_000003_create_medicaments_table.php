<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Module 5 / 5.8 — Catalogue des médicaments (CdC §8, table `medicaments` ; FN7).
 *
 * Schéma du CdC, sans écart. La base initiale est le référentiel CENAME/DPM (prix officiels),
 * seedée — le crowdsourcing des patients ne fait que l'enrichir, il ne le remplace pas.
 *
 * `nom_generique` est la DCI (dénomination commune internationale) : c'est ELLE qui permet de
 * suggérer un générique moins cher (FN7), puisque deux boîtes de marques différentes portant la
 * même DCI contiennent la même molécule.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('medicaments', function (Blueprint $table) {
            $table->id();

            $table->string('nom_generique', 200);            // DCI — la clé du rapprochement générique
            $table->string('nom_commercial', 200)->nullable();
            $table->string('categorie', 100);
            $table->unsignedInteger('prix_reference_cfa')->nullable();   // référence CENAME
            $table->boolean('ordonnance_requise')->default(true);
            $table->boolean('disponible_generique')->default(false);
            $table->string('cename_reference', 50)->nullable();

            $table->timestamps();

            $table->index('nom_generique');
            $table->index('categorie');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('medicaments');
    }
};
