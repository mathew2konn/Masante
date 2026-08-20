<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * P10b-3-i — Le questionnaire de triage devient un PROTOCOLE (CDC_08 §4.3b, §4.4 ; §13 étape 4 ;
 * CDC_04 §115 ; ADR-041 §B3).
 *
 * Migration STRICTEMENT ADDITIVE. `symptomes` garde son schéma : `questions_complementaires_json`
 * n'est ni supprimée ni vidée — elle cesse seulement d'être publiée et d'être écrite (ADR-024,
 * précédents `specialite_hint` en P10a, `vaccinations.statut` en P6.8b, `cmu_*` en P6.8d).
 *
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 * POURQUOI CES TABLES NAISSENT ICI ET PAS EN P10b-1
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 *
 * La migration de b-1 l'écrit noir sur blanc : `protocole_questions` / `protocole_reponses`
 * naissent « avec leur consommateur, parce qu'une table vide est le socle à vide que la décision
 * D3 de P6.3 a refusé ». Leur consommateur est cet incrément.
 *
 * ═══ CE QUE CE DÉMÉNAGEMENT REFERME (constat X3 du G0) ═══
 *
 * Le référentiel des symptômes portait ceci, en donnée :
 *
 *     ['cle' => 'fievre_sup_40', 'type' => 'booleen',
 *      'impact' => ['points_si_vrai' => 15, 'drapeau_rouge_si_vrai' => true]]
 *
 * C'est **mot pour mot** le contre-exemple du §1.2 (`if temperature > 39: urgence = True`). Un
 * drapeau rouge force le niveau `urgence` : cette ligne décide autant que la bande de score que
 * P10b-1 a sortie du code. Elle était gouvernée par le quatre-yeux du §10 — deux signatures
 * administratives — mais **jamais validée** au sens du §7, qui en exige quatre dont la clinique et
 * la réglementaire. b-1 a soumis les seuils de niveau à ces quatre validations et a laissé
 * celle-ci derrière : c'est cette asymétrie que la présente migration referme.
 *
 * ═══ POURQUOI DES TABLES ET NON DEUX COLONNES JSON DE PLUS ═══
 *
 * `options` et `points_par_option` cohabitaient dans le même blob (constat X5) :
 *
 *     'options' => ['seche', 'grasse'],
 *     'impact'  => ['points_par_option' => ['seche' => 3, 'grasse' => 5]]
 *
 * **Deux listes du même fait.** Elles coïncidaient ; rien ne l'imposait. Une option présente dans
 * l'une et absente de l'autre marquait 0 point **sans bruit** ; une entrée d'impact sans option
 * était inatteignable. `protocole_reponses` avec `UNIQUE(question_id, valeur)` rend cette
 * divergence **inexprimable** plutôt qu'interdite — le geste de P6.8c, où l'absence de colonne
 * `type` empêche d'écrire une seconde vérité.
 *
 * ═══ ET POURQUOI AUCUNE COLONNE « CONDITION » SUR LA QUESTION (décision R1) ═══
 *
 * La conditionnalité du §4.3b est portée par les RÈGLES de b-1, via l'action `POSER_QUESTION` :
 *
 *     SI symptome_categorie contient « respiratoire »  ALORS POSER_QUESTION(au_repos)
 *
 * Une colonne `condition_json` serait évaluée par un second chemin — donc une seconde façon
 * d'écrire une condition, capable de diverger de la première. Et un `question_id` nullable ajouté
 * à `protocole_conditions` avec un `CHECK` d'exclusivité serait **impossible sous MySQL 8.4**
 * (erreur 3823 : colonne soumise à une action référentielle — le mur de P6.3, cousin du 1215 de
 * P6.1).
 *
 * Conséquence heureuse et vérifiable : `MoteurProtocole` n'est **pas modifié** par cet incrément.
 * C'est le test de la conception — si le moteur avait dû bouger, c'est que l'arborescence n'était
 * pas une règle.
 */
