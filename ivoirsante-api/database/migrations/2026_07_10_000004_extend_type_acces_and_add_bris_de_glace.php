<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Module 5 / 5.3 — Bris de glace : la règle d'accès au dossier passe de 3 à 5 voies
 * (Note_Continuite §5.4, qui étend Sécurité §4.4).
 *
 *   qr_scan       — QR valide, 30 min (voies 1 et 3)
 *   referent      — médecin référent, permanent révocable (voie 2) [non implémenté à ce jour]
 *   delegation    — QR généré par un délégué (voie 3, trace distincte de qr_scan)
 *   bris_de_glace — urgence vitale, périmètre vital minimal, 15 min (voie 4)
 *   admin         — exceptionnel, admin MaSanté uniquement
 *
 * `motif_urgence` : la justification textuelle est OBLIGATOIRE pour un bris de glace (§5.3). Elle
 * reste nullable en base car les autres voies n'en produisent pas ; c'est le contrôleur qui l'exige.
 * Comme le reste du journal, elle est immuable une fois écrite.
 *
 * `change()` plutôt qu'un DDL brut : MySQL réécrit l'ENUM, et SQLite (tests) recrée la table avec
 * sa contrainte CHECK — car SQLite CONTRAINT bien les enums de Laravel, contrairement à ce qu'on
 * pourrait croire. Un `ALTER TABLE ... MODIFY` réservé à MySQL laisserait les tests échouer.
 */
return new class extends Migration
{
    /** Les cinq voies d'accès au dossier après extension. */
    private const VOIES = ['qr_scan', 'referent', 'delegation', 'bris_de_glace', 'admin'];

    public function up(): void
    {
        Schema::table('acces_dossier', function (Blueprint $table) {
            $table->enum('type_acces', self::VOIES)->change();

            // Justification saisie par l'agent avant l'ouverture (motif + identification du patient).
            $table->text('motif_urgence')->nullable()->after('type_acces');
        });
    }

    public function down(): void
    {
        Schema::table('acces_dossier', function (Blueprint $table) {
            $table->dropColumn('motif_urgence');
            $table->enum('type_acces', ['qr_scan', 'referent', 'admin'])->change();
        });
    }
};
