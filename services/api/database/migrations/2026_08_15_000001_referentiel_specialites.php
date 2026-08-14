<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P6.8a — Référentiel national des spécialités médicales (CDC_09 §8, étape 8 du §14).
 *
 * ═══ LE PROBLÈME QUE CETTE TABLE FERME ═══
 *
 * La spécialité vivait à CINQ endroits, dans TROIS vocabulaires incompatibles :
 *
 *   1. `symptomes.specialite_hint`            — libellés : « ORL (Oto-Rhino-Laryngologie) » ;
 *   2. `services_etablissement.specialite`    — codes    : « orl » ;
 *   3. `medecins.specialite`                  — libellé libre saisi au portail ;
 *   4. `apps/mobile/src/api/donSang.ts`       — la constante `don_sang`, EN DUR DANS LE CLIENT ;
 *   5. `structures_sanitaires.specialites_json` — colonne morte (constat J4 de P6.4d).
 *
 * Deux d'entre eux devaient se rencontrer et ne le pouvaient pas : le triage produit la forme (1),
 * l'annuaire compare en ÉGALITÉ EXACTE contre la forme (2). Le « matching triage (F1.5) » que trois
 * commentaires de migration promettent depuis le Module 3 n'a donc jamais pu fonctionner — ces
 * commentaires sont corrigés en même temps que ce code.
 *
 * ═══ LES CODES SONT ADOPTÉS, JAMAIS RÉINVENTÉS ═══
 *
 * `services_etablissement.specialite` contient déjà `orl`, `cardiologie`, `medecine_generale`…
 * Ces valeurs SONT le vocabulaire de fait. Les promouvoir en codes canoniques rend la bascule
 * additive (ADR-024) et laisse intacts le contrat `?specialite=orl` de P3 — validé G5 — et la
 * constante du mobile, qui devient une valeur VÉRIFIÉE au lieu d'une valeur ESPÉRÉE. Inventer de
 * nouveaux codes aurait cassé les deux.
 *
 * ═══ PAS D'IDENTIFIANT NATIONAL GÉNÉRÉ, ET C'EST RAISONNÉ ═══
 *
 * `ETS`, `PRO`, `MED`, `ANA` numérotent des INSTANCES : cet hôpital-ci, ce médicament-là. Une
 * spécialité est un TERME DE NOMENCLATURE — même nature que `regions.code` et
 * `districts_sanitaires.code` de P6.4a, qui portent un code littéral et aucun numéro. Ajouter un
 * `SPE000001` par symétrie créerait un second identifiant que personne n'emploierait, et
 * contredirait l'adoption ci-dessus. La symétrie décorative a déjà été refusée en P6.4a.
 *
 * ═══ `nature` : LA COLONNE QUI DIT LA VÉRITÉ SUR CE VOCABULAIRE ═══
 *
 * `services_etablissement.specialite` a toujours mélangé deux choses : des spécialités médicales
 * (`cardiologie`, `orl`) et des ACTIVITÉS de service (`pharmacie`, `biologie`, `don_sang`,
 * `urgences`). Le code le savait déjà sans le dire : `StructureSanitaireSeeder` porte une constante
 * `SPECIALITES_SANS_MEDECIN = ['pharmacie', 'biologie']` — une exception codée en dur, qui est le
 * symptôme du mélange.
 *
 * Le §8 demande les « spécialités médicales RECONNUES ». Ranger `don_sang` parmi elles ferait dire
 * au référentiel national que la collecte de sang est une spécialité médicale : ce serait faux.
 * `nature` distingue donc les deux SANS scinder le vocabulaire — elles partagent un espace de codes
 * (une même colonne les porte), et deux tables auraient rendu cette colonne insoluble.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('specialites_medicales', function (Blueprint $table) {
            $table->id();

            // Le pays QUALIFIE, il ne s'écrit pas dans le code (décision P6.4a) : `cardiologie`
            // reste `cardiologie` d'un pays à l'autre, et l'unicité porte sur le couple.
            $table->string('pays_code', 2)->default('CI');
            $table->string('code', 60);

            $table->string('libelle', 120);

            // Ce qui est une spécialité médicale, et ce qui est une activité de service. Voir plus
            // haut : la distinction n'est pas cosmétique, elle empêche le référentiel d'affirmer
            // quelque chose de faux.
            $table->enum('nature', ['specialite_medicale', 'activite'])->default('specialite_medicale');

            // La profession qui l'exerce (§5.1, source unique `App\Support\ProfessionsSante`).
            // NULLABLE : une activité de service n'est exercée par aucune profession en propre, et
            // une valeur inventée serait fausse plutôt que manquante (précédent P6.4a).
            $table->string('profession', 40)->nullable();

            $table->string('description', 255)->nullable();

            // Ordre d'affichage métier, comme `villes.ordre` (P6.4b) : un sélecteur de spécialité
            // trié alphabétiquement mettrait « biologie » avant « urgences ».
            $table->unsignedSmallInteger('ordre')->default(100);

            // On DÉSACTIVE, on ne supprime pas : des services et des fiches de praticiens la
            // référencent, et l'historique doit rester lisible.
            $table->boolean('actif')->default(true);

            $table->timestamps();

            $table->unique(['pays_code', 'code'], 'uq_specialite_pays_code');
            $table->index(['actif', 'nature']);
        });

        // ── Les deux consommateurs ───────────────────────────────────────────────────────────────
        //
        // Les colonnes `specialite` EXISTANTES SONT CONSERVÉES dans les deux tables. Ce n'est pas de
        // la timidité : `services_etablissement.specialite` porte le contrat `?specialite=orl` de P3
        // et `medecins.specialite` est sérialisée par l'annuaire de P3/P4 — deux modules validés G5.
        // La colonne reste la valeur lue ; ce qui change, c'est que plus personne ne peut y écrire
        // n'importe quoi.
        Schema::table('services_etablissement', function (Blueprint $table) {
            $table->foreignId('specialite_id')->nullable()->after('specialite')
                ->constrained('specialites_medicales')->nullOnDelete();
        });

        Schema::table('medecins', function (Blueprint $table) {
            $table->foreignId('specialite_id')->nullable()->after('specialite')
                ->constrained('specialites_medicales')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('medecins', function (Blueprint $table) {
            $table->dropConstrainedForeignId('specialite_id');
        });

        Schema::table('services_etablissement', function (Blueprint $table) {
            $table->dropConstrainedForeignId('specialite_id');
        });

        Schema::dropIfExists('specialites_medicales');
    }
};
