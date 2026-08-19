<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * P10a — Le jeton de partage d'une fiche de triage (CDC_05 §5.4).
 *
 * ═══ POURQUOI CETTE COLONNE EXISTE : LE §5.4 LA REND STRUCTURELLEMENT NÉCESSAIRE ═══
 *
 * Le §5.4 exige « un **QR Code** permettant au médecin d'accéder au triage ». Un QR est un lien
 * durable, imprimé, photographié, envoyé sur WhatsApp. Le construire sur `/triage/{id}/fiche`
 * reviendrait à diffuser un lien dont **l'identifiant est un entier séquentiel** : quiconque le
 * scanne peut lire la fiche du voisin en changeant un chiffre.
 *
 * ═══ ET LE G0 A TROUVÉ QUE LA PORTE ÉTAIT DÉJÀ OUVERTE ═══
 *
 * `GET /triage/{triage}/fiche` et `GET /triage/historique` sont **publics et sans contrôle de
 * propriété** (vérifié par `route:list` : aucun `auth:sanctum`, et `historique` ne filtre sur aucun
 * utilisateur). L'historique renvoie donc les **50 derniers triages de tout le monde** — nom du
 * patient, âge, sexe, symptômes, score. C'est un accès à des données de santé sans lien de prise en
 * charge, que CDC_00 §4 range parmi les interdits absolus, et la loi 2013-450 avec lui.
 *
 * Ce n'est pas un défaut introduit par P10a : il date du Module 1. Mais poser le QR du §5.4
 * par-dessus l'aurait **aggravé** — d'un accès théorique par incrémentation, on serait passé à un
 * lien partagé délibérément. Le jeton referme les deux à la fois.
 *
 * ═══ CE N'EST PAS LE JETON QR DU DOSSIER (P2/P4) ═══
 *
 * `QrTokenService` ouvre une SESSION sur un dossier médical : usage unique, dix minutes, consommé.
 * Une fiche de triage est l'inverse — elle doit rester lisible quand le patient la montre à
 * l'accueil deux heures plus tard, et le §5.4 la veut « partageable, transmissible au médecin avant
 * le rendez-vous ». Deux objets, deux durées de vie : les confondre aurait cassé l'un ou l'autre.
 *
 * Ce que le jeton garantit est donc la **non-énumérabilité**, pas l'éphémérité. Le patient reste
 * maître de ce qu'il partage — c'est lui qui envoie le lien.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('triages', function (Blueprint $table) {
            // 48 caractères d'alphabet alphanumérique : très au-delà de ce qu'une énumération
            // atteint. `unique` pour que la recherche soit indexée et qu'une collision, même
            // improbable, échoue bruyamment plutôt que de donner accès à la fiche d'un autre.
            $table->string('jeton_partage', 64)->nullable()->unique()->after('fiche_generee');
        });

        // ═══ LES TRIAGES EXISTANTS EN REÇOIVENT UN, ET PERSONNE NE LE CONNAÎT ═══
        //
        // Conséquence assumée et dite : une fiche anonyme antérieure devient **inaccessible** sans
        // authentification. Ce n'est pas une perte de fonction — elle n'était atteignable que par
        // l'identifiant séquentiel, c'est-à-dire par le défaut lui-même. Un propriétaire
        // authentifié, lui, continue de lire les siennes.
        //
        // Le rattrapage ne va PAS plus loin : on ne devine pas un `user_id` pour les triages
        // anonymes du passé (précédent des mesures antérieures en L1+L2 — leur inventer une
        // appartenance serait un mensonge d'archive).
        DB::table('triages')->select('id')->orderBy('id')->chunk(200, function ($lignes) {
            foreach ($lignes as $ligne) {
                DB::table('triages')
                    ->where('id', $ligne->id)
                    ->update(['jeton_partage' => Str::random(48)]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('triages', function (Blueprint $table) {
            $table->dropUnique(['jeton_partage']);
            $table->dropColumn('jeton_partage');
        });
    }
};
