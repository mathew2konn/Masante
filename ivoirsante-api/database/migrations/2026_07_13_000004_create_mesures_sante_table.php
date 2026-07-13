<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Module 5 / 5.6 — Journal de bord des mesures (CdC §8.3 `mesures_sante`, FN5).
 *
 * Schéma du CdC, à deux ajouts près, tous deux justifiés :
 *
 *  - `groupe_uuid` — la tension artérielle est UNE mesure pour le patient (12/8) mais DEUX lignes
 *    dans l'ENUM du CdC (systolique + diastolique). Plutôt que de dénaturer l'ENUM, on garde les
 *    deux lignes et on les relie : elles s'affichent et se suppriment ensemble. NULL pour les
 *    mesures simples.
 *  - `source` / `added_by` — provenance (F2.13), déjà posée sur `antecedents`, `ordonnances` et
 *    `resultats_analyses`. C'est elle qui décide de la suppression : le patient peut effacer SA
 *    saisie erronée, jamais une mesure prise par une structure.
 *
 * `statut_norme` n'est JAMAIS accepté du client : il est calculé par le serveur à partir du
 * référentiel de seuils ({@see App\Services\MesureSanteService}) — même principe que le score de
 * triage. `note` est chiffrée au repos (§6.1 Sécurité) : une note libre sur une glycémie est une
 * donnée médicale. `valeur` reste en clair : c'est ce qui rend possibles les courbes et les seuils.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mesures_sante', function (Blueprint $table) {
            $table->id();

            $table->foreignId('membre_id')->constrained('membres_famille')->cascadeOnDelete();

            // Lie les deux lignes d'une même prise de tension (systolique + diastolique).
            $table->uuid('groupe_uuid')->nullable();

            $table->enum('type_mesure', [
                'glycemie',
                'tension_systolique',
                'tension_diastolique',
                'poids',
                'temperature',
                'pouls',
                'saturation_o2',
            ]);

            $table->decimal('valeur', 8, 2);
            $table->string('unite', 20);
            $table->enum('statut_norme', ['normal', 'eleve', 'bas', 'critique']);
            $table->dateTime('date_mesure');
            $table->text('note')->nullable();          // chiffré AES-256 (cast `encrypted`)

            $table->string('added_by', 120)->nullable();
            $table->enum('source', ['patient', 'medecin', 'structure'])->default('patient');

            $table->timestamps();

            // Courbe d'évolution d'un type de mesure sur une période (l'usage dominant).
            $table->index(['membre_id', 'type_mesure', 'date_mesure']);
            $table->index('groupe_uuid');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mesures_sante');
    }
};
