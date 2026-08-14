<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * P6.7a — Catalogue national des analyses (CDC_09 §7.3).
 *
 * ═══ CE QUE §7.3 DEMANDE, ET POURQUOI DEUX TABLES ═══
 *
 * « Code national, libellé, description, unité de mesure, **valeurs de référence**, méthode
 * analytique, conditions de prélèvement, temps de conservation, délai de rendu. »
 *
 * Tout tient dans une table SAUF les valeurs de référence, et c'est le point de conception du
 * module : une plage biologique **dépend de la personne**. L'hémoglobine de 11 g/dL est basse chez
 * l'homme adulte, normale chez la femme enceinte, normale chez l'enfant de deux ans. Une colonne
 * `reference_min` / `reference_max` unique dirait donc à une patiente que son résultat est anormal
 * alors qu'il est normal pour elle — avec l'autorité d'une machine, dans un carnet de santé.
 *
 * D'où `analyse_references` : une ligne par STRATE (sexe × tranche d'âge × état physiologique).
 * C'est cette structure qui rend le remplacement possible **sans migration** le jour où un
 * référentiel biologique réel sera chargé.
 *
 * ═══ LES ÉNUMÉRATIONS SONT RECOPIÉES ICI, DÉLIBÉRÉMENT ═══
 *
 * Convention posée en P6.5a : une migration est un acte d'archive, elle doit rester lisible quand
 * `App\Support\Analyses` aura évolué. Un test de parité casse le build si les deux divergent.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('analyse_compteurs', function (Blueprint $table) {
            $table->string('pays_code', 2)->primary();
            $table->unsignedInteger('dernier')->default(0);
            $table->timestamps();
        });

        Schema::create('analyses', function (Blueprint $table) {
            $table->id();

            // Identité nationale — hors `$fillable` : un client ne choisit pas un code national.
            $table->string('code', 12)->nullable();
            $table->string('pays_code', 2)->default('CI');

            // LOINC est le standard international recommandé par CDC_09 §9.1. LA COLONNE EST VIDE :
            // le jeu LOINC n'est pas en notre possession, et inventer des codes qui auraient l'air
            // vrais serait pire que de laisser la colonne nulle et de le dire.
            $table->string('loinc', 20)->nullable();

            $table->string('libelle', 200);
            $table->text('description')->nullable();

            $table->enum('categorie', [
                'hematologie', 'biochimie', 'immunologie', 'microbiologie', 'parasitologie',
                'virologie', 'hormonologie', 'genetique', 'anatomopathologie', 'toxicologie',
            ])->nullable();

            // LE MILIEU FAIT PARTIE DE L'IDENTITÉ DE L'ANALYSE, pas de son contexte : une glycémie
            // sur sang capillaire et sur plasma veineux sont deux entrées du catalogue.
            $table->enum('milieu_preleve', [
                'sang_veineux', 'sang_capillaire', 'serum', 'plasma', 'urine', 'urine_24h',
                'selles', 'lcr', 'expectoration', 'prelevement_local', 'tissu',
            ])->nullable();

            // L'unité est OBLIGATOIRE : un résultat sans unité n'est pas interprétable, et c'est
            // exactement l'incohérence que §7.3 veut supprimer.
            $table->string('unite', 40);

            $table->string('methode', 200)->nullable();
            $table->text('conditions_prelevement')->nullable();
            $table->text('conservation')->nullable();
            $table->unsignedSmallInteger('delai_rendu_heures')->nullable();

            $table->boolean('actif')->default(true);
            $table->timestamps();

            // Le pays QUALIFIE le code, il ne s'écrit pas dedans (précédents ETS, PRO, MED).
            $table->unique(['pays_code', 'code'], 'uq_analyse_code_pays');
            $table->index('categorie');
        });

        Schema::create('analyse_references', function (Blueprint $table) {
            $table->id();
            $table->foreignId('analyse_id')->constrained('analyses')->cascadeOnDelete();

            $table->enum('sexe', ['tous', 'M', 'F'])->default('tous');

            // EN JOURS, et non en années : la période néonatale se compte en jours, et plusieurs
            // paramètres changent dans la première semaine de vie. Nullable = borne ouverte.
            $table->unsignedInteger('age_min_jours')->nullable();
            $table->unsignedInteger('age_max_jours')->nullable();

            $table->enum('etat_physiologique', [
                'standard', 'grossesse_t1', 'grossesse_t2', 'grossesse_t3', 'nouveau_ne',
            ])->default('standard');

            // Bornes nullables d'un côté : « < 5 » et « > 60 » sont des références légitimes.
            $table->decimal('valeur_min', 12, 4)->nullable();
            $table->decimal('valeur_max', 12, 4)->nullable();

            // Valeurs critiques (§7.3 les distingue implicitement) : le seuil qui impose d'alerter,
            // différent de « hors norme ». STOCKÉES, jamais utilisées pour conclure ici.
            $table->decimal('critique_bas', 12, 4)->nullable();
            $table->decimal('critique_haut', 12, 4)->nullable();

            // Ce que le lecteur voit : « Femme adulte », « Grossesse — 3ᵉ trimestre ».
            $table->string('libelle_strate', 120);

            // NON NULLE, et c'est la garde centrale du module : un intervalle sans provenance est
            // une rumeur. Le contrôle qualité refuse de publier un catalogue qui en contient.
            $table->enum('source', [
                'demonstration', 'autorite_nationale', 'societe_savante', 'laboratoire', 'publication',
            ]);
            $table->string('source_detail', 200)->nullable();

            $table->timestamps();

            $table->index(['analyse_id', 'sexe']);
        });

        $this->poserLaGardeDesBornes();
    }

    /**
     * UNE BORNE BASSE SUPÉRIEURE À LA BORNE HAUTE EST REFUSÉE PAR LE MOTEUR.
     *
     * Le contrôle qualité le signale déjà à la publication — mais il ne s'applique qu'au moment de
     * publier. Une strate incohérente pourrait donc vivre des semaines dans le contenu de travail et
     * s'afficher à côté d'un résultat réel entre-temps. *Une garantie qui ne tient qu'au chemin
     * applicatif n'en est pas une* — leçon du G2 de P6.6a, où l'index unique ne protégeait que le
     * couple déjà ordonné.
     *
     * `CHECK` impossible : `analyse_id` est `cascadeOnDelete`, donc soumise à une action
     * référentielle — **erreur 3823**, le mur de P6.3. D'où des triggers dans les deux dialectes
     * (CDC_04 §139). `COALESCE(cond, 1) = 0` et non `NOT(cond)` : une borne NULL est légitime et ne
     * doit rien déclencher.
     */
    private function poserLaGardeDesBornes(): void
    {
        $mysql = DB::getDriverName() === 'mysql';
        $condition = 'NEW.valeur_min IS NULL OR NEW.valeur_max IS NULL OR NEW.valeur_min <= NEW.valeur_max';
        $nom = 'ck_analyse_reference_bornes';

        foreach (['INSERT', 'UPDATE'] as $evenement) {
            $trigger = $nom.'_'.strtolower($evenement);

            DB::unprepared($mysql
                ? "CREATE TRIGGER {$trigger}
                   BEFORE {$evenement} ON analyse_references
                   FOR EACH ROW
                   BEGIN
                       IF COALESCE(({$condition}), 1) = 0 THEN
                           SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$nom}';
                       END IF;
                   END"
                : "CREATE TRIGGER {$trigger}
                   BEFORE {$evenement} ON analyse_references
                   WHEN COALESCE(({$condition}), 1) = 0
                   BEGIN SELECT RAISE(ABORT, '{$nom}'); END"
            );
        }
    }

    public function down(): void
    {
        foreach (['insert', 'update'] as $evenement) {
            DB::unprepared("DROP TRIGGER IF EXISTS ck_analyse_reference_bornes_{$evenement}");
        }

        Schema::dropIfExists('analyse_references');
        Schema::dropIfExists('analyses');
        Schema::dropIfExists('analyse_compteurs');
    }
};
