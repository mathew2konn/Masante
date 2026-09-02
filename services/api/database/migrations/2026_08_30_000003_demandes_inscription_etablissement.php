<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * P11.1 — DEMANDES D'INSCRIPTION D'ÉTABLISSEMENT (CDC_11 §3, méthode 2).
 *
 * ═══ CE QUE CETTE TABLE FERME ═══
 *
 * CDC_11 §3 décrit deux méthodes d'onboarding et conclut « **Les deux méthodes sont
 * implémentées** ». C'était faux dans ce projet : seule la méthode 1 existait (l'administrateur
 * crée l'établissement). La méthode 2 — « Clinique Saint Joseph souhaite rejoindre la
 * plateforme » — est ouverte depuis **P6.4a** sous le nom de limite **M1**, et a été reportée
 * deux fois (M1 puis O1). *Une affirmation fausse du corpus cesse ici de l'être.*
 *
 * ═══ UNE DEMANDE N'EST PAS UN ÉTABLISSEMENT ═══
 *
 * Décision centrale : **aucune ligne n'est écrite dans `structures_sanitaires` avant
 * l'approbation.** La tentation inverse existe (créer l'établissement en « inactif » et le
 * publier ensuite) et elle est mauvaise pour une raison concrète : `structures_sanitaires` est
 * lue par l'annuaire **public** de P3, validé G5, par le référentiel gouverné de P6.4a et par
 * l'orientation après triage de P10a. Y déposer des candidats non vérifiés reviendrait à faire
 * dépendre l'exactitude d'un référentiel national du soin qu'on met à filtrer `actif` partout —
 * *un oubli de filtre, et un établissement que personne n'a vérifié apparaît dans les résultats
 * d'un patient qui cherche où se faire soigner.*
 *
 * Une demande est donc une **candidature**, dans sa propre table, avec son propre cycle. Elle ne
 * devient un établissement qu'au moment où quelqu'un d'habilité l'approuve — et ce moment
 * emprunte **le même chemin de création que la méthode 1** (`OnboardingEtablissementService`).
 *
 * ═══ CE QU'ELLE COLLECTE, ET POURQUOI SI PEU ═══
 *
 * Le formulaire d'établissement de P6.4d porte une trentaine de champs. On n'en demande ici
 * qu'une poignée, parce que CDC_11 §3 le dit lui-même : après validation, « **c'est l'hôpital
 * qui renseigne** » les médecins, les services, les horaires, les tarifs. Ce qu'il faut à ce
 * stade, c'est **de quoi vérifier** — et ce qui rend une demande vérifiable est le **numéro
 * d'autorisation** (CDC_11 §3.1, « informations légales »), que la plateforme confronte à
 * l'autorité de tutelle. Demander trente champs à quelqu'un dont on ignore encore s'il est
 * légitime, c'est décourager le légitime sans gêner l'autre.
 *
 * ═══ AUCUNE CLÉ ÉTRANGÈRE VERS `users` POUR LE DÉCIDEUR ═══
 *
 * `decide_par` est un **identifiant, pas une relation vivante** (D1 d'ADR-042) : supprimer un
 * compte est un droit (loi 2013-450) et ne doit pas effacer qui a approuvé une inscription. Le
 * nom est **dénormalisé** à côté, comme P7-D2 recopie l'établissement d'une visite — sans quoi
 * la trace ne dirait plus rien le jour où le compte disparaît.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('demandes_inscription_etablissement', function (Blueprint $table) {
            $table->id();

            // Référence opaque remise au demandeur : il n'a pas de compte, c'est son seul moyen
            // de suivre sa demande. Format `DEM-` + 10 caractères aléatoires — pas un compteur,
            // qui laisserait deviner le volume de candidatures et énumérer les autres demandes.
            $table->string('reference', 20)->unique();

            // ── L'établissement candidat ────────────────────────────────────────────────────
            $table->string('nom', 200);
            $table->string('type', 40);                       // catégorie (P6.4a) : chu, clinique…
            $table->string('statut_juridique', 40)->nullable(); // public / privé / confessionnel…
            $table->string('numero_autorisation', 60);         // CE QUI REND LA DEMANDE VÉRIFIABLE
            $table->string('adresse', 255);
            $table->string('commune', 100)->nullable();
            $table->string('telephone', 20);
            $table->string('email', 190);

            // ── Le demandeur (une personne, pas l'établissement) ────────────────────────────
            // Distincts des coordonnées ci-dessus : le standard d'un hôpital n'est pas la
            // personne qui répondra des informations déposées.
            $table->string('demandeur_nom', 100);
            $table->string('demandeur_prenom', 100);
            $table->string('demandeur_fonction', 120);
            $table->string('demandeur_email', 190);
            $table->string('demandeur_telephone', 20);

            $table->text('message')->nullable();

            // ── Cycle ───────────────────────────────────────────────────────────────────────
            $table->string('statut', 20)->default('en_attente'); // en_attente | approuvee | rejetee
            $table->text('motif_rejet')->nullable();

            $table->unsignedBigInteger('decide_par')->nullable(); // identifiant, PAS une FK
            $table->string('decide_par_nom', 150)->nullable();
            $table->timestamp('decide_le')->nullable();

            // L'établissement né de cette demande. `nullOnDelete` est ici correct et voulu :
            // si l'établissement est supprimé, la demande reste — elle raconte qu'une
            // candidature a été approuvée un jour, ce qui est vrai indépendamment.
            $table->foreignId('structure_id')->nullable()->constrained('structures_sanitaires')->nullOnDelete();

            $table->timestamps();

            $table->index(['statut', 'created_at']);
            $table->index('demandeur_email');
        });

        // ── GARDE DU MOTEUR ─────────────────────────────────────────────────────────────────
        // Un rejet sans motif et une approbation sans établissement sont deux incohérences que
        // le code ne doit pas être seul à empêcher : elles produiraient des lignes qui ne
        // veulent rien dire, et c'est en base qu'on les lira dans dix ans.
        //
        // `CHECK` impossible : `structure_id` subit une action référentielle (`SET NULL`), et
        // MySQL 8.4 refuse alors une contrainte de vérification portant dessus — **erreur
        // 3823**, le mur rencontré depuis P6.3 (cousin du 1215 de P6.1). Déclencheurs dans les
        // deux dialectes, avec `COALESCE(cond, 0) = 0` et non `NOT(cond)` : une comparaison sur
        // NULL ne déclencherait rien et la violation passerait **sans bruit**.
        $this->poserGardes();
    }

    public function down(): void
    {
        $this->retirerGardes();
        Schema::dropIfExists('demandes_inscription_etablissement');
    }

    private function poserGardes(): void
    {
        $pilote = Schema::getConnection()->getDriverName();

        if ($pilote === 'mysql') {
            foreach (['INSERT', 'UPDATE'] as $moment) {
                $nom = 'trg_demande_inscription_'.strtolower($moment);
                DB::unprepared("
                    CREATE TRIGGER {$nom} BEFORE {$moment} ON demandes_inscription_etablissement
                    FOR EACH ROW
                    BEGIN
                        IF NEW.statut = 'rejetee' AND COALESCE(TRIM(NEW.motif_rejet) <> '', 0) = 0 THEN
                            SIGNAL SQLSTATE '45000'
                              SET MESSAGE_TEXT = 'Un rejet de demande d''inscription doit porter son motif.';
                        END IF;
                        IF NEW.statut = 'approuvee' AND NEW.structure_id IS NULL THEN
                            SIGNAL SQLSTATE '45000'
                              SET MESSAGE_TEXT = 'Une demande approuvee doit designer l''etablissement cree.';
                        END IF;
                    END
                ");
            }

            return;
        }

        // SQLite (suite de tests) : même garantie, dialecte différent. La faire vivre dans un
        // seul des deux moteurs la rendrait vraie en production et fausse en test — divergence
        // refusée depuis P6.8c (collation) et P6.8e (REGEXP).
        foreach (['INSERT', 'UPDATE'] as $moment) {
            $nom = 'trg_demande_inscription_'.strtolower($moment);
            DB::unprepared("
                CREATE TRIGGER {$nom} BEFORE {$moment} ON demandes_inscription_etablissement
                FOR EACH ROW
                WHEN (NEW.statut = 'rejetee' AND COALESCE(TRIM(COALESCE(NEW.motif_rejet, '')) <> '', 0) = 0)
                   OR (NEW.statut = 'approuvee' AND NEW.structure_id IS NULL)
                BEGIN
                    SELECT RAISE(ABORT, 'Un rejet doit porter son motif ; une approbation doit designer son etablissement.');
                END
            ");
        }
    }

    private function retirerGardes(): void
    {
        foreach (['insert', 'update'] as $moment) {
            DB::unprepared("DROP TRIGGER IF EXISTS trg_demande_inscription_{$moment}");
        }
    }
};
