<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * P10a — Orientation après triage + gouvernance du triage (CDC_05 §5, CDC_09 §10).
 *
 * ═══ LE DÉFAUT QUE CETTE MIGRATION FERME ═══
 *
 * `symptomes.specialite_hint` est un LIBELLÉ LIBRE, et le G0 de P10a a montré qu'il est pire que
 * ce que P6.8 annonçait : **trois de ses sept valeurs portent DEUX spécialités à la fois**
 * (« Cardiologie / Urgences », « Urgences / Traumatologie », « Gynécologie / Maternité »).
 *
 * Le problème n'était donc pas seulement de FORME (libellé contre code) mais de CARDINALITÉ — et
 * c'est la cardinalité qui commande le modèle : aucune colonne `specialite_id` ne peut porter
 * « Cardiologie / Urgences ». D'où une table de liaison, comme `analyse_references` (P6.7a) porte
 * une ligne par strate et `vaccin_echeances` (P6.8b) une ligne par dose.
 *
 * ═══ LE RANG N'EST PAS DÉCORATIF : C'EST LUI QUI SUPPRIME LA RÈGLE EN DUR ═══
 *
 * `TriageService::deduireSpecialite()` priorisait par comparaison de sous-chaînes :
 *
 *     $hints->first(fn ($h) => str_contains($h, 'urgenc') || str_contains($h, 'cardio'))
 *
 * Une règle médicale en dur (CDC_00 §4) que personne n'avait vue **parce qu'elle ne ressemble pas à
 * une règle**. Pire : elle porte sur du texte que le portail laisse corriger — écrire « Urgence »
 * au singulier aurait supprimé la priorité **sans que rien ne le signale**.
 *
 * Le `rang` fait de cette priorité une DONNÉE du référentiel gouverné. Corriger un libellé cesse
 * d'être un acte clinique involontaire.
 *
 * ═══ CE QUI N'EST PAS SUPPRIMÉ ═══
 *
 * `specialite_hint` est CONSERVÉE (ADR-024), mais **plus personne ne l'écrit** — même énoncé
 * honnête que `vaccinations.statut` (P6.8b) et les colonnes `cmu_*` (P6.8d) : la colonne dit ce qui
 * n'est plus maintenu, au lieu de laisser croire à une donnée vivante.
 *
 * ═══ `triages.referentiel_version` — LE CONSTAT F3 DE P6.3, ENFIN REFERMÉ ═══
 *
 * `triages` ne stockait AUCUNE version. Corriger un `poids_severite` rendait donc **tout triage
 * antérieur inexplicable**, alors que CDC_04 §115 prévoit la version du protocole.
 *
 * NULLABLE ET JAMAIS RÉTROACTIVE : les triages passés n'ont eu aucune version en vigueur — leur en
 * attribuer une après coup serait un **mensonge d'archive**. Précédent exact de
 * `mesures_sante.referentiel_version` (L1+L2).
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── La liaison ordonnée symptôme → spécialités ───────────────────────────────────────────
        Schema::create('symptome_specialites', function (Blueprint $table) {
            $table->id();

            $table->foreignId('symptome_id')->constrained('symptomes')->cascadeOnDelete();
            // `restrictOnDelete` : retirer du vocabulaire un terme vers lequel des symptômes
            // orientent doit ÉCHOUER BRUYAMMENT. En `nullOnDelete`, l'orientation deviendrait
            // silencieusement vide — exactement le défaut latent qu'on referme (précédent
            // `couvertures_membre` en P6.8d).
            $table->foreignId('specialite_id')->constrained('specialites_medicales')->restrictOnDelete();

            // L'ORDRE DE PRÉFÉRENCE. 1 = proposée en premier. C'est ce qui remplace le
            // `str_contains('urgenc')` : la priorité devient une donnée relue par deux agents.
            $table->unsignedSmallInteger('rang')->default(1);

            // ═══ LA RESTRICTION DEVIENT UNE DONNÉE, ELLE AUSSI ═══
            //
            // « La gynécologie n'est pertinente que pour une patiente » était un `str_contains('gyn')`
            // dans le service. Porté ici, le fait devient vérifiable et modifiable sans redéployer —
            // et surtout il cesse de dépendre de l'orthographe d'un libellé.
            //
            // NULLABLE = aucune restriction, et c'est le cas courant : une valeur par défaut
            // inventée (« M ») aurait été fausse plutôt que manquante (précédent P6.4a).
            $table->enum('sexe_requis', ['M', 'F'])->nullable();

            $table->timestamps();

            // Un symptôme ne désigne pas deux fois la même spécialité : ce serait la même
            // orientation à deux rangs, donc une contradiction dans la donnée.
            $table->unique(['symptome_id', 'specialite_id'], 'uq_symptome_specialite');
            $table->index(['symptome_id', 'rang'], 'idx_symptome_orientation');
        });

        Schema::table('triages', function (Blueprint $table) {
            // Voir l'en-tête : nullable, jamais rétroactive.
            $table->unsignedInteger('referentiel_version')->nullable()->after('specialite_requise');

            // Les CODES retenus, dans l'ordre. `specialite_requise` (libellé, 100 car.) est
            // CONSERVÉE : l'historique la porte, et la réécrire changerait ce qu'un patient a
            // réellement lu. Elle devient l'affichage, ceci devient la donnée.
            $table->json('specialites_json')->nullable()->after('referentiel_version');
        });

        $this->poserLesGardesDuMoteur();
    }

    /**
     * DEUX GARDES, ET AUCUNE N'EST DÉCORATIVE.
     *
     * 1. Un rang nul — l'orientation serait ordonnée par rien, et deux lignes à `0` rendraient
     *    l'ordre dépendant du moteur : *une valeur qui change avec la façon dont on la demande
     *    n'est pas un calcul, c'est un hasard* (leçon P6.8b).
     * 2. Une orientation vers un terme INACTIF du vocabulaire — elle serait valide au regard de la
     *    clé étrangère et pourtant vide à l'écran : c'est très exactement le défaut latent que cet
     *    incrément referme, et il ne doit pas pouvoir revenir par la porte de derrière.
     *
     * POURQUOI DES DÉCLENCHEURS ET NON DES `CHECK` : `specialite_id` porte une action
     * référentielle, donc MySQL 8.4 refuse (erreur 3823 — le mur de P6.3, cousin du 1215 de P6.1) ;
     * et la garde 2 interroge une AUTRE table, ce qu'un `CHECK` ne sait pas faire. SQLite refuse en
     * plus `ALTER TABLE … ADD CONSTRAINT`. Déclencheurs dans les deux dialectes (CDC_04 §139).
     */
    private function poserLesGardesDuMoteur(): void
    {
        $mysql = DB::getDriverName() === 'mysql';

        foreach (['INSERT', 'UPDATE'] as $evenement) {
            $suffixe = strtolower($evenement);

            DB::unprepared($mysql
                ? "CREATE TRIGGER ck_orientation_{$suffixe}
                   BEFORE {$evenement} ON symptome_specialites
                   FOR EACH ROW
                   BEGIN
                       IF NEW.rang < 1 THEN
                           SIGNAL SQLSTATE '45000'
                               SET MESSAGE_TEXT = 'ck_orientation_rang';
                       END IF;
                       IF (SELECT COUNT(*) FROM specialites_medicales
                           WHERE id = NEW.specialite_id AND actif = 1) = 0 THEN
                           SIGNAL SQLSTATE '45000'
                               SET MESSAGE_TEXT = 'ck_orientation_specialite_inactive';
                       END IF;
                   END"
                : "CREATE TRIGGER ck_orientation_{$suffixe}
                   BEFORE {$evenement} ON symptome_specialites
                   BEGIN
                       SELECT RAISE(ABORT, 'ck_orientation_rang')
                       WHERE NEW.rang < 1;
                       SELECT RAISE(ABORT, 'ck_orientation_specialite_inactive')
                       WHERE (SELECT COUNT(*) FROM specialites_medicales
                              WHERE id = NEW.specialite_id AND actif = 1) = 0;
                   END"
            );
        }
    }

    public function down(): void
    {
        foreach (['insert', 'update'] as $evenement) {
            DB::unprepared("DROP TRIGGER IF EXISTS ck_orientation_{$evenement}");
        }

        Schema::table('triages', function (Blueprint $table) {
            $table->dropColumn(['referentiel_version', 'specialites_json']);
        });

        Schema::dropIfExists('symptome_specialites');
    }
};
