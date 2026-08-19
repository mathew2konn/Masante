<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * P10b-1 — Registre des protocoles médicaux : modèle de données, versionnage, cycle de vie,
 * validation multicouche (CDC_08 §4.4, §6, §7, §10 ; §13 étapes 1-2 ; ADR-041).
 *
 * Migration STRICTEMENT ADDITIVE. `symptomes`, `triages` et le socle référentiel gardent leur
 * schéma ; `triages` reçoit deux colonnes et son ENUM `niveau` est ÉLARGI, jamais réécrit.
 *
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 * POURQUOI CE REGISTRE N'EST PAS UNE ONZIÈME ENTRÉE DU SOCLE P6.3 (décision G1 N2)
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 *
 * Le socle versionne un RÉFÉRENTIEL ENTIER : une version en vigueur à la fois, un instantané JSON
 * global (sa décision D1, prise pour ne pas modifier les tables des modules déjà G5).
 *
 * CDC_08 demande l'inverse, et pas par préférence :
 *   - §6.1 la version appartient AU PROTOCOLE (`2026.2`), avec son propre historique et son état ;
 *   - §7  la validation appartient AU PROTOCOLE : quatre validations distinctes, chacune
 *         enregistrée avec validateur, rôle, date, avis, commentaires, et **opposable** ;
 *   - §4.4 nomme `protocole_versions` et `protocole_validations` comme des tables à part entière.
 *
 * Sous le socle, corriger une posologie du paludisme républierait les vingt autres protocoles, et
 * le dossier de validation clinique de l'un serait rattaché à une version qui parle des autres.
 *
 * Ce registre est donc un FRÈRE du socle : mêmes principes (versionné, quatre-yeux, chaîne d'audit
 * à hachage, anti-substitution, instantané immuable), granularité différente parce que le corpus
 * l'impose. Même raisonnement qu'en P5.5c (service de rapprochement séparé pour garder l'auditeur
 * interne honnêtement « interne »).
 *
 * ═══ CE QUI NAÎT ICI, ET CE QUI NAÎT AILLEURS ═══
 *
 * Huit des onze tables du §4.4. Les trois autres naissent avec leur consommateur, parce qu'une
 * table vide est le « socle à vide » que la décision D3 de P6.3 a refusé :
 *   - `protocole_questions` / `protocole_reponses` → P10b-3 (questionnaire adaptatif) ;
 *   - `protocole_conflits`                        → P10b-2 (sélecteur et résolution §8) ;
 *   - `protocole_applications` (journal d'exécution §10) → P10b-2 également. En P10b-1 le seul
 *     consommateur est le triage, dont `triages` EST déjà le registre : y ajouter une seconde
 *     ligne décrivant la même décision créerait deux vérités sur un acte de santé. L'estampille
 *     va donc sur `triages`, exactement comme P10a y a posé `referentiel_version`.
 */
return new class extends Migration
{
    /** Les niveaux patient de CDC_05 §5.3, tels que `@masante/shared` les porte depuis P0. */
    private const NIVEAUX_PATIENT = ['faible', 'recommandee', 'rapide', 'urgence'];

    /** Les trois valeurs historiques du Module 1. Conservées : voir `elargirNiveaux()`. */
    private const NIVEAUX_HERITES = ['leger', 'modere', 'urgent'];

    public function up(): void
    {
        // ─────────────────────────────────────────────────────────────────────────────
        // 1. Le registre des protocoles (§4.1 métadonnées obligatoires).
        // ─────────────────────────────────────────────────────────────────────────────
        Schema::create('protocoles', function (Blueprint $table) {
            $table->id();

            // ═══ LE CODE EST LITTÉRAL ET CHOISI PAR L'AUTEUR — PAS `PROT000001` ═══
            //
            // `ETS`/`PRO`/`MED`/`ANA`/`VAC` numèrent des INSTANCES qu'on choisit dans une liste.
            // Un protocole se CITE : le contrat du §9.1 montre `PROT-CI-PALU-GRAVE`, un code
            // parlant, et le §4.2 une version `2026.2`. Générer un numéro séquentiel rendrait
            // l'exemple imposé du corpus impossible à représenter — c'est l'argument qui avait
            // tranché `MED000458` en P6.6a, ici dans l'autre sens.
            //
            // Il est IMMUABLE après création : il est inscrit dans `triages.protocole_code` et
            // dans le journal d'application (§10). Le renommer laisserait des décisions médicales
            // archivées désigner un protocole introuvable.
            $table->string('code', 60);

            // Le pays QUALIFIE le protocole, il n'a pas besoin d'être dans sa valeur (P6.4a). La
            // convention du corpus l'écrit quand même dans le code (`PROT-CI-…`) et on la respecte
            // au seeder ; la contrainte, elle, ne s'appuie jamais dessus.
            $table->char('pays_code', 2)->default('CI');

            $table->string('titre', 250);

            // §5 — les domaines couverts. `triage` en fait partie (§5.4) et c'est le seul que
            // P10b-1 publie (décision G1 N3).
            $table->enum('domaine', [
                'triage', 'infectieux', 'chronique', 'maternelle', 'infantile',
                'urgence', 'specialise',
            ])->index();

            // ═══ §3 — L'ORDRE DE PRIORITÉ ENTRE RÉFÉRENTIELS, EN DONNÉE ═══
            //
            // Le §3 impose : national > régional > OMS > sociétés savantes > hospitalier > IA.
            // La colonne naît ici parce que le §13 étape 1 est « le modèle de données » ; son
            // CONSOMMATEUR est le sélecteur de P10b-2. Poser la colonne maintenant évite de
            // remigrer une table de protocoles au module suivant.
            //
            // `ia` n'y figure PAS, et c'est délibéré : le §3 la classe sixième, mais un
            // raisonnement d'IA n'est pas un protocole — il n'a ni version, ni validation
            // clinique, ni référence bibliographique. L'y mettre laisserait croire qu'on peut
            // en enregistrer un.
            $table->enum('niveau_source', [
                'national', 'regional', 'oms', 'societe_savante', 'hospitalier',
            ])->default('national')->index();

            // §4.1 — auteur et organisme. Séparés : l'organisme engage, l'auteur rédige.
            $table->string('organisme', 200)->nullable();
            $table->string('auteur', 200)->nullable();

            // Le code du vocabulaire national (P6.8a), jamais un libellé libre. Pas de clé
            // étrangère : `specialites_medicales` est unique par (pays, code) et le protocole
            // porte déjà son pays — motif `laboratoire_code` en P6.7b. Le contrôle qualité
            // vérifie que le terme existe et qu'il est vivant.
            $table->string('specialite_code', 50)->nullable();

            $table->char('langue', 2)->default('fr');
            $table->json('mots_cles_json')->nullable();

            // Permet de retirer un protocole du catalogue sans le supprimer : ses versions
            // archivées doivent rester consultables indéfiniment (§6.1).
            $table->boolean('actif')->default(true)->index();

            $table->timestamps();

            $table->unique(['pays_code', 'code'], 'uq_protocole_pays_code');
        });

        // ─────────────────────────────────────────────────────────────────────────────
        // 2. Les versions : cycle de vie §6 + instantané compilé.
        // ─────────────────────────────────────────────────────────────────────────────
        Schema::create('protocole_versions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('protocole_id')->constrained('protocoles')->cascadeOnDelete();

            // ═══ DEUX IDENTIFIANTS DE VERSION, DEUX MÉTIERS DIFFÉRENTS ═══
            //
            // `numero`  : compteur monotone, technique, qui garantit un ordre total et l'unicité.
            // `libelle` : ce que le §6.1 et le §4.2 montrent à un humain (« 2.1 », « 2026.2 »).
            //
            // Ce n'est PAS une seconde vérité sur l'ordre : le §8 départage « le plus récent » par
            // la DATE de publication, jamais par le libellé. Personne ne compare deux libellés.
            $table->unsignedInteger('numero');
            $table->string('libelle', 30);

            // §6.1 — les trois états, tels que le corpus les nomme.
            $table->enum('etat', ['brouillon', 'actif', 'archive'])->default('brouillon');

            // Porte les deux invariants d'unicité (voir `ajouterContraintes`) :
            //   'B:<protocole_id>' quand etat = brouillon
            //   'A:<protocole_id>' quand etat = actif
            //   NULL               quand etat = archive
            $table->string('verrou_unicite', 40)->nullable();

            // §4.1 — métadonnées de la version. TOUTES NULLABLES : un brouillon en cours de
            // rédaction ne les a pas encore, et l'absence doit pouvoir se dire (précédent P6.4a).
            // Le contrôle qualité les EXIGE à la publication — pas avant.
            $table->enum('niveau_preuve', ['A', 'B', 'C', 'D'])->nullable();
            $table->string('population', 200)->nullable();
            $table->text('conditions_utilisation')->nullable();
            $table->date('date_expiration')->nullable();

            // L'INSTANTANÉ COMPILÉ : règles, conditions, actions et références figées sous la
            // forme que le moteur exécute (§11 « compilation des règles en structure exécutable »).
            // Rempli à la publication ; c'est lui qui rend une décision passée rejouable même si
            // les tables de travail ont changé depuis.
            $table->json('contenu_json')->nullable();
            $table->char('empreinte', 64)->nullable();

            $table->string('motif', 500);

            $table->foreignId('redige_par')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('redige_le');

            // Quatre-yeux (§10 « double validation pour la publication ») — garanti par déclencheur,
            // pas seulement par le service.
            $table->foreignId('publie_par')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('publie_le')->nullable();

            $table->timestamps();

            $table->unique(['protocole_id', 'numero'], 'uq_protocole_version_numero');
            $table->unique(['protocole_id', 'libelle'], 'uq_protocole_version_libelle');
            $table->unique('verrou_unicite', 'uq_protocole_version_verrou');
            $table->index(['protocole_id', 'etat'], 'idx_protocole_version_etat');
        });

        // ─────────────────────────────────────────────────────────────────────────────
        // 3. Les règles (§4.3a) : SI conditions ALORS actions.
        // ─────────────────────────────────────────────────────────────────────────────
        Schema::create('protocole_regles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('version_id')->constrained('protocole_versions')->cascadeOnDelete();

            // L'ordre EST une donnée clinique : « drapeau rouge » doit primer sur « score entre
            // 0 et 25 ». C'est ce qui remplace le `str_contains` de P10a et le `match` PHP de
            // `niveauDepuisScore()` — la priorité devient relue par deux agents (§7).
            $table->unsignedSmallInteger('ordre');

            // Ce qu'un relecteur clinique lit. Le §7 fait signer des médecins : leur montrer
            // `score>=76` sans phrase serait leur faire signer du code.
            $table->string('libelle', 300);

            $table->timestamps();

            $table->unique(['version_id', 'ordre'], 'uq_protocole_regle_ordre');
        });

        // ─────────────────────────────────────────────────────────────────────────────
        // 4. Les conditions d'une règle (§4.3a) — fait + opérateur + valeur.
        // ─────────────────────────────────────────────────────────────────────────────
        Schema::create('protocole_conditions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('regle_id')->constrained('protocole_regles')->cascadeOnDelete();
            $table->unsignedSmallInteger('ordre')->default(1);

            // ═══ TROIS COLONNES, TROIS LISTES BLANCHES FERMÉES ═══
            //
            // `fait` et `operateur` arrivent par la DONNÉE, donc par l'écran d'authoring. Sans
            // liste blanche, un opérateur deviendrait un choix libre du rédacteur, donc une porte —
            // c'est le raisonnement de `RegistreSectionsCarnet` (P7-C) et de `RegistreReferentiels`
            // (P6.3), où « le code arrive par l'URL ».
            //
            // Elles ne sont volontairement PAS des ENUM : la liste vit dans `RegistreFaitsProtocole`
            // et `RegistreOperateurs`, en PHP, parce qu'un fait doit être ACCOMPAGNÉ de son type et
            // de son extracteur — ce qu'une contrainte de colonne ne sait pas porter. Le contrôle
            // qualité refuse la publication d'un fait ou d'un opérateur inconnu.
            //
            // UN FAIT INCONNU NE S'ÉVALUE PAS À FAUX. C'est le point central du moteur : une
            // condition qui retombe silencieusement à faux rend un protocole inapplicable **sans
            // que rien ne le signale** — la panne invisible que P10a vient de refermer sur
            // l'orientation, et qu'on ne rouvre pas par la porte de derrière.
            $table->string('fait', 60);
            $table->string('operateur', 20);

            // JSON et non deux colonnes : `entre` porte [0, 25], `=` porte un scalaire, `existe`
            // ne porte rien. Deux colonnes `valeur_min`/`valeur_max` obligeraient à laisser l'une
            // vide dans la majorité des cas, et à décider laquelle fait foi.
            $table->json('valeur_json')->nullable();

            $table->timestamps();

            $table->index(['regle_id', 'ordre'], 'idx_protocole_condition_ordre');
        });

        // ─────────────────────────────────────────────────────────────────────────────
        // 5. Les actions d'une règle (§4.3a « ALORS Urgence, Hospitalisation… »).
        // ─────────────────────────────────────────────────────────────────────────────
        Schema::create('protocole_actions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('regle_id')->constrained('protocole_regles')->cascadeOnDelete();
            $table->unsignedSmallInteger('ordre')->default(1);

            // Liste blanche fermée elle aussi (`RegistreActionsProtocole`). Une action est ce que
            // le moteur RESTITUE ; en inventer une que personne ne sait interpréter produirait une
            // recommandation muette.
            $table->string('type', 40);
            $table->json('valeur_json')->nullable();

            // §9.1 — « justification ». Ce que le patient ou le médecin lit à côté de l'action.
            $table->string('justification', 300)->nullable();

            $table->timestamps();

            $table->index(['regle_id', 'ordre'], 'idx_protocole_action_ordre');
        });

        // ─────────────────────────────────────────────────────────────────────────────
        // 6. Les références bibliographiques (§4.1).
        // ─────────────────────────────────────────────────────────────────────────────
        Schema::create('protocole_references', function (Blueprint $table) {
            $table->id();
            $table->foreignId('version_id')->constrained('protocole_versions')->cascadeOnDelete();

            $table->enum('type', ['publication', 'recommandation', 'document', 'lien'])
                ->default('publication');

            $table->string('libelle', 400);
            $table->string('url', 500)->nullable();
            $table->string('citation', 500)->nullable();

            $table->timestamps();
        });

        // ─────────────────────────────────────────────────────────────────────────────
        // 7. Les validations §7 — quatre couches, opposables.
        // ─────────────────────────────────────────────────────────────────────────────
        //
        // APPEND-ONLY, ET SANS UNIQUE(version_id, type). Le §7 dit « opposable » : réécrire une
        // validation effacerait la trace de la première. Plusieurs signatures du même type peuvent
        // donc coexister — **la plus récente fait foi**, les précédentes racontent l'histoire.
        Schema::create('protocole_validations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('version_id')->constrained('protocole_versions')->cascadeOnDelete();

            // Les quatre du §7, dans l'ordre où il les énonce.
            $table->enum('type', ['clinique', 'reglementaire', 'scientifique', 'technique']);

            $table->foreignId('validateur_id')->nullable()->constrained('users')->nullOnDelete();

            // Dénormalisés : le §7 exige « validateur, rôle » et la pièce doit rester lisible
            // après la suppression d'un compte. Motif de `acteur_nom` (P6.3) et de
            // `etablissement` copié sur `acces_dossier` (P7-D2).
            $table->string('validateur_nom', 150);
            $table->string('validateur_role', 100);

            $table->enum('avis', ['favorable', 'reserve', 'defavorable']);
            $table->text('commentaires')->nullable();

            // ═══ L'ANTI-SUBSTITUTION VIT ICI, ET C'EST PLUS JUSTE QU'UNE COLONNE SUR LA VERSION ═══
            //
            // L'empreinte du contenu AU MOMENT DE LA SIGNATURE. À la publication, le contenu est
            // ré-extrait et son empreinte comparée à celle de chaque validation : si elles
            // diffèrent, le protocole a changé depuis qu'on l'a relu, et la publication est
            // refusée **en nommant la validation devenue caduque**.
            //
            // Transposition du contrôle central de P6.3 (« publier ce que personne n'a relu ») et
            // du « destination révoquée depuis le figeage » de P5.5b-2. Ici il porte plus loin
            // qu'ailleurs : ce que personne n'aurait relu, ce sont des règles cliniques.
            $table->char('empreinte_contenu', 64);

            $table->timestamp('valide_le');
            $table->timestamps();

            $table->index(['version_id', 'type'], 'idx_protocole_validation_type');
        });

        // ─────────────────────────────────────────────────────────────────────────────
        // 8. Le journal de GOUVERNANCE — append-only, chaîne de hachage globale (§10).
        // ─────────────────────────────────────────────────────────────────────────────
        //
        // ═══ DEUX JOURNAUX, DEUX NATURES, ET IL FAUT LE DIRE ═══
        //
        // Celui-ci trace QUI a écrit, validé, publié ou archivé un protocole. Il ne contient
        // AUCUN contenu clinique — même règle qu'en P6.3 : deux copies du contenu seraient deux
        // vérités, et l'instantané de la version porte déjà ce qui a changé.
        //
        // Le journal d'EXÉCUTION (`protocole_applications`, §10, P10b-2) sera l'inverse : il
        // portera l'identifiant du patient, les recommandations affichées, la décision du médecin
        // et sa justification d'écart — **parce que le §10 l'exige pour l'audit médico-légal**.
        // Deux journaux, deux objets ; les confondre ferait perdre l'un ou l'autre.
        Schema::create('protocole_journal', function (Blueprint $table) {
            $table->id();

            $table->string('protocole_code', 60);
            $table->char('pays_code', 2);
            $table->foreignId('protocole_id')->nullable()
                ->constrained('protocoles')->nullOnDelete();

            $table->unsignedInteger('version_numero')->nullable();
            $table->string('action', 40);

            $table->foreignId('acteur_id')->nullable()->constrained('users')->nullOnDelete();

            // DANS L'EMPREINTE, et ce n'est pas décoratif : le test d'altération de P6.3 a prouvé
            // que sans lui, réécrire le nom d'un agent en « Système » ne rompait pas la chaîne —
            // or c'est ce nom qu'un humain lit.
            $table->string('acteur_nom', 150);

            $table->json('details_json')->nullable();

            $table->char('empreinte_precedente', 64)->nullable();
            $table->char('empreinte', 64)->unique();

            $table->timestamp('cree_le');

            $table->index(['protocole_code', 'cree_le'], 'idx_protocole_journal_code_date');
        });

        $this->ajouterContraintes();

        // ─────────────────────────────────────────────────────────────────────────────
        // 9. `triages` : l'estampille du protocole + les 4 niveaux patient.
        // ─────────────────────────────────────────────────────────────────────────────
        Schema::table('triages', function (Blueprint $table) {
            // §6.1 — « chaque décision clinique conserve la version exacte du protocole utilisée »,
            // exigence médico-légale non négociable, et CDC_04 §115 la nomme.
            //
            // NULLABLE ET JAMAIS RÉTROACTIVE : les triages d'avant P10b n'ont été jugés par aucun
            // protocole ; leur en attribuer un serait un mensonge d'archive (précédent exact de
            // `mesures_sante.referentiel_version` en L1+L2, et de `triages.referentiel_version`
            // en P10a).
            //
            // Le CODE est dénormalisé à côté du numéro : le journal doit rester lisible si le
            // protocole disparaît du registre.
            $table->string('protocole_code', 60)->nullable()->after('referentiel_version');
            $table->unsignedInteger('protocole_version')->nullable()->after('protocole_code');
        });

        $this->elargirNiveaux();
    }

    public function down(): void
    {
        $this->supprimerContraintes();

        Schema::table('triages', function (Blueprint $table) {
            $table->dropColumn(['protocole_code', 'protocole_version']);
        });

        $this->redefinirNiveaux(self::NIVEAUX_HERITES);

        Schema::dropIfExists('protocole_journal');
        Schema::dropIfExists('protocole_validations');
        Schema::dropIfExists('protocole_references');
        Schema::dropIfExists('protocole_actions');
        Schema::dropIfExists('protocole_conditions');
        Schema::dropIfExists('protocole_regles');
        Schema::dropIfExists('protocole_versions');
        Schema::dropIfExists('protocoles');
    }

    /**
     * ═══ L'ENUM EST ÉLARGI, JAMAIS RÉÉCRIT ═══
     *
     * `triages.niveau` portait les trois valeurs du Module 1. CDC_05 §5.3 en exige QUATRE côté
     * patient, et `@masante/shared` les porte depuis P0 sans que rien ne les consomme (constat W3
     * du G0). Les nouvelles s'ajoutent ; **les trois anciennes restent**.
     *
     * Convertir l'historique serait un mensonge d'archive : un patient a lu « MODÉRÉ » sur son
     * écran, et réécrire sa fiche en « RECOMMANDEE » changerait ce qu'il a réellement lu. C'est
     * exactement le raisonnement qui a laissé `specialite_requise` en place en P10a et
     * `referentiel_version` à NULL en L1+L2.
     *
     * Précédent de la manœuvre : P6.4a, `structures_sanitaires.type` passé de 7 à 13 valeurs sans
     * perdre une ligne.
     */
    private function elargirNiveaux(): void
    {
        $this->redefinirNiveaux(array_merge(self::NIVEAUX_HERITES, self::NIVEAUX_PATIENT));
    }

    /**
     * Motif éprouvé en P7-A (`delegations.droits`) puis P6.4a : MySQL accepte un `MODIFY` direct,
     * SQLite fige la liste dans un `CHECK` du `CREATE TABLE` et exige que Laravel reconstruise
     * la table.
     *
     * @param  array<int, string>  $valeurs
     */
    private function redefinirNiveaux(array $valeurs): void
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement(sprintf(
                'ALTER TABLE triages MODIFY niveau ENUM(%s) NOT NULL',
                implode(',', array_map(static fn (string $v): string => "'".$v."'", $valeurs))
            ));

            return;
        }

        Schema::table('triages', function (Blueprint $table) use ($valeurs) {
            $table->enum('niveau', $valeurs)->change();
        });
    }

    /**
     * Les invariants du cycle de vie §6, exprimés AU MOTEUR.
     *
     *  (a) le verrou d'unicité ne peut pas mentir sur l'état — au plus UN brouillon et UNE version
     *      active par protocole ;
     *  (b) quatre-yeux : le publieur n'est jamais le rédacteur (§10 « double validation ») ;
     *  (c) un brouillon n'a pas de date de publication ; toute version publiée en a une.
     *
     * ═══ UNE SEULE VERSION ACTIVE, ET LE CORPUS EST IMPRÉCIS SUR CE POINT ═══
     *
     * Le tableau du §6.1 montre `2.0 Active` ET `2.1 Active`. Deux versions actives du même
     * protocole rendraient « laquelle s'applique ? » insoluble — or c'est le §6.1 lui-même qui
     * exige que chaque décision conserve **la** version exacte utilisée, ce qui présuppose une
     * réponse unique. On tranche pour une seule version active, et on le dit plutôt que de laisser
     * croire que le corpus a été suivi à la lettre.
     *
     * POURQUOI DES DÉCLENCHEURS ET NON DES `CHECK` — le mur déjà rencontré trois fois :
     *   - MySQL 8.4 **erreur 3823** : un `CHECK` ne peut pas porter sur une colonne subissant une
     *     action référentielle, or `redige_par`/`publie_par` sont `nullOnDelete` (la gouvernance
     *     doit survivre à la suppression d'un compte) et `protocole_id` est `cascadeOnDelete`.
     *     Cousin de l'erreur 1215 de P6.1, identique au cas de P6.3.
     *   - SQLite refuse `ALTER TABLE … ADD CONSTRAINT`.
     *
     * `COALESCE(…, 0) = 0` et non `NOT(…)` : une comparaison avec NULL vaut NULL, et un test
     * `WHEN NULL` ne déclencherait rien — la violation passerait **sans bruit**.
     */
    private function ajouterContraintes(): void
    {
        $mysql = DB::connection()->getDriverName() === 'mysql';

        $conditions = [
            'ck_protocole_version_verrou' => $mysql
                ? "   (NEW.etat = 'brouillon' AND NEW.verrou_unicite = CONCAT('B:', NEW.protocole_id))
                   OR (NEW.etat = 'actif'     AND NEW.verrou_unicite = CONCAT('A:', NEW.protocole_id))
                   OR (NEW.etat = 'archive'   AND NEW.verrou_unicite IS NULL)"
                : "   (NEW.etat = 'brouillon' AND NEW.verrou_unicite = 'B:' || NEW.protocole_id)
                   OR (NEW.etat = 'actif'     AND NEW.verrou_unicite = 'A:' || NEW.protocole_id)
                   OR (NEW.etat = 'archive'   AND NEW.verrou_unicite IS NULL)",

            'ck_protocole_version_quatre_yeux' =>
                'NEW.publie_par IS NULL OR NEW.redige_par IS NULL OR NEW.publie_par <> NEW.redige_par',

            'ck_protocole_version_publication' =>
                "   (NEW.etat = 'brouillon' AND NEW.publie_le IS NULL)
                 OR (NEW.etat <> 'brouillon' AND NEW.publie_le IS NOT NULL)",
        ];

        foreach ($conditions as $nom => $condition) {
            foreach (['INSERT', 'UPDATE'] as $evenement) {
                $trigger = $nom.'_'.strtolower($evenement);

                DB::unprepared($mysql
                    ? "CREATE TRIGGER {$trigger}
                       BEFORE {$evenement} ON protocole_versions
                       FOR EACH ROW
                       BEGIN
                           IF COALESCE(({$condition}), 0) = 0 THEN
                               SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$nom}';
                           END IF;
                       END"
                    : "CREATE TRIGGER {$trigger}
                       BEFORE {$evenement} ON protocole_versions
                       WHEN COALESCE(({$condition}), 0) = 0
                       BEGIN SELECT RAISE(ABORT, '{$nom}'); END"
                );
            }
        }
    }

    private function supprimerContraintes(): void
    {
        foreach ([
            'ck_protocole_version_verrou',
            'ck_protocole_version_quatre_yeux',
            'ck_protocole_version_publication',
        ] as $nom) {
            foreach (['insert', 'update'] as $evenement) {
                DB::unprepared("DROP TRIGGER IF EXISTS {$nom}_{$evenement}");
            }
        }
    }
};
