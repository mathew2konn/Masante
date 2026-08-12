<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Carnet familial partagé / D1 — jetons de push et trace du relais.
 *
 * `appareils_push` : un compte peut avoir plusieurs téléphones (le père a un professionnel et un
 * personnel). Le jeton est UNIQUE au niveau global, pas par compte : un téléphone revendu change de
 * propriétaire, et Expo réattribue son jeton — la contrainte `unique` sur `jeton_expo` fait que le
 * nouveau `POST` réaffecte la ligne au bon compte au lieu d'en empiler une seconde qui enverrait
 * les notifications de l'ancien propriétaire au nouveau. C'est une garde de confidentialité, pas
 * une garde d'unicité.
 *
 * `notification_envois` : ce qui permettra de répondre « le père a-t-il été prévenu, oui ou non ? ».
 * Sans elle, un push perdu serait indistinguable d'un push jamais tenté — inacceptable pour un
 * bris de glace. `statut` porte la machine `EN_ATTENTE → ENVOYEE | ECHOUEE`, et `ticket_id` garde
 * l'accusé Expo pour un éventuel relevé de reçus (différé, cf. dettes du plan G1).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('appareils_push', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();

            // `ExponentPushToken[xxxxxxxxxxxxxxxxxxxxxx]` — opaque, jamais interprété côté serveur.
            $table->string('jeton_expo')->unique();

            $table->string('plateforme', 16)->nullable();   // ios | android
            $table->timestamp('vu_le')->nullable();         // dernier passage de l'application

            // Révoqué à la déconnexion, ou sur `DeviceNotRegistered` renvoyé par Expo : continuer
            // d'écrire à un jeton mort fait perdre du quota et masque les vraies pannes.
            $table->timestamp('revoque_le')->nullable();

            $table->timestamps();
            $table->index(['user_id', 'revoque_le']);
        });

        Schema::create('notification_envois', function (Blueprint $table) {
            $table->id();

            $table->uuid('notification_id');
            $table->foreign('notification_id')->references('id')->on('notifications')->cascadeOnDelete();

            $table->foreignId('appareil_id')->constrained('appareils_push')->cascadeOnDelete();

            $table->string('statut', 16)->default('EN_ATTENTE');   // EN_ATTENTE | ENVOYEE | ECHOUEE
            $table->string('ticket_id')->nullable();
            $table->string('erreur')->nullable();
            $table->unsignedTinyInteger('tentatives')->default(0);
            $table->timestamp('traite_le')->nullable();
            $table->timestamps();

            // Un envoi par notification et par appareil : le rejeu du relais ne double pas la trace.
            $table->unique(['notification_id', 'appareil_id'], 'uq_envoi_notification_appareil');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_envois');
        Schema::dropIfExists('appareils_push');
    }
};
