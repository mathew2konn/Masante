<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Module 3 / étape 3A.1 — Table `services_etablissement` (CdC §8.4).
 *
 * Services médicaux d'une structure (Médecine générale, ORL, Urgences…).
 * La configuration par les gestionnaires d'établissement relève du Module 4 : ici les
 * services sont seedés et exposés en lecture seule.
 *
 * ═══ CORRECTION P6.8a — CE COMMENTAIRE PROMETTAIT CE QUI N'EXISTAIT PAS ═══
 *
 * Il affirmait que `specialite` « sert au matching avec la spécialité déduite par le triage
 * (F1.5) ». Le G0 de P6.8 a établi que ce rapprochement n'a jamais pu aboutir : le triage produit
 * des LIBELLÉS (« ORL (Oto-Rhino-Laryngologie) »), cette colonne porte des CODES (« orl »), et
 * l'annuaire les compare en égalité exacte. Deux vocabulaires qui ne se rencontrent pas.
 *
 * Ce que P6.8a apporte : la colonne porte désormais un code du VOCABULAIRE NATIONAL
 * (`specialites_medicales`), désigné par `specialite_id`, et le formulaire du portail ne laisse
 * plus taper n'importe quel mot. Le branchement du triage, lui, reste à faire — il appartient à
 * P10, qui refond déjà le triage.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('services_etablissement', function (Blueprint $table) {
            $table->id();

            $table->foreignId('structure_id')->constrained('structures_sanitaires')->cascadeOnDelete();

            $table->string('nom_service', 200);
            $table->string('specialite', 100); // code de spécialité (matching triage)
            $table->boolean('actif')->default(true);

            $table->timestamps();

            $table->index(['structure_id', 'specialite']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('services_etablissement');
    }
};
