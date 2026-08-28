<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P10c-2-i (F10) — Traçabilité de l'appel IA (CDC_05 §9.2 ; CDC_04 §115/§123).
 *
 * Migration STRICTEMENT ADDITIVE. Noms adoptés du §123 (`predictions_ia`) et du §115
 * (`triages.modele_version`) — principe P6.8a, on ne réinvente pas un nom que le corpus a déjà
 * choisi.
 *
 * ═══ CETTE TABLE EST VIDE DE CONTENU CLINIQUE AUJOURD'HUI, ET C'EST DIT AVANT DE CODER ═══
 *
 * Le plan G1 (D5, puis F10) l'annonçait : le jour où l'explication existera, cette table portera
 * du contenu clinique (une explication SHAP nomme les valeurs qui l'ont produite), et elle
 * rejoindra alors le régime de `protocole_applications` — la seule chaîne du projet qui porte du
 * clinique, parce que le §10 l'exige.
 *
 * Ce jour n'est pas aujourd'hui. Tant qu'aucun modèle n'existe (F5/F6 : `/api/v1/triage/score`
 * répond 503 à chaque appel), chaque ligne ne porte qu'un refus honnête — aucune donnée clinique.
 * Bâtir dès maintenant l'empreinte/chaînage/déclencheurs d'un journal d'audit pour une table qui ne
 * contiendra rien à protéger serait le socle à vide que P6.3-D3 a refusé : le durcissement complet
 * (chaîne hachée, anti-substitution) est donc **différé à P10c-3**, quand un modèle réel écrira la
 * première explication — et c'est une limite annoncée, pas un oubli.
 *
 * ═══ `triage_id` : IDENTIFIANT, PAS RELATION VIVANTE (ADR-042 D1, même motif que F4) ═══
 *
 * ═══ `mode` PORTE DÉJÀ SES DEUX VALEURS, ALORS QU'UNE SEULE EST ATTEIGNABLE AUJOURD'HUI ═══
 *
 * Le contrat existe avant la librairie (F5) : `hybride` reste inatteignable tant que P10c-3 n'a pas
 * livré de modèle, mais le placer dans l'ENUM maintenant évite d'avoir à faire migrer une donnée de
 * production le jour où il le devient.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('triages', function (Blueprint $table): void {
            // Nullable et JAMAIS rétroactive : les triages antérieurs n'ont été vus par aucun
            // modèle, leur en attribuer un serait un mensonge d'archive (précédent L2,
            // `protocole_code` de P10b-1).
            $table->string('modele_version', 60)->nullable()->after('protocole_version');
        });

        Schema::create('predictions_ia', function (Blueprint $table): void {
            $table->id();

            $table->unsignedBigInteger('triage_id')
                ->comment('Identifiant, pas une relation vivante (ADR-042 D1).');
            $table->index('triage_id', 'idx_predictions_ia_triage');

            $table->string('modele_version', 60)->nullable();
            $table->enum('mode', ['hybride', 'degrade']);

            // Renseigné seulement en mode degrade (F6) : le motif que Laravel a reçu du service,
            // ou celui du disjoncteur quand l'appel n'est même pas parti (F8).
            $table->string('motif_degradation', 255)->nullable();

            $table->unsignedInteger('latence_ms')->nullable();

            // Inatteignables tant qu'aucun modèle n'existe (F5) — voir l'en-tête sur le
            // durcissement différé à P10c-3.
            $table->decimal('probabilite', 5, 4)->nullable();
            $table->json('facteurs_json')->nullable();
            $table->json('explication_json')->nullable();
            $table->string('confiance', 20)->nullable();
            $table->text('limites')->nullable();

            $table->timestamp('cree_le')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('predictions_ia');

        Schema::table('triages', function (Blueprint $table): void {
            $table->dropColumn('modele_version');
        });
    }
};
