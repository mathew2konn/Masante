<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * P6.8d — Assurances et organismes agréés (CDC_09 §8, étape 8 du §14).
 *
 * ═══ LE DÉFAUT QUE CETTE MIGRATION OUTILLE ═══
 *
 * `membres_famille` porte `cmu_numero`, `cmu_statut` et `cmu_validite` : **la CMU est codée dans les
 * NOMS DE COLONNES**. Trois conséquences vérifiées au G0 :
 *
 *   1. un seul tiers payant est représentable, et son nom est dans le schéma — alors que le §8.2 du
 *      CDC_06 en nomme six familles ;
 *   2. la séquence du §8 (« CNAM, **puis** assurances privées ») est irréalisable : elle suppose
 *      deux couvertures sur la même facture, trois colonnes n'en portent qu'une ;
 *   3. `non_inscrit` est **un statut qui dit qu'il n'y a pas de couverture** — une absence stockée
 *      comme une valeur, sur une ligne qui existe toujours.
 *
 * ═══ LE POINT DE CONCEPTION ═══
 *
 * **Une couverture n'est pas un attribut de la personne : c'est un contrat entre une personne et un
 * organisme.** Les colonnes `cmu_*` disent l'inverse — elles en font une propriété du corps, comme
 * le groupe sanguin. C'est ce qui rend inexprimable la situation la plus banale qui soit : un
 * fonctionnaire à la CMU **et** à la mutuelle de son ministère.
 *
 * Bascule parallèle à celle de P6.8b (« qu'est-ce qui est dû pour cette personne-là, aujourd'hui ? »)
 * et à celle d'ADR-034 (une plage biologique dépend de la personne) : la variable est ici
 * l'ORGANISME, et il y en a plusieurs.
 *
 * ═══ DEUX TABLES, UNE SEULE SOUS GOUVERNANCE ═══
 *
 * `organismes_assurance` est le registre national (§8) : il entre sous gouvernance §10.
 * `couvertures_membre` n'y entre pas, pour la même raison qu'`alertes_epidemiques` en P6.8c : c'est
 * un **fait individuel**, pas une donnée de référence. Un quatre-yeux sur la déclaration de sa propre
 * mutuelle n'aurait aucun sens.
 *
 * ═══ `ASS` + 6, ET L'AGRÉMENT EST NATIONAL ═══
 *
 * Sixième application du motif `ETS` / `PRO` / `MED` / `ANA` / `VAC` : un organisme est une
 * **instance** — cette caisse-ci, cette mutuelle-là —, pas un terme de nomenclature ; il se numérote.
 *
 * QUESTION REPOSÉE, PAS RECOPIÉE. P6.8c vient de rompre avec `pays_code` parce qu'une maladie est un
 * fait de nature : le paludisme est le paludisme partout. Un organisme d'assurance est une **personne
 * morale agréée par un État** — son agrément est délivré, suspendu et retiré par une autorité
 * nationale. Donc `UNIQUE(pays_code, code)`, et CI comme SN peuvent porter `ASS000001`.
 *
 * ═══ CE QUE `organismes_assurance` NE PORTE DÉLIBÉRÉMENT PAS ═══
 *
 * **Ni téléphone, ni adresse, ni site.** La projection gouvernée prend la LIGNE ENTIÈRE (voir
 * `SourceAssurances`) : y mettre un numéro de téléphone ferait d'un changement de standard **un acte
 * d'autorité soumis au quatre-yeux**. C'est exactement le critère qu'ADR-026 applique aux
 * établissements, transposé à l'envers — là-bas on excluait de la projection, ici on exclut de la
 * table, parce que la projection ne trie pas.
 *
 * **Aucun compteur d'assurés**, alors qu'il serait utile à l'écran : il rendrait fausse la phrase qui
 * justifie la projection entière (« rien n'écrit automatiquement dans cette table »), et l'instantané
 * divergerait à chaque déclaration d'un citoyen — précaution née de `note_moyenne` en P6.4a.
 *
 * **Aucune garantie, aucun plafond, aucune exclusion** : ce sont des clauses de CONTRAT, pas des
 * données d'agrément. Le registre dit *qui* couvre, pas *ce que* couvre un contrat donné.
 */
return new class extends Migration
{
    /** Les provenances admises. Ni `oms` ni `societe_savante` ici : un agrément est administratif. */
    private const SOURCES = ['demonstration', 'autorite_nationale', 'publication'];

    /**
     * Les six familles du §8.2 du CDC_06 — TRANSCRIPTION EXACTE de `TypePriseEnCharge` (service de
     * paiement Java). **Il y avait un vocabulaire à adopter, pas à inventer** : précédent P6.8a, où
     * les codes `orl` / `cardiologie` ont été promus plutôt que réinventés. En forger d'autres ici
     * ferait diverger les deux moitiés de la plateforme sur la nature d'un tiers payant.
     */
    private const TYPES = ['cnam', 'assurance', 'mutuelle', 'entreprise', 'ong', 'programme_gouvernemental'];

    /** L'état d'un agrément. NULLABLE en base : l'absence se dit, elle ne s'invente pas. */
    private const STATUTS_AGREMENT = ['valide', 'suspendu', 'retire'];

    public function up(): void
    {
        Schema::create('organisme_assurance_compteurs', function (Blueprint $table) {
            $table->string('pays_code', 2)->primary();
            $table->unsignedInteger('dernier')->default(0);
            $table->timestamps();
        });

        Schema::create('organismes_assurance', function (Blueprint $table) {
            $table->id();

            // HORS `$fillable` : un client ne choisit pas un code national, il le reçoit.
            $table->string('code', 12)->nullable();
            $table->string('pays_code', 2)->default('CI');

            $table->string('nom', 200);
            // « CNAM », « MUGEFCI »… Ce qu'un agent d'accueil lit sur une carte présentée.
            $table->string('sigle', 30)->nullable();

            $table->enum('type', self::TYPES);

            // ═══ EXISTE ET RESTERA VIDE, ET ON LE DIT ═══
            //
            // Troisième application du motif `analyses.loinc` (P6.7a) puis `code_cim10` (P6.8c) : les
            // numéros d'agrément sont délivrés par une autorité (ministère, CIMA) et **je n'en
            // invente pas**. Le contrôle qualité ne l'exige donc pas — l'exiger rendrait le
            // référentiel impubliable dès le premier jour ; l'absence est COMPTÉE et AFFICHÉE.
            $table->string('numero_agrement', 60)->nullable();

            // NULLABLE, et ce n'est pas un relâchement : c'est ce qu'un guichet lira. *Un organisme
            // sans agrément renseigné n'est pas « probablement agréé »* — l'absence doit pouvoir se
            // dire pour que l'écran ne l'affirme pas (même raisonnement qu'`autorisation_statut` en
            // P6.5a).
            $table->enum('agrement_statut', self::STATUTS_AGREMENT)->nullable();
            $table->date('agrement_debut')->nullable();
            $table->date('agrement_fin')->nullable();

            // NON NULLE, garde centrale du module — 5ᵉ application après P6.7a, P6.8b et P6.8c.
            $table->enum('source', self::SOURCES);
            $table->string('source_detail', 200)->nullable();

            // On DÉSACTIVE, on ne supprime pas : des couvertures citoyennes le référencent.
            $table->boolean('actif')->default(true);

            $table->timestamps();

            $table->unique(['pays_code', 'code'], 'uq_organisme_code_pays');
            // Deux organismes au même nom dans un pays seraient indiscernables dans la liste où un
            // citoyen choisit le sien — et le choix porte sur ce qu'il lit, pas sur un identifiant.
            $table->unique(['pays_code', 'nom'], 'uq_organisme_nom_pays');
            $table->index(['pays_code', 'type', 'actif'], 'idx_organisme_pays_type');
        });

        // ── Le contrat déclaré par un citoyen ─────────────────────────────────────────────────────
        Schema::create('couvertures_membre', function (Blueprint $table) {
            $table->id();
            $table->foreignId('membre_id')->constrained('membres_famille')->cascadeOnDelete();

            // ═══ `restrictOnDelete` ET NON `nullOnDelete` ═══
            //
            // Supprimer un organisme qui couvre des assurés doit ÉCHOUER BRUYAMMENT. En `nullOnDelete`
            // les couvertures survivraient en désignant le vide, et personne ne saurait chez qui ces
            // gens étaient assurés. Le chemin normal est la DÉSACTIVATION (`actif`), pas la
            // suppression — comme pour `maladies`.
            $table->foreignId('organisme_assurance_id')->nullable()
                ->constrained('organismes_assurance')->restrictOnDelete();

            // ═══ LE REPLI HORS RÉFÉRENTIEL — 3ᵉ APPLICATION DU MOTIF E4 ═══
            //
            // Le registre livré est un jeu de démonstration : ses lacunes sont CERTAINES. Imposer le
            // référentiel rendrait indéclarable la couverture d'un citoyen réellement assuré — le
            // « mur » refusé en P6.8c (*un contrôle qu'on ne peut pas satisfaire n'est pas une
            // exigence*).
            //
            // DIFFÉRENCE AVEC L'ALERTE ÉPIDÉMIQUE, et c'est pourquoi la question méritait d'être
            // reposée : là-bas la porte reste ouverte parce qu'une maladie émergente **n'est dans
            // aucune nomenclature** ; ici, parce que **notre registre est incomplet**. Le premier est
            // structurel, le second est temporaire — et c'est justement pour cela que l'écart est
            // COMPTÉ : il doit tendre vers zéro à mesure que le registre réel est chargé.
            $table->string('organisme_libelle', 200)->nullable();

            // Chiffré au repos et masqué à la lecture, exactement comme `cmu_numero` (§6.1 Sécurité).
            $table->text('numero_assure')->nullable();

            $table->date('date_debut')->nullable();
            $table->date('date_fin')->nullable();
            // Une résiliation est un FAIT DATÉ, pas un statut : on garde la ligne, on ne l'efface pas.
            $table->date('resiliee_le')->nullable();

            // ═══ LA PROVENANCE (décision propriétaire F2) ═══
            //
            // `verifie` est RÉSERVÉ ET PRÊT À ACTIVER : l'étape 2 du §8.1 du CDC_06 (« le système
            // vérifie son éligibilité, API CNAM ») n'existe pas, et rien dans ce projet ne peut
            // l'inventer. **Aucun chemin d'écriture ne peut poser cette valeur** — un vecteur le
            // prouve, et c'est ce qui la distingue d'une colonne décorative.
            //
            // POURQUOI ELLE EXISTE QUAND MÊME : sans elle, l'écran dirait « statut déclaré » comme un
            // commentaire ; avec elle, il le dit comme une DONNÉE. Le jour où une vérification
            // existera, la distinction sera déjà portée par les lignes antérieures.
            $table->enum('provenance', ['declare', 'verifie'])->default('declare');
            $table->timestamp('verifiee_le')->nullable();

            $table->timestamps();

            $table->index(['membre_id', 'organisme_assurance_id'], 'idx_couverture_membre');
        });

        $this->poserLesGardesDuMoteur();
    }

    /**
     * TROIS GARDES, ET AUCUNE N'EST DÉCORATIVE.
     *
     * 1. Un agrément qui finit avant de commencer.
     * 2. Une couverture qui finit avant de commencer.
     * 3. Une couverture qui ne nomme aucun organisme — ni par le référentiel, ni en clair. Elle
     *    n'affirmerait rien : « je suis assuré » sans dire chez qui n'est pas une information.
     *
     * ═══ POURQUOI DES DÉCLENCHEURS ET NON DES `CHECK` ═══
     *
     * La garde 3 vise `organisme_assurance_id`, qui porte une action référentielle — c'est le mur
     * rencontré en P6.3 (MySQL 8.4, erreur 3823), cousin du 1215 de P6.1. Et SQLite refuse
     * `ALTER TABLE … ADD CONSTRAINT`, donc les gardes 1 et 2 n'existeraient pas dans la suite de
     * tests : *une garantie que les tests ne peuvent pas éprouver n'en est pas une*. Déclencheurs
     * dans les deux dialectes (CDC_04 §139), comme en P6.3, P6.6a, P6.7a, P6.8b et P6.8c.
     *
     * La condition est écrite en `IS NOT NULL AND … >` et non en `NOT(… <=)` : une comparaison
     * portant sur NULL ne déclencherait rien, et la violation passerait **sans bruit** (leçon P6.3).
     */
    private function poserLesGardesDuMoteur(): void
    {
        $mysql = DB::getDriverName() === 'mysql';

        foreach (['INSERT', 'UPDATE'] as $evenement) {
            $suffixe = strtolower($evenement);

            DB::unprepared($mysql
                ? "CREATE TRIGGER ck_agrement_dates_{$suffixe}
                   BEFORE {$evenement} ON organismes_assurance
                   FOR EACH ROW
                   BEGIN
                       IF NEW.agrement_debut IS NOT NULL
                          AND NEW.agrement_fin IS NOT NULL
                          AND NEW.agrement_debut > NEW.agrement_fin THEN
                           SIGNAL SQLSTATE '45000'
                               SET MESSAGE_TEXT = 'ck_agrement_dates';
                       END IF;
                   END"
                : "CREATE TRIGGER ck_agrement_dates_{$suffixe}
                   BEFORE {$evenement} ON organismes_assurance
                   BEGIN
                       SELECT RAISE(ABORT, 'ck_agrement_dates')
                       WHERE NEW.agrement_debut IS NOT NULL
                         AND NEW.agrement_fin IS NOT NULL
                         AND NEW.agrement_debut > NEW.agrement_fin;
                   END"
            );

            DB::unprepared($mysql
                ? "CREATE TRIGGER ck_couverture_{$suffixe}
                   BEFORE {$evenement} ON couvertures_membre
                   FOR EACH ROW
                   BEGIN
                       IF NEW.date_debut IS NOT NULL
                          AND NEW.date_fin IS NOT NULL
                          AND NEW.date_debut > NEW.date_fin THEN
                           SIGNAL SQLSTATE '45000'
                               SET MESSAGE_TEXT = 'ck_couverture_dates';
                       END IF;
                       IF NEW.organisme_assurance_id IS NULL
                          AND (NEW.organisme_libelle IS NULL OR NEW.organisme_libelle = '') THEN
                           SIGNAL SQLSTATE '45000'
                               SET MESSAGE_TEXT = 'ck_couverture_organisme';
                       END IF;
                   END"
                : "CREATE TRIGGER ck_couverture_{$suffixe}
                   BEFORE {$evenement} ON couvertures_membre
                   BEGIN
                       SELECT RAISE(ABORT, 'ck_couverture_dates')
                       WHERE NEW.date_debut IS NOT NULL
                         AND NEW.date_fin IS NOT NULL
                         AND NEW.date_debut > NEW.date_fin;
                       SELECT RAISE(ABORT, 'ck_couverture_organisme')
                       WHERE NEW.organisme_assurance_id IS NULL
                         AND (NEW.organisme_libelle IS NULL OR NEW.organisme_libelle = '');
                   END"
            );
        }
    }

    public function down(): void
    {
        foreach (['insert', 'update'] as $evenement) {
            DB::unprepared("DROP TRIGGER IF EXISTS ck_agrement_dates_{$evenement}");
            DB::unprepared("DROP TRIGGER IF EXISTS ck_couverture_{$evenement}");
        }

        Schema::dropIfExists('couvertures_membre');
        Schema::dropIfExists('organismes_assurance');
        Schema::dropIfExists('organisme_assurance_compteurs');
    }
};
