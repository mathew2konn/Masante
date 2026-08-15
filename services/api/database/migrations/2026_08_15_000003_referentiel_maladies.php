<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * P6.8c — Référentiel national des maladies (CDC_09 §8, étape 8 du §14).
 *
 * ═══ LE DÉFAUT QUE CETTE MIGRATION OUTILLE ═══
 *
 * La maladie était nommée à CINQ endroits, dans autant de vocabulaires libres :
 *
 *   1. `Portail\AlerteEpidemiqueController::MALADIES` — sept libellés EN DUR alimentant un `<select>`
 *      pendant que la validation acceptait `required|string|max:100`. Le commentaire du code
 *      l'avouait : « champ libre malgré tout ». Le `<select>` RESSEMBLAIT à une contrainte.
 *   2. `alertes_epidemiques.maladie` — ce texte libre, affiché BRUT dans la bannière du mobile.
 *   3. `symptomes.maladies_probables_json` — libellés mêlant maladies, syndromes et un état
 *      physiologique (`Grossesse`), lus par PERSONNE sauf l'instantané publié du référentiel
 *      `symptomes_triage`. Hors périmètre : porteur P10 (voir `SourceSymptomesTriage`).
 *   4. `antecedents.description` — la maladie chronique en texte libre chiffré, montrée à un
 *      secouriste SANS authentification par `FicheVitaleService`.
 *   5. `vaccins.maladies_evitees` — texte, avec une promesse écrite désignant cet incrément.
 *
 * ═══ UNE MALADIE N'APPARTIENT À AUCUN PAYS (décision propriétaire E2) ═══
 *
 * PREMIÈRE RUPTURE ASSUMÉE avec `ETS`, `PRO`, `MED`, `ANA` et `VAC`, qui portent tous
 * `UNIQUE(pays_code, code)` parce qu'ils numérotent des objets NATIONAUX : cet hôpital-ci, ce
 * praticien-là. Le paludisme est le paludisme partout — c'est la raison d'être même d'une
 * classification internationale. Écrire `pays_code` sur une maladie affirmerait dans le schéma que
 * le paludisme ivoirien diffère du paludisme sénégalais.
 *
 * CE QUI EST NATIONAL, C'EST LA LISTE SOUS SURVEILLANCE, pas la maladie : les maladies à déclaration
 * obligatoire diffèrent d'un pays à l'autre. D'où `maladie_surveillance`, portée par pays — et
 * publiée dans le MÊME instantané que les maladies (motif des interactions de P6.6a et des strates
 * de P6.7a : les publier séparément laisserait une surveillance désigner une maladie absente de la
 * version en vigueur, donc une référence irrésoluble).
 *
 * ═══ `MAL` + 6, ET LE CODE CIM N'EST PAS CE CODE-LÀ ═══
 *
 * Le critère posé en P6.8b — « instance → numéro ; terme de nomenclature → code littéral » —
 * plaiderait ici pour `fievre_typhoide`, comme `orl` en P6.8a. Il ne s'applique pas, pour une raison
 * propre à ce référentiel : LA CIM OCCUPERA LA PLACE DU CODE LITTÉRAL le jour où elle sera chargée.
 * Fabriquer `fievre_typhoide` créerait un pseudo-code qui RESSEMBLE à un code de nomenclature et
 * devrait ensuite cohabiter avec `A01.0` — deux codes littéraux concurrents pour la même chose. Et
 * contrairement à P6.8a, il n'y a RIEN À ADOPTER : les valeurs en base sont des phrases accentuées
 * (« Fièvre typhoïde »), pas des codes.
 *
 * Donc : `code` = identifiant de LIGNE ; `code_cim10` / `code_cim11` = la NOMENCLATURE, et ils
 * resteront VIDES. CIM-10 et CIM-11 sont des publications de l'OMS : je n'invente pas de codes. Le
 * contrôle qualité n'en exige aucun — l'exiger rendrait le référentiel impubliable dès le premier
 * jour ; l'absence est COMPTÉE et AFFICHÉE, jamais transformée en blocage.
 *
 * ═══ CE QUE CETTE TABLE NE PORTE DÉLIBÉRÉMENT PAS ═══
 *
 * Pas de `categorie` (infectieuse / chronique / …) : AUCUN consommateur n'en a besoin, et classer
 * une maladie n'est pas gratuit — ce serait une affirmation clinique non sourcée ajoutée pour la
 * forme. Le seul regroupement réel (« que surveille-t-on ici ? ») est porté par la surveillance.
 * Ajouter une classification que personne ne lit serait le socle à vide refusé en P6.3-D3.
 *
 * Pas de compteur d'alertes ni de cas : la projection gouvernée prend la LIGNE ENTIÈRE, et elle ne
 * peut le rester que si rien n'écrit automatiquement dans la table (précaution de P6.8a, née du
 * `note_moyenne` de P6.4a).
 */
