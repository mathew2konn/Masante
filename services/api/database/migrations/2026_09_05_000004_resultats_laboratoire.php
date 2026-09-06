<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * B5-c — RÉSULTATS (saisie/import), AUTOMATES, VALIDATION BIOLOGIQUE, PUBLICATION
 * (CDC_09 §7.4, CDC_04 §109, CDC_11 §8.1 ; plan.md PLAN 4 §13, M1→M12).
 *
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 * M1 — LE BROUILLON DES RÉSULTATS VIT HORS DU CARNET, SUR `prelevements` LUI-MÊME
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 *
 * `CarnetSectionController::index()` liste sans filtre de statut : il n'existe aucune notion de
 * brouillon dans le carnet. Créer une ligne `resultats_analyses` avant validation la rendrait
 * visible au patient AVANT que L7 ne l'autorise. `resultats_bruts_json` (chiffré, même structure
 * que `resultats_analyses.resultats_json`) et `resultats_bruts_origine` (`saisie`|`automate`,
 * décidée par le serveur — M2) portent donc ce brouillon À PART, sur le prélèvement lui-même.
 * `resultats_bruts_json` SURVIT à la publication (M8) : c'est la pièce médico-légale de ce que le
 * laboratoire a réellement validé, distincte de la copie du carnet que le patient peut modifier.
 *
 * ═══ `journal_laboratoire.action` : EXTENSION ADDITIVE, JAMAIS RÉÉCRITE ═══
 *
 * Cinq actes de plus (`resultat_saisi`, `resultat_importe`, `validation`, `rejet`, `publication`) —
 * patron `triages.niveau` (P10b-1), `paiements.mode` (B4-b), `prix_pharmacie.source` (P11.2). Aucune
 * ligne existante ne change de sens. `->change()` sur les DEUX dialectes (patron P11.2 du
 * 2026-08-30, prouvé en G2 live) : `MODIFY` sous MySQL, reconstruction de table sous SQLite —
 * garantie identique des deux côtés, jamais « vraie en production, fausse en test ».
 *
 * ═══ `resultats_analyses.origine` : DÉCIDÉE PAR LE SERVEUR, JAMAIS DÉCLARÉE (L15) ═══
 *
 * Nullable : les résultats saisis directement par un patient, un délégué ou un soignant (hors
 * circuit laboratoire) n'ont pas d'origine à déclarer — seule une publication issue de
 * `ServiceValidationBiologique::publier()` la porte.
 *
 * ═══ `validations_biologiques` : LE JOURNAL DES VERDICTS, APPEND-ONLY ═══
 *
 * `prelevement_id`/`user_id` SONT DES IDENTIFIANTS, PAS DES RELATIONS VIVANTES (ADR-042 D1) : un
 * compte supprimé ne doit pas effacer la trace d'un verdict. `nom` est FIGÉ (même motif que
 * `preleve_par_nom`/`valide_par_nom`). Un rejet DOIT porter son motif — sans lui, dans six mois,
 * personne ne saurait pourquoi une saisie a été jetée (précédent : commission sans seed P5.5a,
 * révocation de clé P11.2, motif de rejet d'onboarding P11.1). Garde posée AU MOTEUR, pas
 * seulement au service (dual dialecte, mur de P6.3 : `CHECK` impossible ici aussi, colonnes sans
 * contrainte — mais rien n'empêche un déclencheur de porter la même règle).
 *
 * ═══ `automates` : LE REGISTRE, DÉCLARÉ PAR COMMANDE — PAS PAR ÉCRAN (M9, L10 réécrit) ═══
 *
 * Même raisonnement qu'`EmettreClientApiCommand` (P11.2) : déclarer un appareil qui écrira dans des
 * dossiers patients est un acte d'exploitation vérifié hors du système. `client_api_id`
 * (`nullOnDelete`) trace SOUS QUELLE CLÉ cet appareil pousse — il n'authentifie rien lui-même,
 * l'authentification reste entièrement portée par le HMAC (`AuthentificationClientApi`, inchangé).
 */
return new class extends Migration
{
    private const ACTIONS_JOURNAL = [
        'jeton_consulte', 'prelevement_enregistre', 'expedie', 'recu', 'mis_en_analyse',
        'resultat_saisi', 'resultat_importe', 'validation', 'rejet', 'publication',
    ];

    private const ACTIONS_JOURNAL_B5B = [
        'jeton_consulte', 'prelevement_enregistre', 'expedie', 'recu', 'mis_en_analyse',
    ];

    public function up(): void
    {
        Schema::table('prelevements', function (Blueprint $table) {
            $table->text('resultats_bruts_json')->nullable()->after('resultat_analyse_id');
            $table->enum('resultats_bruts_origine', ['saisie', 'automate'])->nullable()
                ->after('resultats_bruts_json');
        });

        Schema::table('journal_laboratoire', function (Blueprint $table) {
            $table->enum('action', self::ACTIONS_JOURNAL)->change();
        });
        $this->reconstituerGardesJournalLaboratoire();

        Schema::table('resultats_analyses', function (Blueprint $table) {
            $table->enum('origine', ['saisie', 'automate'])->nullable()->after('source');
        });

        Schema::create('validations_biologiques', function (Blueprint $table) {
            $table->id();

            // Identifiants SANS contrainte (ADR-042 D1) : un journal ne se laisse pas modifier par
            // la suppression d'un compte ou d'un prélèvement.
            $table->unsignedBigInteger('prelevement_id');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('nom', 200);

            $table->enum('verdict', ['valide', 'rejete']);
            $table->text('motif')->nullable();

            $table->timestamp('cree_le')->useCurrent();

            $table->index('prelevement_id', 'idx_validation_prelevement');
        });

        Schema::create('automates', function (Blueprint $table) {
            $table->id();

            $table->foreignId('structure_id')->constrained('structures_sanitaires')->cascadeOnDelete();
            $table->foreignId('client_api_id')->nullable()
                ->constrained('clients_api')->nullOnDelete();

            $table->string('libelle', 150);
            $table->string('marque', 100)->nullable();
            $table->string('modele', 100)->nullable();
            $table->string('numero_serie', 100)->nullable();

            $table->boolean('actif')->default(true);
            $table->timestamp('dernier_message_le')->nullable();

            $table->timestamps();

            $table->index(['structure_id', 'actif'], 'idx_automate_structure');
        });

        $this->poserGardes();
    }

    public function down(): void
    {
        $this->retirerGardes();

        Schema::dropIfExists('automates');
        Schema::dropIfExists('validations_biologiques');

        Schema::table('resultats_analyses', function (Blueprint $table) {
            $table->dropColumn('origine');
        });

        Schema::table('journal_laboratoire', function (Blueprint $table) {
            $table->enum('action', self::ACTIONS_JOURNAL_B5B)->change();
        });
        $this->reconstituerGardesJournalLaboratoire();

        Schema::table('prelevements', function (Blueprint $table) {
            $table->dropColumn(['resultats_bruts_json', 'resultats_bruts_origine']);
        });
    }

    /**
     * DÉFAUT RÉEL, TROUVÉ PAR LA SUITE COMPLÈTE (G3), PAS PAR LES 42 VECTEURS DÉDIÉS DE B5-c.
     *
     * `Schema::table('journal_laboratoire', …)->enum('action', …)->change()` a fait échouer DEUX
     * vecteurs de B5-b (`CircuitPrelevementTest::test_le_journal_refuse_…_au_niveau_du_moteur`),
     * invisibles à la suite de B5-c parce qu'elle ne les exerce jamais. **Sous SQLite** (le
     * dialecte de test), Laravel émule un `ALTER COLUMN` en reconstruisant toute la table : elle
     * crée une copie, copie les lignes, PUIS SUPPRIME l'originale et renomme la copie. Or SQLite
     * supprime automatiquement les déclencheurs attachés à une table qu'on supprime — les deux
     * gardes append-only de `journal_laboratoire` (posées par la migration B5-b) disparaissaient
     * donc SILENCIEUSEMENT, sans qu'aucune erreur ne le signale : le journal redevenait modifiable.
     *
     * **Sous MySQL**, `ALTER TABLE … MODIFY COLUMN` ne reconstruit pas la table par son nom : les
     * déclencheurs survivent — vérifié au G2 live, pas supposé. Le défaut est donc propre à SQLite,
     * et c'est la direction la PLUS TRAÎTRE de la divergence P6.8c/P6.8e/P10c-3-ii/P11.2 : ici la
     * garantie qui manque est celle du dialecte de TEST, pas celle de production — un test qui
     * exercerait cette garde APRÈS coup la trouverait déjà rompue, silencieusement, sans lien
     * apparent avec cette migration.
     *
     * Cette méthode ne fait donc RIEN sous MySQL (les déclencheurs de B5-b y sont intacts) et
     * RECRÉE les deux gardes sous SQLite — appelée après CHAQUE `->change()` sur cette table
     * (`up()` ET `down()`), puisque la reconstruction se produit dans les deux sens.
     */
    private function reconstituerGardesJournalLaboratoire(): void
    {
        if (Schema::getConnection()->getDriverName() === 'mysql') {
            return;
        }

        foreach (['update', 'delete'] as $moment) {
            DB::unprepared("DROP TRIGGER IF EXISTS trg_journal_labo_{$moment}");
        }

        foreach (['UPDATE', 'DELETE'] as $moment) {
            $nom = 'trg_journal_labo_'.strtolower($moment);
            DB::unprepared("
                CREATE TRIGGER {$nom} BEFORE {$moment} ON journal_laboratoire
                FOR EACH ROW
                BEGIN
                    SELECT RAISE(ABORT, 'Une entree du journal du laboratoire ne se modifie ni ne s''efface : append-only.');
                END
            ");
        }
    }

    private function poserGardes(): void
    {
        $pilote = Schema::getConnection()->getDriverName();

        if ($pilote === 'mysql') {
            DB::unprepared("
                CREATE TRIGGER trg_validation_bio_insert BEFORE INSERT ON validations_biologiques
                FOR EACH ROW
                BEGIN
                    IF NEW.verdict = 'rejete' AND (NEW.motif IS NULL OR NEW.motif = '') THEN
                        SIGNAL SQLSTATE '45000'
                          SET MESSAGE_TEXT = 'Un rejet doit porter son motif.';
                    END IF;
                END
            ");

            foreach (['UPDATE', 'DELETE'] as $moment) {
                $nom = 'trg_validation_bio_'.strtolower($moment);
                DB::unprepared("
                    CREATE TRIGGER {$nom} BEFORE {$moment} ON validations_biologiques
                    FOR EACH ROW
                    BEGIN
                        SIGNAL SQLSTATE '45000'
                          SET MESSAGE_TEXT = 'Une validation biologique ne se modifie ni ne s''efface : append-only.';
                    END
                ");
            }

            return;
        }

        // SQLite (suite de tests) : même garantie, dialecte différent — divergence refusée depuis
        // P6.8c (collation) et P6.8e (REGEXP).
        DB::unprepared("
            CREATE TRIGGER trg_validation_bio_insert_forme BEFORE INSERT ON validations_biologiques
            FOR EACH ROW
            WHEN NEW.verdict = 'rejete' AND (NEW.motif IS NULL OR NEW.motif = '')
            BEGIN
                SELECT RAISE(ABORT, 'Un rejet doit porter son motif.');
            END
        ");

        foreach (['UPDATE', 'DELETE'] as $moment) {
            $nom = 'trg_validation_bio_'.strtolower($moment);
            DB::unprepared("
                CREATE TRIGGER {$nom} BEFORE {$moment} ON validations_biologiques
                FOR EACH ROW
                BEGIN
                    SELECT RAISE(ABORT, 'Une validation biologique ne se modifie ni ne s''efface : append-only.');
                END
            ");
        }
    }

    private function retirerGardes(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS trg_validation_bio_insert');
        DB::unprepared('DROP TRIGGER IF EXISTS trg_validation_bio_insert_forme');

        foreach (['update', 'delete'] as $moment) {
            DB::unprepared("DROP TRIGGER IF EXISTS trg_validation_bio_{$moment}");
        }
    }
};
