<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * P6.8e — Numéros d'urgence nationaux (CDC_09 §8, étape 8 du §14).
 *
 * ═══ LE DÉFAUT QUE CETTE TABLE FERME, ET IL EST ÉCRIT DANS LE CORPUS ═══
 *
 * CDC_02 §37 : « multi-pays par profil national (référentiels CDC_09, **rien en dur — y compris les
 * numéros d'urgence** : SAMU 185 en CI) ». L'exigence n'est pas déduite d'un principe général : elle
 * nomme ce site précis. C'est le seul incrément de P6 dans ce cas.
 *
 * Le G0 a trouvé QUATRE familles de sites, là où le plan de P6.8 en annonçait deux :
 *
 *   1. `TriageService::NUMERO_SAMU`                 — constante backend, 1 consommateur ;
 *   2. `apps/mobile/src/config/constants.ts`        — constante client, **5** consommateurs ;
 *   3. `packages/shared/src/i18n/{fr,en}.ts`        — « SOS 185 », **le numéro collé dans une chaîne
 *      de traduction**, et clé MORTE (aucun appelant) — le pire des sites : il a l'apparence de la
 *      source unique et se périme en silence ;
 *   4. six conseils cliniques seedés — de la DONNÉE, déjà publiée sous gouvernance depuis L1+L2.
 *
 * Le site (4) n'est **pas** refermé ici, et c'est dit plutôt que tu : republier des conseils
 * médicaux est un acte de gouvernance clinique, pas une correction technique. Porteur : **P10**,
 * qui refond déjà le triage (décision propriétaire C4).
 *
 * ═══ LE POINT DE CONCEPTION — CE RÉFÉRENTIEL N'EST PAS COMME LES NEUF AUTRES ═══
 *
 * Tous les référentiels de P6 répondent à « qu'est-ce qui fait autorité ? ». Celui-ci répond
 * d'abord à « **que compose-t-on quand plus rien ne fonctionne ?** ».
 *
 * Son consommateur central n'a **ni réseau, ni session, ni compte**, et c'est délibéré :
 * `CarteVitaleEcran` est atteignable depuis l'écran de CONNEXION, pour un secouriste qui ramasse le
 * téléphone d'un inconscient (FN2), et `urgence/sos.ts` dit noir sur blanc que « demander au serveur
 * qui alerter supposerait un réseau que l'on n'a précisément pas ».
 *
 * Les neuf référentiels précédents ont pu poser un **refus bruyant** (503 avant la v1 : L1+L2,
 * P6.8b, P6.8d) parce que leur consommateur était un écran ordinaire, en ligne et authentifié.
 * **Ici, un refus bruyant signifierait : pas de numéro d'urgence, dans une urgence.** Le motif ne se
 * recopie donc pas — mais il n'est pas abandonné pour autant :
 *
 *   - **le serveur reste honnête** : sans version publiée, l'API refuse comme les autres. Il ne sert
 *     JAMAIS la table de travail en se faisant passer pour le référentiel ;
 *   - **la résilience vit chez le client** : cache `SecureStore` (qui SURVIT à la déconnexion), puis
 *     valeur livrée avec l'application en dernier recours.
 *
 * *L'honnêteté est due à l'exploitant, la disponibilité au secouriste — et les deux tiennent
 * ensemble parce qu'elles ne vivent pas au même endroit.*
 *
 * ═══ PAS D'IDENTIFIANT GÉNÉRÉ, ET LA QUESTION A ÉTÉ REPOSÉE ═══
 *
 * `ETS` / `PRO` / `MED` / `ANA` / `VAC` / `ASS` numérotent des **instances**. « Le SAMU » n'est pas
 * une instance parmi d'autres SAMU : c'est un **terme de nomenclature**, comme `regions.code`
 * (P6.4a) et les codes de spécialité (P6.8a). Le code est donc littéral — `samu`, `police`,
 * `pompiers` — et il n'y a **ni compteur, ni commande de backfill** dans cet incrément.
 *
 * ═══ `UNIQUE(pays_code, code)` — RÉPONSE INVERSE À CELLE DE P6.8c, POUR LA RAISON INVERSE ═══
 *
 * P6.8c a rompu avec `pays_code` parce qu'une maladie est un fait de nature : le paludisme est le
 * paludisme partout. Un numéro d'urgence est exactement le contraire — il n'a **aucune existence
 * hors d'un pays** : il est attribué par un plan national de numérotation et ne veut rien dire
 * ailleurs. C'est le référentiel le plus national de tout P6.
 */
return new class extends Migration
{
    /**
     * Les provenances admises.
     *
     * `declaration_projet` est une valeur NEUVE, et elle existe pour une raison précise. Le jeu livré
     * contient trois numéros : le SAMU 185, que le corpus nomme dix fois, et **le 100 et le 180
     * déclarés par le propriétaire le 2026-08-15**. Aucun des trois n'a été confronté à un arrêté.
     *
     * Les ranger sous `autorite_nationale` affirmerait une vérification qui n'a pas eu lieu ; les
     * ranger sous `demonstration` dirait qu'ils sont inventés, ce qui serait faux aussi. La valeur
     * dit donc exactement ce qui s'est passé : *quelqu'un d'identifié les a déclarés, et personne ne
     * les a vérifiés.* Quatrième application du motif `analyses.loinc` (P6.7a), après `code_cim10`
     * (P6.8c) et `numero_agrement` (P6.8d) — mais poussée d'un cran : ici on ne compte pas seulement
     * ce qui manque, on **qualifie ce qui est là**.
     */
    private const SOURCES = ['demonstration', 'declaration_projet', 'autorite_nationale', 'publication'];

    public function up(): void
    {
        Schema::create('numeros_urgence', function (Blueprint $table) {
            $table->id();

            // Le pays QUALIFIE (précédent P6.4a) — mais ici il ne fait pas que qualifier : un numéro
            // d'urgence n'existe QUE dans un plan de numérotation national.
            $table->string('pays_code', 2)->default('CI');

            // Terme de nomenclature : `samu`, `police`, `pompiers`. HORS `$fillable`.
            $table->string('code', 40);

            // Ce qui est réellement composé. `string` et non un entier : un numéro d'urgence peut
            // porter un `+`, et un entier perdrait un zéro de tête au premier pays qui en a un.
            $table->string('numero', 20);

            $table->string('libelle', 120);

            // « Service d'aide médicale urgente ». Ce qu'un citoyen lit pour savoir LEQUEL composer —
            // et se tromper de numéro dans une urgence coûte des minutes.
            $table->string('description', 255)->nullable();

            // Ordre d'affichage métier (précédent `villes.ordre`, `specialites_medicales.ordre`).
            // Il n'est pas décoratif : c'est lui qui met le secours MÉDICAL en tête sur une
            // application de santé.
            $table->unsignedSmallInteger('ordre')->default(100);

            // On DÉSACTIVE, on ne supprime pas : l'historique d'un numéro retiré doit rester lisible.
            $table->boolean('actif')->default(true);

            // NON NULLE — 6ᵉ application après P6.7a, P6.8b, P6.8c et P6.8d.
            $table->enum('source', self::SOURCES);
            $table->string('source_detail', 200)->nullable();

            $table->timestamps();

            $table->unique(['pays_code', 'code'], 'uq_numero_urgence_pays_code');
            $table->index(['pays_code', 'actif', 'ordre'], 'idx_numero_urgence_diffusion');
        });

        $this->poserLaGardeDuMoteur();
    }

    /**
     * UNE SEULE GARDE DU MOTEUR, ET ELLE EST LA PLUS LITTÉRALE DU PROJET : **un numéro d'urgence
     * vide est un bouton qui ne compose rien.**
     *
     * ═══ POURQUOI UN DÉCLENCHEUR ═══
     *
     * Aucune colonne de cette table ne porte d'action référentielle, donc le mur de l'erreur 3823
     * (P6.3) ne s'applique pas et un `CHECK` serait possible en MySQL. Mais **SQLite refuse
     * `ALTER TABLE … ADD CONSTRAINT`** : la garde n'existerait alors pas dans la suite de tests, et
     * *une garantie que les tests ne peuvent pas éprouver n'en est pas une*. Déclencheurs dans les
     * deux dialectes (CDC_04 §139), comme en P6.3, P6.6a, P6.7a, P6.8b, P6.8c et P6.8d.
     *
     * ═══ CE QUE CETTE GARDE NE FAIT PAS, ET POURQUOI ═══
     *
     * Elle ne vérifie **pas** que le numéro est composable (pas de lettres). MySQL 8 sait le faire en
     * `REGEXP`, SQLite non sans extension : la garde serait **plus stricte en production qu'en
     * test**, exactement la divergence relevée en P6.8c avec la collation. Ce contrôle-là vit donc
     * dans `SourceNumerosUrgence::controlerQualite()`, où il est éprouvable — et le dire ici vaut
     * mieux que laisser croire que le moteur s'en charge.
     */
    private function poserLaGardeDuMoteur(): void
    {
        $mysql = DB::getDriverName() === 'mysql';

        foreach (['INSERT', 'UPDATE'] as $evenement) {
            $suffixe = strtolower($evenement);

            DB::unprepared($mysql
                ? "CREATE TRIGGER ck_numero_urgence_{$suffixe}
                   BEFORE {$evenement} ON numeros_urgence
                   FOR EACH ROW
                   BEGIN
                       IF NEW.numero IS NULL OR TRIM(NEW.numero) = '' THEN
                           SIGNAL SQLSTATE '45000'
                               SET MESSAGE_TEXT = 'ck_numero_urgence_vide';
                       END IF;
                   END"
                : "CREATE TRIGGER ck_numero_urgence_{$suffixe}
                   BEFORE {$evenement} ON numeros_urgence
                   BEGIN
                       SELECT RAISE(ABORT, 'ck_numero_urgence_vide')
                       WHERE NEW.numero IS NULL OR TRIM(NEW.numero) = '';
                   END"
            );
        }
    }

    public function down(): void
    {
        foreach (['insert', 'update'] as $evenement) {
            DB::unprepared("DROP TRIGGER IF EXISTS ck_numero_urgence_{$evenement}");
        }

        Schema::dropIfExists('numeros_urgence');
    }
};
