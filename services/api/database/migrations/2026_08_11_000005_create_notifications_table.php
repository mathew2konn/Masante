<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Carnet familial partagé / D1 — table native des notifications Laravel.
 *
 * Elle n'est pas inventée : c'est le schéma standard de `Illuminate\Notifications`, dont le trait
 * `Notifiable` est posé sur `App\Models\User` depuis P0 — sans que la migration n'ait jamais été
 * publiée. Le système était donc câblé et inutilisable ; on le rend utilisable.
 *
 * Pourquoi le natif plutôt qu'une table maison : `via()` EST le port « canal » demandé au G1
 * (ajouter le SMS demain = une ligne), et `read_at` / `unreadNotifications` / `markAsRead()`
 * arrivent éprouvés. Même intention que l'Outbox + port `EnvoiNotification` validé en P5.4c côté
 * paiement, mais dans l'idiome du framework plutôt que réécrite.
 *
 * RÈGLE POSÉE AU G1, à ne jamais lever : `data` ne contient AUCUN contenu médical. Une notification
 * dit qui, quoi, et où cliquer — jamais ce qu'il y a dans le dossier. Un push s'affiche sur un écran
 * verrouillé, visible de n'importe qui dans la pièce, et son corps transite par le service Expo. Le
 * fait médical, lui, reste derrière l'authentification.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            // La pastille de non-lues est le premier appel de chaque ouverture d'écran : elle doit
            // se lire sur l'index, pas sur la table. `morphs()` indexe déjà (type, id) ; on prolonge
            // avec `read_at` pour que le COUNT des non-lues reste couvert.
            $table->index(['notifiable_type', 'notifiable_id', 'read_at'], 'idx_notif_destinataire_lu');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
