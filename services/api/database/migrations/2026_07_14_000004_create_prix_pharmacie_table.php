<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Module 5 / 5.8 — Prix et disponibilité d'un médicament dans une pharmacie (CdC §8,
 * table `prix_pharmacie` ; FN7 comparateur + FN8 ruptures).
 *
 * UNE SEULE TABLE POUR LES DEUX FONCTIONS. Le CdC y a mis le champ `disponible` : une rupture, ce
 * n'est rien d'autre qu'un relevé disant « ce médicament n'est pas en rayon ici, ce jour ». Créer
 * une table `ruptures` à part aurait donné deux mécanismes concurrents pour dire la même chose, et
 * deux vérités possibles sur le même fait.
 *
 * ÉCART ASSUMÉ AU CdC : `prix_cfa` passe de NOT NULL à NULLABLE. On ne relève pas le prix d'un
 * médicament absent des rayons — exiger un prix aurait rendu FN8 inapplicable, ou pire, aurait
 * poussé à inventer un chiffre.
 *
 * `source` hiérarchise la confiance (le service applique cet ordre) : `pharmacie_portail` (le
 * pharmacien lui-même) > `cename` (référence officielle) > `crowdsource_patient` (un passant, dont
 * on prend la MÉDIANE et jamais le dernier mot).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('prix_pharmacie', function (Blueprint $table) {
            $table->id();

            $table->foreignId('medicament_id')->constrained('medicaments')->cascadeOnDelete();
            $table->foreignId('structure_id')->constrained('structures_sanitaires')->cascadeOnDelete();

            // NULLABLE (écart CdC) : un signalement de rupture n'a pas de prix à porter.
            $table->unsignedInteger('prix_cfa')->nullable();
            $table->boolean('disponible')->default(true);

            $table->enum('source', ['cename', 'pharmacie_portail', 'crowdsource_patient']);
            // Qui a signalé (crowdsourcing) : nullable pour les sources officielles.
            $table->foreignId('signale_par_user_id')->nullable()->constrained('users')->nullOnDelete();

            // Date du relevé : un prix sans date ne vaut rien (la fraîcheur est affichée au patient).
            $table->timestamp('date_mise_a_jour');

            $table->timestamps();

            $table->index(['medicament_id', 'structure_id']);
            $table->index(['structure_id', 'disponible']);
            $table->index('date_mise_a_jour');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prix_pharmacie');
    }
};
