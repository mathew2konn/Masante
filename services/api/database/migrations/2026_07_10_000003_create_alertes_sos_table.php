<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Module 5 / 5.2 — Journal des alertes SOS (CdC FN1).
 *
 * Le CdC ne prévoit aucune table pour FN1 : l'alerte y est purement sortante (appel + SMS depuis
 * le téléphone). On la journalise tout de même, pour la revue a posteriori et les statistiques
 * (zones et heures de déclenchement), comme le portail le fait déjà des accès au dossier.
 *
 * DONNÉE SENSIBLE. La position GPS est une donnée personnelle (loi n°2013-450). Elle n'est
 * enregistrée que parce que le patient a LUI-MÊME déclenché l'alerte, et uniquement au moment de
 * ce déclenchement : aucun suivi continu, aucune position en dehors d'un SOS.
 *
 * BEST-EFFORT. L'appel au SAMU et le SMS partent du téléphone, sans passer par nous. Cet
 * enregistrement arrive APRÈS, s'il y a du réseau. Son échec n'empêche jamais l'alerte : la
 * latitude est donc nullable (un SOS sans position vaut mieux que pas de SOS).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('alertes_sos', function (Blueprint $table) {
            $table->id();

            // Qui a déclenché (compte) et pour quel membre (nullable : SOS sans carte vitale activée).
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('membre_id')->nullable()->constrained('membres_famille')->nullOnDelete();

            // Position au moment du déclenchement. Nullable : GPS refusé, indisponible, ou en intérieur.
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->unsignedSmallInteger('precision_metres')->nullable();

            // Ce que le téléphone a réellement fait : appel SAMU, SMS au contact, ou les deux.
            $table->enum('canal', ['appel', 'sms', 'appel_sms']);

            // Contact prévenu (dénormalisé : le contact peut être modifié ou supprimé ensuite,
            // la trace de l'alerte doit rester exacte).
            $table->string('contact_prevenu_nom', 200)->nullable();
            $table->string('contact_prevenu_tel', 20)->nullable();

            $table->timestamp('declenchee_le')->useCurrent();

            $table->index(['user_id', 'declenchee_le']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alertes_sos');
    }
};
