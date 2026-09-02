<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P10c-3-i (F14, décision propriétaire 2026-08-28) — Les antécédents entrent dans le vecteur de
 * features de l'IA, comme la valeur DÉJÀ gouvernée par P10b-3-ii, jamais une liste brute recalculée.
 *
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 * `triages.score_antecedents` : POURQUOI IL FAUT LE PERSISTER, PAS SEULEMENT LE RENVOYER
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 *
 * `TriageService::analyser()` calcule déjà cette valeur bornée
 * (`$resultat['details_score']['antecedents']`) et la renvoie dans la réponse JSON — mais rien ne
 * la persistait. `TriageController::appelerAssistanceIa()` peut la lire depuis `$resultat` (même
 * requête), mais `ServiceRetourTriage::alimenterJeuApprentissage()` (P10c-2-i) tourne potentiellement
 * des JOURS plus tard, sur le seul `Triage` retrouvé en base — sans colonne, cette valeur serait
 * irrémédiablement perdue, exactement comme `score_severite`/`niveau`/`protocole_version` auraient
 * été perdus s'ils n'avaient pas été estampillés à l'écriture (motif constant de ce module : ne
 * JAMAIS recalculer rétroactivement une décision, toujours persister ce qui a été décidé au moment
 * où le serveur le savait).
 *
 * Nullable et JAMAIS rétroactive : les triages antérieurs à cette migration n'ont eu aucune valeur
 * calculée à l'écriture — leur en attribuer une après coup serait un mensonge d'archive (précédent
 * L2, `protocole_code` de P10b-1, `modele_version` de P10c-2-i).
 *
 * ═══ `jeux_donnees_entrainement.score_antecedents` : LA FEATURE ELLE-MÊME ═══
 *
 * SHAP ne pourra jamais nommer QUEL antécédent pèse — seulement que « les antécédents » pèsent en
 * bloc. Nommer un antécédent précis exigerait un encodage catégoriel stable par maladie, hors
 * périmètre de cet incrément (coût assumé et dit, plan G1 P10c-3-i, F14).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('triages', function (Blueprint $table): void {
            $table->unsignedTinyInteger('score_antecedents')->nullable()->after('score_severite');
        });

        Schema::table('jeux_donnees_entrainement', function (Blueprint $table): void {
            $table->unsignedTinyInteger('score_antecedents')->nullable()->after('grossesse');
        });
    }

    public function down(): void
    {
        Schema::table('jeux_donnees_entrainement', function (Blueprint $table): void {
            $table->dropColumn('score_antecedents');
        });

        Schema::table('triages', function (Blueprint $table): void {
            $table->dropColumn('score_antecedents');
        });
    }
};
