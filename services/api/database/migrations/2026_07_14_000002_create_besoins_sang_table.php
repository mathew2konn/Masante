<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Module 5 / 5.7 — Besoins en sang publiés par un établissement (CdC FN6 : « voir les groupes
 * sanguins les plus demandés » + « alerte aux donneurs compatibles en cas d'urgence signalée par
 * un CHU »).
 *
 * Même patron que les alertes épidémiques (5.4) : une publication datée, désactivable sans être
 * supprimée (l'historique des tensions d'approvisionnement a une valeur en soi), et un ciblage
 * calculé 100 % côté serveur. Ici, c'est l'ÉTABLISSEMENT qui publie — lui seul sait qu'il manque
 * de O− ce matin ; l'admin MaSanté n'a aucune visibilité sur les stocks d'un hôpital.
 *
 * `niveau` distingue le besoin courant (« nous manquons régulièrement de B− ») de l'URGENCE
 * transfusionnelle, seule à déclencher une alerte aux donneurs compatibles. Sans cette distinction,
 * tout deviendrait urgent — et plus rien ne le serait.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('besoins_sang', function (Blueprint $table) {
            $table->id();

            $table->foreignId('structure_id')->constrained('structures_sanitaires')->cascadeOnDelete();

            // Groupe de la POCHE recherchée. Les donneurs alertés sont ceux qui peuvent donner à ce
            // groupe (compatibilité ABO/Rhésus, calculée par DonSangService) — pas seulement les
            // porteurs du même groupe : un O− peut sauver un AB+.
            $table->enum('groupe_sanguin', ['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-']);

            $table->enum('niveau', ['courant', 'urgent']);
            $table->string('message', 300)->nullable();   // contexte facultatif (« suite à un accident »)

            $table->date('date_debut');
            $table->date('date_fin')->nullable();          // NULL = besoin toujours en cours
            $table->boolean('actif')->default(true);

            // Traçabilité de la publication (comme les alertes épidémiques).
            $table->foreignId('publie_par_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index(['actif', 'niveau']);
            $table->index(['structure_id', 'actif']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('besoins_sang');
    }
};