return new class extends Migration
{
    /** Les provenances admises — une seule nomenclature pour les trois tables du référentiel. */
    private const SOURCES = ['demonstration', 'autorite_nationale', 'oms', 'societe_savante', 'publication'];

    public function up(): void
    {
        // UNE SEULE LIGNE, ET C'EST LA CONSÉQUENCE DIRECTE DE E2. Le compteur des autres
        // référentiels est indexé par pays parce que leurs objets le sont ; celui-ci ne peut pas
        // l'être sans contredire « une maladie n'appartient à aucun pays ». La clé existe quand même
        // plutôt qu'une table à ligne unique sans clé : elle rend l'ordre de verrou de P6.1
        // (UPDATE d'abord, INSERT seulement si 0 ligne affectée) transposable tel quel.
        Schema::create('maladie_compteurs', function (Blueprint $table) {
            $table->string('cle', 20)->primary();
            $table->unsignedInteger('dernier')->default(0);
            $table->timestamps();
        });

        Schema::create('maladies', function (Blueprint $table) {
            $table->id();

            // Identité — HORS `$fillable` : un client ne choisit pas un code national, il le reçoit.
            // UNIQUE **GLOBAL** et non `(pays_code, code)` : voir l'en-tête, décision E2.
            $table->string('code', 12)->nullable();

            // LE LIBELLÉ OFFICIEL FRANÇAIS, et il vit ICI et nulle part ailleurs. C'est ce qui rend
            // la seconde vérité INEXPRIMABLE plutôt que simplement interdite : `maladie_libelles` ne
            // porte que des libellés ALTERNATIFS, aucune colonne ne peut y désigner un second
            // libellé officiel pour la langue pivot.
            //
            // UNIQUE : deux maladies distinctes portant le même libellé rendraient ambigus le
            // `<select>` de l'alerte et le rattachement par égalité exacte du backfill.
            $table->string('libelle', 200);

            // ═══ LA NOMENCLATURE, ET ELLE RESTERA VIDE ═══
            //
            // Troisième application du motif `analyses.loinc` (P6.7a) : la colonne existe, elle est
            // vide, et on le DIT. Charger la CIM sera de la donnée, zéro code — et tant que ce n'est
            // pas fait, ce n'est pas un référentiel national.
            $table->string('code_cim10', 10)->nullable();
            $table->string('code_cim11', 12)->nullable();

            $table->text('description')->nullable();

            // NON NULLE, garde centrale du module — 4ᵉ application après `analyse_references.source`
            // (P6.7a) et `calendrier_vaccinal.source` (P6.8b) : une entrée de référentiel sans
            // provenance est une rumeur, et le contrôle qualité refuse de publier un contenu qui en
            // contient. Le jeu livré est étiqueté `demonstration` et l'écran en affiche le compte.
            $table->enum('source', self::SOURCES);
            $table->string('source_detail', 200)->nullable();

            // On DÉSACTIVE, on ne supprime pas : des alertes et des antécédents la référencent.
            $table->boolean('actif')->default(true);

            $table->timestamps();

            $table->unique('code', 'uq_maladie_code');
            $table->unique('libelle', 'uq_maladie_libelle');
            $table->index('code_cim10');
            $table->index('actif');
        });

        // ── Les libellés alternatifs (décision E3, §8 « libellés multilingues ») ──────────────────
        //
        // Cette table ne porte PAS le libellé officiel français : il est sur la ligne `maladies`.
        // Elle porte les AUTRES langues et les synonymes de recherche (« palu » → Paludisme), c'est
        // tout. Aucune colonne `type` n'existe, donc aucune valeur ne peut prétendre à l'officialité
        // en concurrence de la ligne.
        Schema::create('maladie_libelles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('maladie_id')->constrained('maladies')->cascadeOnDelete();

            // Étiquette de langue courte (`en`, `dyu`, `bci`…). `fr` est LÉGITIME ici : un synonyme
            // français (« palu ») n'est pas un second libellé officiel, c'est une autre façon de
            // nommer la même chose — et c'est ce qui permet de retrouver la maladie à la saisie.
            $table->string('langue', 5);
            $table->string('libelle', 200);

            // Celui qu'on AFFICHE pour cette langue ; les autres ne servent qu'à retrouver.
            //
            // « EXACTEMENT UN PRINCIPAL PAR LANGUE » EST TENU PAR LE CONTRÔLE QUALITÉ, PAS PAR LE
            // MOTEUR : MySQL 8 n'a pas d'index unique partiel. C'est annoncé comme tel et non
            // déguisé en garantie du moteur — précédent du quota d'images de P6.4c.
            $table->boolean('principal')->default(false);

            $table->enum('source', self::SOURCES);
            $table->string('source_detail', 200)->nullable();

            $table->timestamps();

            $table->unique(['maladie_id', 'langue', 'libelle'], 'uq_maladie_libelle_langue');
            $table->index('langue');
        });

        // ── La surveillance, elle, est NATIONALE (décision E2) ────────────────────────────────────
        Schema::create('maladie_surveillance', function (Blueprint $table) {
            $table->id();
            $table->foreignId('maladie_id')->constrained('maladies')->cascadeOnDelete();
            $table->string('pays_code', 2);

            // Deux faits distincts, et les confondre serait faux : une maladie peut être surveillée
            // sans être à déclaration obligatoire, et l'inverse existe aussi.
            $table->boolean('declaration_obligatoire')->default(false);
            $table->boolean('surveillance_prioritaire')->default(false);

            $table->enum('source', self::SOURCES);
            $table->string('source_detail', 200)->nullable();

            $table->timestamps();

            // Une maladie n'a qu'un statut de surveillance par pays : deux lignes seraient deux
            // vérités sur la même question de santé publique.
            $table->unique(['maladie_id', 'pays_code'], 'uq_surveillance_maladie_pays');
            $table->index(['pays_code', 'surveillance_prioritaire'], 'idx_surveillance_pays');
        });

        // ── La promesse de P6.8b, tenue ───────────────────────────────────────────────────────────
        //
        // `referentiel_vaccins.php` disait : « TEXTE et non table de maladies : la CIM arrive en
        // P6.8c, et un lien vers une table qui n'existe pas encore serait une promesse, pas une
        // donnée. » La voici. `vaccins.maladies_evitees` est CONSERVÉE (ADR-024, additif) : elle
        // porte des formulations que le lien ne rend pas (« formes graves de… »), et une migration
        // destructive perdrait de l'information réelle pour un gain nul (précédent P6.4d-K2).
        Schema::create('vaccin_maladies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vaccin_id')->constrained('vaccins')->cascadeOnDelete();
            $table->foreignId('maladie_id')->constrained('maladies')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['vaccin_id', 'maladie_id'], 'uq_vaccin_maladie');
        });

        // ── Le lien de l'alerte épidémique (décision E4) ──────────────────────────────────────────
        //
        // FACULTATIF, et c'est une décision, pas un relâchement : une maladie émergente n'est dans
        // aucune nomenclature au moment où elle émerge, et le contenu livré est un jeu de
        // démonstration dont les lacunes sont certaines. Imposer le référentiel ferait payer ces
        // lacunes à une alerte URGENTE — argument de P6.6b. L'écart est COMPTÉ et AFFICHÉ.
        //
        // `maladie` (le libellé) est CONSERVÉE et reste la valeur lue par le mobile : quand un lien
        // est fourni, le serveur la REPREND du référentiel plutôt que du client.
        Schema::table('alertes_epidemiques', function (Blueprint $table) {
            $table->foreignId('maladie_id')->nullable()->after('maladie')
                ->constrained('maladies')->nullOnDelete();
            $table->string('maladie_code', 12)->nullable()->after('maladie_id');
        });

        // ── Le lien de l'antécédent — LE CONSOMMATEUR CLINIQUE ────────────────────────────────────
        //
        // `description` N'EST JAMAIS RÉÉCRITE. C'est la leçon de P6.7b, où la réécriture du
        // prescripteur inscrivait le nom du MAUVAIS médecin : le lien s'ajoute À CÔTÉ des mots du
        // patient, il ne les remplace pas. Et le serveur ne DEVINE jamais un code depuis ce texte —
        // ce serait un diagnostic posé par une machine (CDC_00 §4).
        //
        // Code ET libellé figés, comme en P6.6b et P6.7b : une correction ultérieure du référentiel
        // ne doit pas réécrire ce qui a été inscrit au carnet.
        Schema::table('antecedents', function (Blueprint $table) {
            $table->foreignId('maladie_id')->nullable()->after('type')
                ->constrained('maladies')->nullOnDelete();
            $table->string('maladie_code', 12)->nullable()->after('maladie_id');
            $table->string('maladie_libelle', 200)->nullable()->after('maladie_code');
        });

        $this->poserLaGardeDuLibelleOfficiel();
    }

    /**
     * UN LIBELLÉ ALTERNATIF NE PEUT PAS RECOPIER LE LIBELLÉ OFFICIEL DE SA PROPRE MALADIE.
     *
     * Le schéma rend déjà l'ambiguïté inexprimable (aucune colonne `type` ne permet de déclarer un
     * second libellé officiel) ; ce déclencheur ferme le dernier chemin : recopier « Paludisme » en
     * `fr` sous la ligne « Paludisme » créerait deux stockages de la même chaîne, donc deux endroits
     * à corriger le jour d'un renommage — *et le second serait oublié*.
     *
     * `CHECK` impossible : il devrait interroger une AUTRE table, ce qu'aucun dialecte n'autorise.
     * D'où des déclencheurs dans les deux dialectes (CDC_04 §139), comme en P6.3, P6.6a, P6.7a et
     * P6.8b.
     *
     * PORTÉE ANNONCÉE, pas devinée : le déclencheur garde les écritures de `maladie_libelles`. Le
     * cas inverse — renommer le libellé officiel pour qu'il rejoigne un alternatif existant — est
     * attrapé par le contrôle qualité à la publication, pas par le moteur. Les deux gardes ont deux
     * publics et aucune ne rattrape l'autre.
     *
     * ═══ LA COMPARAISON EST BINAIRE, ET LE G2 A MONTRÉ POURQUOI ═══
     *
     * Écrite en `=` simple, elle utilise la COLLATION de la colonne — insensible à la casse ET AUX
     * ACCENTS sous MySQL 8. « Cholera » (anglais) et « Choléra » (français) y sont donc ÉGAUX, et le
     * déclencheur refusait un libellé anglais parfaitement légitime : le seeder de démonstration
     * s'est arrêté sur `ERROR 1644` au premier vaccin traduit.
     *
     * SQLite compare octet à octet : la suite de tests n'a rien vu. *Un garde-fou plus strict que sa
     * propre règle est un défaut, même quand il refuse « par excès de prudence »* — ici il aurait
     * rendu le multilingue du §8 inutilisable pour toute langue proche du français.
     *
     * D'où `CAST(... AS BINARY)` : la règle écrite est « recopier la chaîne À L'IDENTIQUE », et
     * c'est exactement ce que le moteur vérifie maintenant. Le quasi-doublon (« paludisme » sous
     * « Paludisme ») reste attrapé par le contrôle qualité à la publication.
     */
    private function poserLaGardeDuLibelleOfficiel(): void
    {
        $mysql = DB::getDriverName() === 'mysql';
        $nom   = 'ck_libelle_alternatif_distinct';

        foreach (['INSERT', 'UPDATE'] as $evenement) {
            $trigger = $nom.'_'.strtolower($evenement);

            DB::unprepared($mysql
                ? "CREATE TRIGGER {$trigger}
                   BEFORE {$evenement} ON maladie_libelles
                   FOR EACH ROW
                   BEGIN
                       IF EXISTS (
                           SELECT 1 FROM maladies m
                           WHERE m.id = NEW.maladie_id
                             AND CAST(m.libelle AS BINARY) = CAST(NEW.libelle AS BINARY)
                       ) THEN
                           SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$nom}';
                       END IF;
                   END"
                : "CREATE TRIGGER {$trigger}
                   BEFORE {$evenement} ON maladie_libelles
                   BEGIN
                       SELECT RAISE(ABORT, '{$nom}')
                       WHERE EXISTS (
                           SELECT 1 FROM maladies m
                           WHERE m.id = NEW.maladie_id AND m.libelle = NEW.libelle
                       );
                   END"
            );
        }
    }

    public function down(): void
    {
        foreach (['insert', 'update'] as $evenement) {
            DB::unprepared("DROP TRIGGER IF EXISTS ck_libelle_alternatif_distinct_{$evenement}");
        }

        Schema::table('antecedents', function (Blueprint $table) {
            $table->dropConstrainedForeignId('maladie_id');
            $table->dropColumn(['maladie_code', 'maladie_libelle']);
        });

        Schema::table('alertes_epidemiques', function (Blueprint $table) {
            $table->dropConstrainedForeignId('maladie_id');
            $table->dropColumn('maladie_code');
        });

        Schema::dropIfExists('vaccin_maladies');
        Schema::dropIfExists('maladie_surveillance');
        Schema::dropIfExists('maladie_libelles');
        Schema::dropIfExists('maladies');
        Schema::dropIfExists('maladie_compteurs');
    }
};
