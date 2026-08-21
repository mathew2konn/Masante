<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * P10c-1 — Les constantes cliniques du §5.2 entrent dans le triage (CDC_05 §5.1/§5.2 ;
 * CDC_08 §4.3a ; ADR-043).
 *
 * Migration STRICTEMENT ADDITIVE : `triages`, `mesures_sante` et `symptomes` gardent leur schéma.
 *
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 * CE QUE CET INCRÉMENT REFERME, ET LE CODE L'AVAIT DÉJÀ ÉCRIT
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 *
 * `RegistreFaitsProtocole` porte ceci depuis P10b-1, avec sa condition de levée :
 *
 *     « Déclarer `temperature` ou `spo2` — que CDC_05 §5.2 cite — alors qu'aucun écran ne les
 *       collecte permettrait d'écrire une règle qui ne se déclencherait jamais. Ce serait publier
 *       une garantie inerte. Ces faits entreront quand leur collecte existera. »
 *
 * La collecte existe à partir d'ici. Le gain n'est pas l'IA (c'est P10c-2) : c'est que
 * `SI température ≥ 39,5 ET âge < 5 ALORS urgence` devient une **règle en base, versionnée, relue
 * et signée par quatre validateurs** (§7) — le contre-exemple littéral du §1.2 retourné à l'endroit.
 *
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 * POURQUOI UNE TABLE, ET POURQUOI PAS DANS `mesures_sante`
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 *
 * Écrire la température saisie au triage dans le carnet serait tentant. Ce serait ouvrir un
 * **4ᵉ chemin d'écriture** dans une table du carnet, avec sa question de rejeu et de suppression
 * par le patient — exactement ce que la décision W3 de P6.8b a refusé pour le calendrier vaccinal,
 * qui *répond et prévient mais n'écrit rien*.
 *
 * Ces valeurs décrivent **cet épisode de triage** : elles vivent avec lui, comme `triage_reponses`.
 *
 * Une table et non un blob JSON : précédent P6.6a (une interaction est une relation, deux JSON
 * diraient deux fois le même fait) et constat X5 de P10b-3-i (deux listes du même fait dans le même
 * blob finissent par diverger sans bruit).
 *
 * ═══ `type_mesure` EST UNE CHAÎNE ICI, ALORS QUE LES DEUX AUTRES TABLES ONT UN ENUM ═══
 *
 * `referentiels_mesure` et `mesures_sante` figent sept valeurs : leur écran de saisie propose une
 * liste fixe. Ici la liste des types collectables vient de la **version publiée** du référentiel —
 * c'est ce qui permet à un protocole d'interroger `constante.glycemie` sans une ligne de code.
 * Un ENUM rendrait cette propriété fausse.
 *
 * **Limite héritée, dite plutôt que déguisée** : l'ENUM de `referentiels_mesure` plafonne malgré
 * tout l'ensemble à ces sept types. Un huitième exigerait d'y toucher — la porte est ouverte de ce
 * côté-ci, elle reste fermée en amont.
 *
 * ═══ `mesure_id` EST UN IDENTIFIANT, PAS UNE RELATION VIVANTE ═══
 *
 * Aucune clé étrangère, délibérément : c'est la décision D1 d'ADR-042, prise il y a deux jours.
 * Si le patient supprime la mesure de son carnet, la constante du triage doit **conserver la trace
 * de ce qu'elle a repris** — une action référentielle la mettrait à NULL et effacerait de
 * l'archive le fait que cette valeur venait du carnet.
 *
 * Bénéfice second, et il est réel : `origine` et `mesure_id` n'étant soumis à aucune action
 * référentielle, la garde ci-dessous peut porter dessus sans rencontrer l'**erreur 3823** (le mur
 * de P6.3, cousin du 1215 de P6.1).
 */
