<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * P10b-2 — Sélecteur, ordre de priorité §3, conflits §8, journal d'exécution §10 (CDC_08 §13
 * étape 3 ; ADR-041 §B2).
 *
 * Migration STRICTEMENT ADDITIVE : `protocoles` reçoit une colonne, deux tables naissent, rien
 * n'est réécrit. Les huit tables de P10b-1 gardent leur schéma.
 *
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 * LES DEUX DERNIÈRES TABLES DU §4.4 QUI MANQUAIENT AU MOTEUR
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 *
 * `protocole_questions` / `protocole_reponses` naîtront en P10b-3 avec le questionnaire adaptatif.
 * Les créer vides serait le « socle à vide » refusé par la décision D3 de P6.3 — c'est la règle
 * que P10b-1 s'est appliquée à lui-même pour ces deux tables-ci.
 *
 * ═══ POURQUOI `protocole_applications` MAINTENANT, ALORS QUE b-1 AVAIT ARGUMENTÉ L'INVERSE ═══
 *
 * La migration de b-1 écrit : « en P10b-1 le seul consommateur est le triage, dont `triages` EST
 * déjà le registre : y ajouter une seconde ligne décrivant la même décision créerait deux
 * vérités ». C'était exact **tant qu'un seul protocole décidait**.
 *
 * Ce n'est plus le cas. `triages.protocole_code` porte UN code ; dès que plusieurs protocoles sont
 * évalués, l'estampille ne peut plus dire ce qui s'est passé — elle nomme celui qui a **emporté
 * l'action exclusive**, et elle est muette sur les autres, sur les divergences et sur ce qui a été
 * recommandé. Deux faits différents, deux endroits : ce n'est pas la même vérité écrite deux fois.
 *
 * ═══ CE JOURNAL-CI CONTIENT DU CONTENU CLINIQUE, CONTRAIREMENT AUX TROIS AUTRES ═══
 *
 * `referentiel_journal`, `signature_journal` et `protocole_journal` n'en contiennent aucun : deux
 * copies feraient deux vérités. Ici le §10 exige explicitement d'historiser « les recommandations
 * affichées » — un journal d'exécution qui tairait ce qui a été recommandé ne servirait à rien le
 * jour d'un litige, qui est sa seule raison d'être.
 *
 * Aucune donnée nouvelle n'est exposée pour autant : le niveau et l'orientation sont déjà dans
 * `triages`. La lecture est réservée aux rôles habilités.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ─────────────────────────────────────────────────────────────────────────────
        // 1. Le contexte d'application, en DONNÉE du protocole (§9.1).
        // ─────────────────────────────────────────────────────────────────────────────
        Schema::table('protocoles', function (Blueprint $table) {
            // Les contextes où ce protocole s'applique : `triage`, `consultation`, `urgence`.
            //
            // NULLABLE, et l'absence a un sens précis : un protocole qui ne dit pas quand il
            // s'applique n'est JAMAIS sélectionné automatiquement. Le contrôle qualité l'exige à
            // la publication, jamais avant — un brouillon en cours de rédaction ne l'a pas encore
            // (motif des métadonnées de b-1).
            //
            // Une valeur par défaut (`["triage"]`) aurait été pire que l'absence : elle aurait
            // rendu sélectionnable, en silence, un protocole dont personne n'a décidé du champ
            // d'application.
            $table->json('contextes_json')->nullable()->after('domaine');
        });

        // ─────────────────────────────────────────────────────────────────────────────
        // 2. Le journal d'exécution (§10) — chaîné.
        // ─────────────────────────────────────────────────────────────────────────────
        Schema::create('protocole_applications', function (Blueprint $table) {
            $table->id();

            // §9.1 : « trace_id ». Rendu à l'appelant, il lui permet de retrouver l'évaluation
            // exacte — c'est la poignée du §10 côté client.
            $table->uuid('trace_id')->unique();

            $table->string('contexte', 20)->index();
            $table->char('pays_code', 2)->default('CI');

            // ═══ QUI — trois identifiants, aucun redondant ═══
            //
            // `membre_id` : le dossier concerné, quand il y en a un. Un triage peut être ANONYME
            //               (le Module 1 l'autorise), et l'absence doit rester représentable.
            // `user_id`   : le compte qui a demandé l'évaluation.
            // `professionnel_id` : le soignant, quand un soignant est dans la boucle. Toujours NULL
            //               aujourd'hui : le triage citoyen n'en a pas. Le §10 le nomme, la colonne
            //               existe, et le fait qu'elle soit vide est une limite écrite, pas un
            //               oubli.
            //
            // ═══ CE SONT DES IDENTIFIANTS, PAS DES RELATIONS VIVANTES ═══
            //
            // Aucune clé étrangère, délibérément. Ces valeurs entrent dans l'empreinte de la
            // chaîne : une action référentielle qui les mettrait à NULL ferait crier
            // « entrée modifiée » sur un journal que PERSONNE n'a touché — il suffirait de
            // supprimer un compte, ce qui est un acte ordinaire et un droit (loi 2013-450).
            //
            // *Une chaîne qui crie au loup cesse d'être lue.* Le journal enregistre QUI était
            // concerné au moment des faits ; il n'entretient pas un lien avec une ligne qui
            // vit sa propre vie.
            //
            // Le défaut existe dans `protocole_journal` (b-1) et `referentiel_journal`
            // (P6.3), où `acteur_id` est `nullOnDelete` ET dans l'empreinte : la chaîne de
            // gouvernance de ce projet est rompue depuis que le G2 de b-1 a supprimé ses
            // comptes temporaires. Constat fait ici, correction hors périmètre — on ne
            // « répare » pas une chaîne de hachage, et la rouvrir demande une décision.
            $table->unsignedBigInteger('membre_id')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('professionnel_id')->nullable();

            // Le triage d'où vient l'évaluation, quand elle vient d'un triage. Sans clé
            // étrangère non plus : le journal survit au triage supprimé, et son empreinte aussi.
            $table->unsignedBigInteger('triage_id')->nullable();

            // ═══ QUOI — la version EXACTE, exigence médico-légale du §6.1 et du §10 ═══
            //
            // Le protocole qui a emporté l'action exclusive. NULL est un cas légitime : aucun
            // protocole sélectionné n'a produit d'action exclusive.
            $table->string('protocole_retenu_code', 60)->nullable();
            $table->unsignedInteger('protocole_retenu_version')->nullable();

            // Tous les protocoles évalués, avec leur version, leur rang §3, leur niveau de preuve
            // et leur date de publication — de quoi rejouer le départage sans rien redemander à la
            // base, dont le contenu aura changé.
            $table->json('protocoles_json');

            // §10 « recommandations affichées ».
            $table->json('recommandations_json');

            // ═══ §10 — LA DÉCISION FINALE ET L'ÉCART, ÉCRITS UNE FOIS OU JAMAIS ═══
            //
            // Le journal est append-only : ces colonnes se remplissent À LA CRÉATION, quand
            // l'appelant est un professionnel qui énonce sa décision dans la même requête. Une
            // décision prise PLUS TARD ne se rattrapera pas par un `UPDATE` — ce serait réécrire
            // le passé, exactement ce qu'un journal immuable interdit — mais par une NOUVELLE
            // entrée de la chaîne.
            //
            // Aujourd'hui elles restent vides : le triage citoyen n'a personne pour décider.
            $table->string('decision_finale', 200)->nullable();
            $table->text('ecart_justification')->nullable();

            // La chaîne (§10 « journal immuable », CDC_10) — motif `protocole_journal`.
            $table->char('empreinte', 64);
            $table->char('empreinte_precedente', 64)->nullable();

            $table->timestamp('cree_le');

            $table->index(['protocole_retenu_code', 'cree_le'], 'idx_application_protocole_date');
            $table->index(['membre_id', 'cree_le'], 'idx_application_membre_date');
        });

        // ─────────────────────────────────────────────────────────────────────────────
        // 3. Les divergences constatées (§4.4, §8).
        // ─────────────────────────────────────────────────────────────────────────────
        Schema::create('protocole_conflits', function (Blueprint $table) {
            $table->id();

            $table->foreignId('application_id')
                ->constrained('protocole_applications')->cascadeOnDelete();

            // L'action sur laquelle les deux protocoles divergent — forcément EXCLUSIVE, sinon il
            // n'y a pas divergence mais cumul.
            $table->string('action_type', 40);

            // ═══ LES DEUX CÔTÉS SONT CONSERVÉS, PAS SEULEMENT LE GAGNANT ═══
            //
            // Le §8 exige que « toutes les divergences soient consignées afin de garantir la
            // transparence des décisions », et qu'un conflit non résolu soit « présenté au médecin
            // avec LES DEUX recommandations et leurs sources ». Ne garder que la valeur retenue
            // ferait disparaître la moitié dont on a précisément besoin pour comprendre.
            $table->string('valeur_retenue', 200)->nullable();
            $table->string('protocole_retenu_code', 60);
            $table->unsignedInteger('protocole_retenu_version');
            $table->string('source_retenue', 20);

            $table->string('valeur_ecartee', 200)->nullable();
            $table->string('protocole_ecarte_code', 60);
            $table->unsignedInteger('protocole_ecarte_version');
            $table->string('source_ecartee', 20);

            // Lequel des critères du §8 a départagé. `non_departage` existe pour que le cas se
            // DISE s'il survenait, plutôt que de se deviner à l'absence de valeur.
            $table->enum('critere', ['rang', 'niveau_preuve', 'recence', 'non_departage']);

            $table->timestamp('cree_le');

            $table->index(['application_id'], 'idx_conflit_application');
        });

        $this->ajouterGardesAppendOnly();
    }

    public function down(): void
    {
        $this->retirerGardesAppendOnly();

        Schema::dropIfExists('protocole_conflits');
        Schema::dropIfExists('protocole_applications');

        Schema::table('protocoles', function (Blueprint $table) {
            $table->dropColumn('contextes_json');
        });
    }

    /**
     * APPEND-ONLY AU MOTEUR — `UPDATE` et `DELETE` refusés, y compris en SQL direct.
     *
     * Le §10 exige un « journal immuable ». Une garde applicative (`saving`/`deleting` sur le
     * modèle) ne protège que le chemin Eloquent : elle est muette devant un client MySQL. La
     * chaîne de hachage rend l'altération DÉTECTABLE ; le déclencheur la rend IMPOSSIBLE par les
     * voies ordinaires. Les deux, parce qu'aucune ne rattrape l'autre.
     *
     * Motif `referentiel_journal` (P6.3) et `signature_journal` (P6.5b). Un `CHECK` n'aurait de
     * toute façon rien à exprimer ici : ce qu'on interdit n'est pas une valeur, c'est un
     * VERBE — `UPDATE` et `DELETE`. Seul un déclencheur sait refuser une opération.
     */
    private function ajouterGardesAppendOnly(): void
    {
        $mysql = DB::connection()->getDriverName() === 'mysql';

        foreach (['protocole_applications', 'protocole_conflits'] as $table) {
            foreach (['UPDATE', 'DELETE'] as $evenement) {
                $nom = 'ck_'.str_replace('protocole_', '', $table).'_append_only_'.strtolower($evenement);
                $message = $table.'_append_only';

                DB::unprepared($mysql
                    ? "CREATE TRIGGER {$nom}
                       BEFORE {$evenement} ON {$table}
                       FOR EACH ROW
                       BEGIN
                           SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$message}';
                       END"
                    : "CREATE TRIGGER {$nom}
                       BEFORE {$evenement} ON {$table}
                       BEGIN SELECT RAISE(ABORT, '{$message}'); END"
                );
            }
        }
    }

    private function retirerGardesAppendOnly(): void
    {
        foreach (['protocole_applications', 'protocole_conflits'] as $table) {
            foreach (['UPDATE', 'DELETE'] as $evenement) {
                $nom = 'ck_'.str_replace('protocole_', '', $table).'_append_only_'.strtolower($evenement);
                DB::unprepared("DROP TRIGGER IF EXISTS {$nom}");
            }
        }
    }
};