return new class extends Migration
{
    /**
     * Les types de question repris à l'identique du questionnaire actuel (F1.2).
     *
     * Ils ne sont pas inventés : ce sont ceux que `QuestionsScreen` sait déjà rendre et que le
     * seeder des symptômes utilise depuis le Module 1. En ajouter serait livrer un contrôle que
     * personne n'affiche.
     */
    private const TYPES = ['nombre', 'echelle', 'booleen', 'choix'];

    public function up(): void
    {
        // ─────────────────────────────────────────────────────────────────────────────
        // 1. Les questions d'une VERSION de protocole (§4.4 `protocole_questions`).
        // ─────────────────────────────────────────────────────────────────────────────
        Schema::create('protocole_questions', function (Blueprint $table) {
            $table->id();

            // Rattachée à la VERSION, jamais au protocole : corriger l'énoncé d'une question doit
            // produire une nouvelle version relue et signée, pas un UPDATE discret sur une version
            // en vigueur. C'est la granularité que le §6.1 impose et que W5 du G0 de P10b a
            // établie face au socle P6.3.
            $table->foreignId('version_id')->constrained('protocole_versions')->cascadeOnDelete();

            // La clé stable par laquelle une condition désigne la réponse : `reponse.<cle>`.
            // Elle ne porte JAMAIS d'accent ni d'espace — le fait est un identifiant, pas une
            // phrase (précédent du code de spécialité en P6.8a, où `iconv('ASCII//TRANSLIT')`
            // dépendait du locale et produisait `gyn_ecologie` sur ce poste).
            $table->string('cle', 60);

            // Ce que le patient LIT. Figé dans l'instantané comme tout le reste.
            $table->string('libelle', 300);

            $table->enum('type', self::TYPES);

            // `nombre` seulement — « jours », « °C ». Purement d'affichage.
            $table->string('unite', 30)->nullable();

            // `echelle` seulement. Ce sont ces bornes que le serveur rendra OPPOSABLES (R7) :
            // jusqu'ici le référentiel publiait `min:1 max:10` et `TriageService` ne les
            // regardait pas — `intensite = 100` produisait 120 points (constat X4).
            $table->unsignedSmallInteger('valeur_min')->nullable();
            $table->unsignedSmallInteger('valeur_max')->nullable();

            $table->unsignedSmallInteger('ordre')->default(1);

            $table->timestamps();

            // Une clé une seule fois par version : sinon `reponse.intensite` désignerait deux
            // énoncés et le fait serait ambigu au moment même où une règle s'appuie dessus.
            $table->unique(['version_id', 'cle'], 'uq_protocole_question_cle');
            $table->index(['version_id', 'ordre'], 'idx_protocole_question_ordre');
        });

        // ─────────────────────────────────────────────────────────────────────────────
        // 2. Les réponses POSSIBLES d'une question (§4.4 `protocole_reponses`).
        // ─────────────────────────────────────────────────────────────────────────────
        //
        // ═══ LE §4.4 NOMME LA TABLE, IL NE DIT PAS CE QU'ELLE CONTIENT ═══
        //
        // Deux lectures étaient possibles : les réponses POSSIBLES d'une question, ou les réponses
        // DONNÉES par un patient. La seconde ferait doublon avec `triage_reponses` que le §115 de
        // CDC_04 exige par ailleurs — deux tables pour le même fait, la « deux vérités » que ce
        // projet refuse depuis P6.6a. C'est donc la première.
        Schema::create('protocole_reponses', function (Blueprint $table) {
            $table->id();

            $table->foreignId('question_id')->constrained('protocole_questions')->cascadeOnDelete();

            // La valeur transmise par le client et comparée par les règles (`seche`, `grasse`).
            $table->string('valeur', 60);

            // Ce que le patient LIT sur le bouton. Séparé de la valeur : renommer « Toux sèche »
            // en « Toux non productive » ne doit pas casser les règles qui comparent `seche`.
            $table->string('libelle', 200);

            $table->unsignedSmallInteger('ordre')->default(1);

            $table->timestamps();

            // ═══ C'EST CET INDEX QUI REFERME X5 ═══
            //
            // Les deux listes parallèles deviennent une seule ligne : une réponse possible EST son
            // libellé. Il n'y a plus d'option sans impact ni d'impact sans option, parce qu'il n'y
            // a plus deux endroits où l'écrire.
            $table->unique(['question_id', 'valeur'], 'uq_protocole_reponse_valeur');
            $table->index(['question_id', 'ordre'], 'idx_protocole_reponse_ordre');
        });

        // ─────────────────────────────────────────────────────────────────────────────
        // 3. Les réponses DONNÉES par un patient (CDC_04 §115 `triage_reponses`).
        // ─────────────────────────────────────────────────────────────────────────────
        Schema::create('triage_reponses', function (Blueprint $table) {
            $table->id();

            $table->foreignId('triage_id')->constrained('triages')->cascadeOnDelete();

            $table->string('question_cle', 60);

            // ═══ LE LIBELLÉ EST FIGÉ, JAMAIS RÉSOLU À LA LECTURE ═══
            //
            // Motif établi : P6.6b fige la DCI et le dosage dans une ordonnance, P7-D2 copie
            // l'établissement dans le journal d'accès, P10a fige le libellé d'orientation dans
            // l'instantané. Republier le questionnaire ne doit pas réécrire ce qu'un patient a lu
            // le jour de son triage.
            $table->string('question_libelle', 300);

            // La valeur brute saisie, telle quelle. Pas de colonne typée : une échelle porte un
            // entier, un choix une chaîne, un booléen un booléen — trois colonnes dont deux
            // toujours vides, et il faudrait décider laquelle fait foi (raisonnement de
            // `valeur_json` sur les conditions, P10b-1).
            $table->string('valeur', 200);

            // ═══ IL N'Y A DÉLIBÉRÉMENT PAS DE COLONNE `points` ═══
            //
            // Le plan G1 en prévoyait une, « l'impact réellement retenu ». L'implémentation a
            // montré que cette valeur **n'existe plus** : depuis que l'impact est une RÈGLE (R3),
            // une seule règle peut porter sur plusieurs réponses —
            // `SI reponse.a = x ET reponse.b = y ALORS AJOUTER_SCORE 10` — et ces 10 points ne se
            // répartissent entre `a` et `b` par aucun partage défendable.
            //
            // Y écrire 0 serait une colonne qui ment par omission ; y écrire une part inventée
            // serait pire. L'explication du score vit là où le §10 l'a mise en P10b-2 : le journal
            // d'exécution, qui nomme les **règles déclenchées** — c'est-à-dire le vrai grain de la
            // décision.
            //
            // C'est le prix de la bascule, et il est cohérent : on ne peut pas à la fois sortir la
            // règle du code et continuer d'attribuer ses points réponse par réponse.

            // ═══ L'ESTAMPILLE DU §6.1, SUR LA RÉPONSE ELLE-MÊME ═══
            //
            // « Chaque décision clinique conserve la version exacte du protocole utilisée ».
            // `triages` porte déjà celle du protocole de NIVEAU (P10b-1) ; celle-ci porte le
            // protocole de QUESTIONNAIRE, qui est un autre protocole avec son propre cycle.
            // Les confondre rendrait un triage inexplicable dès que l'un des deux évolue.
            $table->string('protocole_code', 60)->nullable();
            $table->unsignedInteger('protocole_version')->nullable();

            $table->timestamps();

            // Une question répondue une seule fois par triage.
            $table->unique(['triage_id', 'question_cle'], 'uq_triage_reponse_question');
        });

        $this->poserLaGardeDesBornes();
    }

    /**
     * UNE ÉCHELLE DONT LE MINIMUM DÉPASSE LE MAXIMUM EST REFUSÉE PAR LE MOTEUR.
     *
     * Le contrôle qualité le signale déjà — mais seulement au moment de publier. Une échelle
     * incohérente pourrait donc vivre dans un brouillon et, pire, être servie telle quelle si un
     * jour un écran d'authoring en propose l'aperçu. *Une garantie qui ne tient qu'au chemin
     * applicatif n'en est pas une* : leçon du G2 de P6.6a, où l'index unique ne protégeait que le
     * couple déjà ordonné.
     *
     * `CHECK` impossible : `version_id` est `cascadeOnDelete`, donc soumise à une action
     * référentielle — **erreur 3823**, le mur de P6.3, cousin du 1215 de P6.1. D'où des triggers
     * dans les deux dialectes (CDC_04 §139).
     *
     * `COALESCE(cond, 1) = 0` et non `NOT(cond)` : les deux bornes sont NULL pour toute question
     * qui n'est pas une échelle, et une comparaison NULL ne déclencherait rien — la violation
     * passerait **sans bruit**, ce qui est précisément le défaut qu'on ferme.
     */
    private function poserLaGardeDesBornes(): void
    {
        $mysql = DB::getDriverName() === 'mysql';
        $condition = 'NEW.valeur_min IS NULL OR NEW.valeur_max IS NULL OR NEW.valeur_min < NEW.valeur_max';
        $nom = 'ck_protocole_question_bornes';

        foreach (['INSERT', 'UPDATE'] as $evenement) {
            $trigger = $nom.'_'.strtolower($evenement);

            DB::unprepared($mysql
                ? "CREATE TRIGGER {$trigger}
                   BEFORE {$evenement} ON protocole_questions
                   FOR EACH ROW
                   BEGIN
                       IF COALESCE(({$condition}), 1) = 0 THEN
                           SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$nom}';
                       END IF;
                   END"
                : "CREATE TRIGGER {$trigger}
                   BEFORE {$evenement} ON protocole_questions
                   WHEN COALESCE(({$condition}), 1) = 0
                   BEGIN SELECT RAISE(ABORT, '{$nom}'); END"
            );
        }
    }

    public function down(): void
    {
        foreach (['insert', 'update'] as $evenement) {
            DB::unprepared("DROP TRIGGER IF EXISTS ck_protocole_question_bornes_{$evenement}");
        }

        Schema::dropIfExists('triage_reponses');
        Schema::dropIfExists('protocole_reponses');
        Schema::dropIfExists('protocole_questions');
    }
};