return new class extends Migration
{
    public function up(): void
    {
        // ═══════════════════════════════════════════════════════════════════════════════════════
        // 1) LA FRAÎCHEUR EST UNE DONNÉE DU RÉFÉRENTIEL, PAS UN SEUIL DANS LE CODE
        // ═══════════════════════════════════════════════════════════════════════════════════════
        //
        // « Une température prise il y a trois mois n'est pas une température. » Combien de temps
        // une valeur du carnet reste-t-elle proposable au triage ? La réponse diffère radicalement
        // selon le type — quelques heures pour un pouls, plusieurs mois pour un poids — donc elle
        // ne peut pas être une constante de code, et elle ne peut pas être unique.
        //
        // NULLABLE, ET `null` VEUT DIRE « JAMAIS PRÉ-REMPLI ». C'est le sens sûr : une donnée
        // absente ne doit pas autoriser silencieusement la réutilisation d'une mesure ancienne.
        // Même discipline qu'en P6.5a — « un professionnel sans autorisation n'est pas
        // *probablement autorisé* ». Un défaut numérique aurait au contraire inscrit une décision
        // clinique dans une migration.
        Schema::table('referentiels_mesure', function (Blueprint $table) {
            $table->unsignedInteger('fraicheur_max_minutes')
                ->nullable()
                ->after('decimales');
        });

        // ═══════════════════════════════════════════════════════════════════════════════════════
        // 2) LES CONSTANTES D'UN TRIAGE
        // ═══════════════════════════════════════════════════════════════════════════════════════
        Schema::create('triage_constantes', function (Blueprint $table) {
            $table->id();

            $table->foreignId('triage_id')->constrained('triages')->cascadeOnDelete();

            // Voir l'en-tête : chaîne et non ENUM, pour que la liste vienne du référentiel publié.
            $table->string('type_mesure', 40);

            // Même précision que `mesures_sante.valeur` : une constante de triage et une mesure du
            // carnet portent le même fait clinique, elles ne doivent pas s'arrondir différemment.
            $table->decimal('valeur', 8, 2);

            // L'unité est FIGÉE à l'écriture, comme la DCI d'une ordonnance (P6.6b) et le libellé
            // d'une question (P10b-3-i). La résoudre à la lecture ferait qu'une correction du
            // référentiel changerait rétroactivement le sens d'une valeur enregistrée — 39,5 °C
            // deviendrait 39,5 d'autre chose, et rien ne le signalerait.
            $table->string('unite', 20);

            // D'où vient la valeur. Deux états seulement, et pas trois : ce qui atteint le moteur a
            // toujours été vu par le patient. `reprise_du_carnet` signifie « pré-remplie depuis le
            // carnet, puis validée », jamais « lue à son insu ».
            $table->enum('origine', ['saisie', 'reprise_du_carnet'])->default('saisie');

            // La mesure du carnet reprise. Identifiant nu — voir l'en-tête.
            $table->unsignedBigInteger('mesure_id')->nullable();

            // ═══ L'ESTAMPILLE (CDC_09 §10, CDC_04 §115) ═══
            //
            // Avec quelle version des seuils cette valeur a-t-elle été acceptée ? Sans elle, une
            // correction des bornes de plausibilité rendrait inexplicable qu'une valeur ait été
            // refusée hier et le soit encore, ou l'inverse. Même motif que
            // `mesures_sante.referentiel_version` (L2).
            $table->unsignedInteger('referentiel_version');

            $table->timestamps();

            // Un triage porte AU PLUS une valeur par constante. Sans cette contrainte, deux
            // températures pourraient coexister sur le même triage et le fait `constante.temperature`
            // vaudrait celle que le hasard de l'ordre aurait mise en dernier.
            $table->unique(['triage_id', 'type_mesure'], 'uq_triage_constante');
        });

        $this->poserLaGardeDeLOrigine();
    }

    /**
     * UNE CONSTANTE QUI SE DIT REPRISE DU CARNET DOIT DIRE LAQUELLE.
     *
     * Sans cette garde, une ligne pourrait affirmer venir du carnet en ne désignant rien : la
     * trace serait invérifiable, et c'est précisément la trace qui rend le pré-remplissage
     * défendable. *Un garde-fou qui ne tient qu'au chemin applicatif n'en est pas un* — leçon du
     * G2 de P6.6a, où l'index unique ne protégeait que le couple déjà ordonné.
     *
     * Déclencheurs dans les deux dialectes (CDC_04 §139) : SQLite refuse
     * `ALTER TABLE … ADD CONSTRAINT`, et n'écrire la garde qu'en MySQL la rendrait **vraie en
     * production et fausse en test** — divergence refusée en P6.8c (collation) et P6.8e (REGEXP).
     *
     * `COALESCE(cond, 0) = 0` et non `NOT(cond)` : `mesure_id` est NULL dans le cas nominal, et une
     * comparaison NULL ne déclencherait rien — la violation passerait **sans bruit**, ce qui est
     * exactement le défaut qu'on ferme.
     */
    private function poserLaGardeDeLOrigine(): void
    {
        $mysql = DB::getDriverName() === 'mysql';
        $condition = "NEW.origine <> 'reprise_du_carnet' OR NEW.mesure_id IS NOT NULL";
        $nom = 'ck_triage_constante_origine';

        foreach (['INSERT', 'UPDATE'] as $evenement) {
            $trigger = $nom.'_'.strtolower($evenement);

            DB::unprepared($mysql
                ? "CREATE TRIGGER {$trigger}
                   BEFORE {$evenement} ON triage_constantes
                   FOR EACH ROW
                   BEGIN
                       IF COALESCE(({$condition}), 0) = 0 THEN
                           SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$nom}';
                       END IF;
                   END"
                : "CREATE TRIGGER {$trigger}
                   BEFORE {$evenement} ON triage_constantes
                   WHEN COALESCE(({$condition}), 0) = 0
                   BEGIN SELECT RAISE(ABORT, '{$nom}'); END"
            );
        }
    }

    public function down(): void
    {
        foreach (['insert', 'update'] as $evenement) {
            DB::unprepared("DROP TRIGGER IF EXISTS ck_triage_constante_origine_{$evenement}");
        }

        Schema::dropIfExists('triage_constantes');

        Schema::table('referentiels_mesure', function (Blueprint $table) {
            $table->dropColumn('fraicheur_max_minutes');
        });
    }
};
