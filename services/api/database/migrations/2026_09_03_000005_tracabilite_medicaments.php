<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * B3-c — CODE-BARRES + TRAÇABILITÉ NATIONALE DES MÉDICAMENTS (CDC_11 §7.6).
 *
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 * LE §7.6 TIENT EN UNE PHRASE : « Lutte contre les médicaments falsifiés, suivi de consommation,
 * statistiques nationales. » Trois finalités, aucun mécanisme — c'est ce lot qui les invente.
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 *
 * ═══ POURQUOI UNE TABLE À PART, ET NON `delivrances` ENRICHIE ═══
 *
 * `delivrances` CASCADE sur l'ordonnance : le patient est maître de son carnet (loi 2013-450), et
 * son droit de supprimer ne doit pas être empêché par un besoin statistique. Or le registre
 * national doit survivre EXACTEMENT à ce que `delivrances` ne survit pas.
 *
 *   `delivrances`         — l'ACTE, rattaché au dossier du patient (B3-a, non touchée).
 *   `traces_dispensation` — le FAIT NATIONAL, détaché.
 *
 * ═══ LA DÉCISION CENTRALE : AUCUNE DONNÉE NOMINATIVE ═══
 *
 * Ni patient, ni prescripteur, ni ordonnance, ni posologie, ni instructions. C'est ce qui rend la
 * survie du registre acceptable : autrement on aurait construit un dossier médical qui survit à la
 * suppression du dossier médical — l'inverse exact de la loi. Il porte le QUOI (identité de produit
 * FIGÉE), le COMBIEN, le QUAND, le OÙ (officine).
 *
 * `delivrance_ligne_id` est conservé comme IDENTIFIANT SANS CLÉ ÉTRANGÈRE (réconciliation et
 * idempotence, ADR-042 D1) : dénominalisé n'est pas anonyme, et c'est dit avant de coder — tant que
 * la délivrance existe, qui tient la base peut remonter au patient ; une fois l'ordonnance
 * supprimée, la trace devient réellement orpheline (même formulation qu'en P10c-2-i).
 *
 * ═══ CE QUI N'Y EST PAS ═══
 *
 *   `lot`, `date_peremption`  — le stock de B3-b n'est PAS suivi lot par lot ; attribuer un lot à
 *                               une sortie serait INVENTER une attribution.
 *   colonne `statut`/`total`  — une valeur recalculable finit par diverger (P5.3a) ; les
 *                               statistiques sont DÉRIVÉES du registre, jamais stockées.
 *   `delivrance_id`           — redondant avec `delivrance_ligne_id`, qui se joint à
 *                               `delivrance_lignes` tant qu'elle existe. Une clé de moins vers le
 *                               nominatif est une clé de moins.
 *
 * ═══ `code_barres` SUR `medicaments`, JAMAIS SUR L'ARTICLE D'OFFICINE ═══
 *
 * Un EAN/GTIN identifie un PRODUIT DU FABRICANT : deux officines qui vendent la même boîte scannent
 * le même code. Il entre dans la projection gouvernée (`SourceMedicaments::extraire()`) — l'empreinte
 * du référentiel change, ce n'est pas une dérive (précédent `forme_juridique`, P6.4d). Il reste
 * VIDE à la naissance, et l'absence est comptée et affichée (5ᵉ application du motif `loinc`/CIM).
 *
 * `UNIQUE(pays_code, code_barres)` — cohérent avec `UNIQUE(pays_code, code)` de P6.6a. MySQL
 * autorise plusieurs `NULL` : les fiches sans code-barres ne se heurtent pas entre elles.
 *
 * ═══ APPEND-ONLY, MAIS PAS UNE CHAÎNE DE HACHAGE (E7) ═══
 *
 * Deux niveaux, motif `mouvements_stock` (B3-b) : le modèle refuse, et le moteur refuse aussi —
 * apostrophes DOUBLÉES dans les messages de déclencheur (piège trouvé au G2 de B3-b : « ne s'efface »
 * referme la chaîne SQL et rend la migration impossible à rejouer). Une SEPTIÈME chaîne d'audit
 * (ADR-042) protégerait contre un AUTRE risque (qui tient la base) : on ne durcit pas par symétrie
 * décorative (précédent P6.4a, qui a refusé le journal de non-réutilisation pour les établissements).
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── Le code-barres du produit — projection gouvernée (E4) ───────────────────────────────
        Schema::table('medicaments', function (Blueprint $table) {
            $table->string('code_barres', 14)->nullable()->after('cename_reference');
            $table->unique(['pays_code', 'code_barres'], 'uq_medicament_code_barres');
        });

        // ── Le registre national, détaché et dénominalisé (E1, E2, E3) ──────────────────────────
        Schema::create('traces_dispensation', function (Blueprint $table) {
            $table->id();
            $table->string('pays_code', 2)->default('CI');

            // LE QUOI — identité de produit FIGÉE : elle doit survivre au retrait de la fiche.
            //
            // `medicament_id` SANS CLÉ ÉTRANGÈRE, corrigé pendant l'écriture (défaut trouvé par un
            // VECTEUR de G3, invisible à la relecture du plan) : une FK `nullOnDelete` fait exécuter
            // par le moteur un UPDATE (mise à NULL) sur CETTE ligne quand le médicament parent est
            // supprimé — et un déclencheur `BEFORE UPDATE` append-only bloquant TOUT refuserait
            // cette mise à NULL elle-même, empêchant purement et simplement de retirer un produit du
            // référentiel. Même famille que `structure_id`/`delivrance_ligne_id` juste en dessous :
            // un IDENTIFIANT, jamais une relation vivante (ADR-042 D1) — les colonnes FIGÉES
            // (`medicament_code`/`nom`/`dci`/`dosage`) portent déjà tout ce qui doit survivre.
            $table->unsignedBigInteger('medicament_id')->nullable();
            $table->string('medicament_code', 12)->nullable(); // NULL = non rattaché (E8)
            $table->string('medicament_nom', 200);
            $table->string('medicament_dci', 200)->nullable();
            $table->string('medicament_dosage', 100)->nullable();

            // LE COMBIEN
            $table->unsignedInteger('quantite');

            // LE OÙ — identifiant sans clé étrangère + identifiant national figé.
            $table->unsignedBigInteger('structure_id')->nullable();
            $table->string('structure_identifiant_national', 12)->nullable();

            // LE QUAND
            $table->timestamp('dispensee_le');

            // Réconciliation et idempotence (E3) — identifiant, jamais clé étrangère.
            $table->unsignedBigInteger('delivrance_ligne_id')->nullable();

            $table->timestamp('created_at')->useCurrent();

            $table->unique('delivrance_ligne_id', 'uq_trace_delivrance_ligne');
            $table->index(['pays_code', 'medicament_code'], 'idx_trace_produit');
            $table->index('dispensee_le', 'idx_trace_date');
            $table->index('structure_id', 'idx_trace_officine');
        });

        $this->poserGardes();
    }

    public function down(): void
    {
        $this->retirerGardes();

        Schema::dropIfExists('traces_dispensation');

        Schema::table('medicaments', function (Blueprint $table) {
            $table->dropUnique('uq_medicament_code_barres');
            $table->dropColumn('code_barres');
        });
    }

    private function poserGardes(): void
    {
        $pilote = Schema::getConnection()->getDriverName();

        if ($pilote === 'mysql') {
            DB::unprepared("
                CREATE TRIGGER trg_trace_dispensation_insert BEFORE INSERT ON traces_dispensation
                FOR EACH ROW
                BEGIN
                    IF NEW.quantite = 0 THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Une trace de dispensation porte une quantite non nulle.';
                    END IF;
                END
            ");

            // Apostrophes DOUBLÉES dans les deux messages : ce texte part dans une chaîne SQL entre
            // apostrophes, et un seul « ' » non doublé referme la chaîne — la migration refuserait
            // alors de se rejouer, exactement le piège trouvé au G2 live de B3-b.
            foreach (['UPDATE' => 'ne se modifie pas', 'DELETE' => "ne s''efface pas"] as $moment => $verbe) {
                DB::unprepared('
                    CREATE TRIGGER trg_trace_dispensation_'.strtolower($moment)." BEFORE {$moment} ON traces_dispensation
                    FOR EACH ROW
                    BEGIN
                        SIGNAL SQLSTATE '45000'
                          SET MESSAGE_TEXT = 'Une trace de dispensation {$verbe} : le registre national est append-only.';
                    END
                ");
            }

            return;
        }

        DB::unprepared('
            CREATE TRIGGER trg_trace_dispensation_insert BEFORE INSERT ON traces_dispensation
            FOR EACH ROW
            WHEN NEW.quantite = 0
            BEGIN
                SELECT RAISE(ABORT, \'Une trace de dispensation porte une quantite non nulle.\');
            END
        ');

        foreach (['UPDATE', 'DELETE'] as $moment) {
            DB::unprepared('
                CREATE TRIGGER trg_trace_dispensation_'.strtolower($moment)." BEFORE {$moment} ON traces_dispensation
                FOR EACH ROW
                BEGIN
                    SELECT RAISE(ABORT, 'Une trace de dispensation ne se modifie ni ne s''efface : le registre national est append-only.');
                END
            ");
        }
    }

    private function retirerGardes(): void
    {
        foreach (['insert', 'update', 'delete'] as $moment) {
            DB::unprepared("DROP TRIGGER IF EXISTS trg_trace_dispensation_{$moment}");
        }
    }
};
