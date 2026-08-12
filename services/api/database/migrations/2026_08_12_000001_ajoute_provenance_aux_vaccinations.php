<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Carnet familial partagé / D0 — provenance sur `vaccinations`.
 *
 * POURQUOI CETTE TABLE ET PAS UNE AUTRE. La migration F2.13 (Module 2) avait ajouté
 * `source ENUM('patient','medecin','structure')` à `antecedents`, `ordonnances` et
 * `resultats_analyses` — mais pas à `vaccinations`, qui n'a jamais eu non plus d'`added_by`.
 *
 * Le G0 de D2 a montré ce que cela coûte : l'incrément C force bien `source = patient` sur toute
 * contribution, mais sur cette table Eloquent écarte silencieusement les deux clés (absentes de
 * `$fillable`). La garantie d'origine y est donc **sans effet** — pas fausse, inexistante. Un
 * vaccin inscrit par un proche est aujourd'hui indistinguable d'un vaccin inscrit par un médecin.
 *
 * D0 ouvre l'écriture soignant sur cette section (décision propriétaire au G1 : un médecin vaccine,
 * et c'est souvent la première chose écrite dans le carnet d'un enfant). Sans ces deux colonnes,
 * la fiche de parcours de D2 ne pourrait pas dire d'où vient la ligne.
 *
 * STRICTEMENT ADDITIVE : `source` par défaut `patient`, `added_by` nullable. Aucune ligne existante
 * ne change de sens — ce qui était auto-déclaré reste auto-déclaré.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vaccinations', function (Blueprint $table) {
            // MÊME TYPE que sur `antecedents`, `ordonnances` et `resultats_analyses` : un ENUM à
            // deux valeurs, pas une chaîne libre. `added_by` dit une CATÉGORIE d'auteur, jamais
            // une identité — celle-ci vit dans le journal d'audit (`acces_dossier.agent_id`), qui
            // est immuable. Introduire ici un troisième type de colonne pour la même notion
            // n'aurait servi qu'à créer une divergence de plus.
            $table->enum('added_by', ['patient', 'medecin'])->default('patient')->after('medecin_nom');
            $table->enum('source', ['patient', 'medecin', 'structure'])
                ->default('patient')
                ->after('added_by');
        });
    }

    public function down(): void
    {
        Schema::table('vaccinations', function (Blueprint $table) {
            $table->dropColumn(['added_by', 'source']);
        });
    }
};
