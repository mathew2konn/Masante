<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Module 3 / étape F3.5 — Table `medecins` (praticiens réservables ; Analyse_Delta_RDV N5).
 *
 * Annuaire professionnel public (données NON sensibles : nom, spécialité, tarif indicatif) —
 * aucun chiffrement, cohérent avec `structures_sanitaires` / `services_etablissement`. Un médecin
 * est rattaché à UN service (cascade naturelle « service choisi → médecins de ce service »). Le
 * `tarif_consultation` est purement indicatif à l'affichage : AUCUNE logique de paiement (le bloc
 * Mobile Money FT5/N1 reste hors périmètre du prototype). En production ces données seront
 * alimentées par le portail admin (Module 4) ; ici elles sont seedées.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('medecins', function (Blueprint $table) {
            $table->id();

            $table->foreignId('structure_id')->constrained('structures_sanitaires')->cascadeOnDelete();
            $table->foreignId('service_id')->constrained('services_etablissement')->cascadeOnDelete();

            $table->string('titre', 20)->default('Dr'); // Dr / Pr
            $table->string('nom', 120);
            $table->string('prenom', 120);
            $table->string('specialite', 100);                 // libellé affiché
            $table->unsignedInteger('tarif_consultation')->nullable(); // FCFA, indicatif (pas de paiement)
            $table->boolean('actif')->default(true);

            $table->timestamps();

            $table->index(['service_id', 'actif']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('medecins');
    }
};
