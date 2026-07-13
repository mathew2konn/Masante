<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Module 5 / 5.6 — Référentiel des seuils de mesure (FN5 « alertes si valeurs anormales »).
 *
 * Les seuils médicaux vivent EN BASE, comme les règles du triage (F1.3, table `symptomes`) et le
 * calendrier prénatal (5.5, `etapes_prenatales`) : un médecin peut les corriger par un UPDATE, sans
 * redéployer. C'est le pattern imposé par le CdC pour toute règle médicale.
 *
 * Deux couples de bornes, à ne pas confondre :
 *  - `valeur_min` / `valeur_max` : PLAUSIBILITÉ de la saisie. Une glycémie de 500 g/L est une faute
 *    de frappe, pas une urgence : elle est refusée à la validation, jamais enregistrée.
 *  - `normal_min` / `normal_max` + `critique_bas` / `critique_haut` : NORMALITÉ clinique, qui donne
 *    le `statut_norme` (normal / bas / eleve / critique) calculé côté serveur.
 *
 * `conseil_anormal` porte le message montré au patient hors norme — contenu médical, donc en base
 * lui aussi. Le mobile ne fait qu'afficher.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('referentiels_mesure', function (Blueprint $table) {
            $table->id();

            // Les 7 types du CdC §8.3 (table `mesures_sante`). Clé de mise à jour du seeder.
            $table->enum('type_mesure', [
                'glycemie',
                'tension_systolique',
                'tension_diastolique',
                'poids',
                'temperature',
                'pouls',
                'saturation_o2',
            ])->unique();

            $table->string('libelle', 100);
            $table->string('unite', 20);

            // Plausibilité de saisie (garde-fou anti faute de frappe).
            $table->decimal('valeur_min', 8, 2);
            $table->decimal('valeur_max', 8, 2);

            // Normalité clinique.
            $table->decimal('normal_min', 8, 2);
            $table->decimal('normal_max', 8, 2);
            // Seuils d'alerte. NULL = pas de critique de ce côté (ex. une saturation ne peut pas
            // être « critiquement haute » ; un poids n'a pas de seuil d'urgence universel).
            $table->decimal('critique_bas', 8, 2)->nullable();
            $table->decimal('critique_haut', 8, 2)->nullable();

            // Nombre de décimales à afficher/saisir (0 pour un pouls, 2 pour une glycémie).
            $table->unsignedTinyInteger('decimales')->default(0);
            $table->unsignedTinyInteger('ordre')->default(0);

            $table->text('conseil_anormal');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('referentiels_mesure');
    }
};
