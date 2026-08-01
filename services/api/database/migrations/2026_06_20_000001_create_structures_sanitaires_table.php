<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Module 3 / étape 3A.1 — Table `structures_sanitaires` (CdC §8.4, F3.1/F3.5).
 *
 * Catalogue géolocalisé des structures sanitaires d'Abidjan. Données NON sensibles
 * (annuaire public) : pas de chiffrement ici. Les coordonnées GPS alimentent la carte
 * Google Maps (3B) et le calcul de proximité (Haversine, côté serveur).
 *
 * `note_moyenne` / `nb_avis` sont dénormalisés (recalculés à chaque avis, étape 3A.2).
 * Les disponibilités temps réel (Firebase) et l'écriture par les agents arrivent au Module 4 :
 * ici, les disponibilités sont stockées en base (table `disponibilites_jour`) et seedées.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('structures_sanitaires', function (Blueprint $table) {
            $table->id();

            $table->string('nom', 200);
            $table->enum('type', ['chu', 'chr', 'clinique_privee', 'cabinet', 'pharmacie', 'laboratoire', 'centre_sante']);
            $table->text('adresse');
            $table->string('commune', 100);

            // Coordonnées GPS (précision ~1 mm) — cœur de la géolocalisation.
            $table->decimal('latitude', 10, 8);
            $table->decimal('longitude', 11, 8);

            $table->string('telephone', 20)->nullable();
            $table->string('whatsapp', 20)->nullable();

            // Horaires : { "lundi": "08:00-18:00", ... }. Spécialités : ["ORL", "Cardiologie", ...].
            $table->json('horaires_json')->nullable();
            $table->json('specialites_json')->nullable();

            $table->unsignedInteger('tarif_min_cfa')->nullable();
            $table->unsignedInteger('tarif_max_cfa')->nullable();

            // Réputation (dénormalisée, recalculée par les avis — 3A.2).
            $table->decimal('note_moyenne', 3, 2)->nullable();
            $table->unsignedInteger('nb_avis')->default(0);

            $table->boolean('partenaire_ivoirsante')->default(false);

            $table->timestamps();

            $table->index('type');
            $table->index('commune');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('structures_sanitaires');
    }
};
