<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * P6.3 — Socle référentiel : registre, versionnage, audit (CDC_09 §10, §14.1 ; ADR-024/ADR-025).
 *
 * Migration STRICTEMENT ADDITIVE. Aucune table de référentiel métier n'est modifiée :
 * `referentiels_mesure` et `symptomes` gardent leur schéma, leurs lectures et leurs modules
 * validés G5 (triage, mesures). Le socle les OBSERVE, il ne les remplace pas — ADR-024.
 *
 * TROIS TABLES, TROIS RÔLES DISTINCTS :
 *
 *  1. `referentiels`         — le REGISTRE. Quels référentiels nationaux sont gouvernés, par qui
 *                              (§10 « chaque référentiel a un responsable désigné »), et quelle
 *                              version est publiée aujourd'hui.
 *  2. `referentiel_versions` — le CYCLE DE VIE §10 (proposition → validation → publication →
 *                              archivage) et l'INSTANTANÉ du contenu publié (décision G1 D1-a).
 *  3. `referentiel_journal`  — l'AUDIT immuable §11, chaîne de hachage GLOBALE.
 *
 * POURQUOI UN INSTANTANÉ JSON PLUTÔT QU'UN VERSIONNAGE LIGNE À LIGNE (SCD-2) : §10 exige que
 * « toute décision clinique conserve la version du référentiel utilisée ». Un versionnage ligne à
 * ligne aurait imposé d'ajouter `valide_du`/`valide_au` à CHAQUE table de référentiel — donc de
 * modifier des tables de modules validés G5. L'instantané fige le contenu publié sans toucher à
 * une seule table métier : c'est le motif déjà éprouvé du snapshot des paramètres d'alerte de
 * fraude (P5.3b-2) et du cut-off T de l'auditeur d'intégrité (P5.3b-4).
 *
 * POURQUOI `verrou_unicite` : deux invariants doivent tenir EN BASE, pas dans la confiance —
 * au plus UNE proposition en cours et au plus UNE version publiée par référentiel. MySQL n'a pas
 * d'index unique partiel ; une colonne générée serait la voie naturelle mais MySQL refuse une
 * colonne générée STORED dérivée d'une colonne portant une action référentielle (erreur 1215
 * constatée en G2 sur P6.1). On maintient donc une colonne applicativement, en gardant DEUX
 * garanties du moteur : UNIQUE (l'unicité) et CHECK (la colonne ne peut pas mentir sur `statut`).
 * Exactement la parade retenue pour l'unicité du dossier titulaire en P6.1.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ─────────────────────────────────────────────────────────────────────────────
        // 1. Le registre des référentiels gouvernés.
        // ─────────────────────────────────────────────────────────────────────────────
        Schema::create('referentiels', function (Blueprint $table) {
            $table->id();

            // Code stable, connu de la liste blanche `RegistreReferentiels`. C'est lui qui
            // apparaît dans l'API de diffusion et dans l'estampille d'une décision.
            $table->string('code', 50);

            // Multi-pays (CDC_09 §1.2 principe 5) : un jeu de référentiels par pays, et ajouter
            // un pays reste un ajout de DONNÉES — aucune ligne de code.
            $table->char('pays_code', 2)->default('CI');

            $table->string('libelle', 150);

            // §10 « chaque référentiel a un responsable désigné ». Rôle, pas personne : une
            // personne change de poste, la responsabilité reste.
            $table->string('role_responsable', 50);

            // Dénormalisé À DESSEIN : c'est LA clé de cache de la diffusion (§4 du service).
            // Une FK vers `referentiel_versions` créerait un cycle de dépendance entre les deux
            // tables, et il faudrait quand même lire la version pour connaître son numéro.
            $table->unsignedInteger('version_publiee_numero')->nullable();
            $table->timestamp('publiee_le')->nullable();

            $table->timestamps();

            $table->unique(['code', 'pays_code'], 'uq_referentiel_code_pays');
        });

        // ─────────────────────────────────────────────────────────────────────────────
        // 2. Les versions : cycle de vie §10 + instantané figé.
        // ─────────────────────────────────────────────────────────────────────────────
        Schema::create('referentiel_versions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('referentiel_id')->constrained('referentiels')->cascadeOnDelete();

            // Croissant par référentiel. Attribué SOUS VERROU au dépôt de la proposition ;
            // l'UNIQUE ci-dessous attrape toute course qui passerait entre les mailles.
            $table->unsignedInteger('numero');

            $table->enum('statut', ['proposition', 'publiee', 'archivee', 'rejetee'])
                ->default('proposition');

            // Porte les deux invariants d'unicité — voir l'en-tête de la migration.
            //   'P:<referentiel_id>' quand statut = proposition
            //   'V:<referentiel_id>' quand statut = publiee
            //   NULL                 quand statut = archivee | rejetee
            $table->string('verrou_unicite', 40)->nullable();

            // Pourquoi ce changement. Exigé : une version sans motif est une version qu'on ne
            // saura pas expliquer dans six mois.
            $table->string('motif', 500);

            // L'INSTANTANÉ : le contenu du référentiel tel que proposé, puis publié. C'est lui
            // qui rend une décision passée rejouable sans toucher aux tables métier.
            $table->json('contenu_json');

            // SHA-256 du contenu canonique. Permet de comparer deux versions et de détecter une
            // altération de l'instantané sans le relire entièrement.
            $table->char('empreinte', 64);
            $table->unsignedInteger('nb_entrees');

            $table->foreignId('propose_par')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('propose_le');

            // Quatre-yeux (§10 « double validation ») : renseigné à la décision, jamais par
            // l'auteur — garanti par CHECK, pas seulement par le service.
            $table->foreignId('decide_par')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decide_le')->nullable();
            $table->string('motif_decision', 500)->nullable();

            $table->timestamps();

            $table->unique(['referentiel_id', 'numero'], 'uq_ref_version_numero');
            $table->unique('verrou_unicite', 'uq_ref_version_verrou');
            $table->index(['referentiel_id', 'statut'], 'idx_ref_version_statut');
        });

        // Les trois invariants du cycle de vie, exprimés au moteur.
        //
        // Note : `propose_par` et `decide_par` sont `nullOnDelete`. Si le compte d'un agent est
        // supprimé, les deux peuvent devenir NULL — d'où la forme « IS NULL OR ≠ » du quatre-yeux :
        // elle reste vraie après effacement, sans jamais autoriser un auto-validation à l'écriture.
        $this->ajouterContraintes();

        // ─────────────────────────────────────────────────────────────────────────────
        // 3. Le journal d'audit — append-only, chaîne de hachage GLOBALE (§11).
        // ─────────────────────────────────────────────────────────────────────────────
        //
        // CHAÎNE GLOBALE et non par référentiel : une chaîne par référentiel permettrait de
        // supprimer l'historique entier d'un référentiel sans que rien ne le révèle. Une chaîne
        // unique lie toutes les modifications entre elles — c'est le motif de la chaîne
        // `audit_entries` du service paiement (P5.1), porté ici en PHP.
        //
        // Pas d'`updated_at` : cette table ne se met jamais à jour.
        Schema::create('referentiel_journal', function (Blueprint $table) {
            $table->id();

            // Dénormalisés à l'écriture : le journal doit rester lisible même si le référentiel
            // a disparu du registre. Même motif que l'établissement copié sur `acces_dossier`
            // en P7-D2 — on ne déduit jamais après coup ce qu'on pouvait figer sur le moment.
            $table->string('referentiel_code', 50);
            $table->char('pays_code', 2);
            $table->foreignId('referentiel_id')->nullable()
                ->constrained('referentiels')->nullOnDelete();

            $table->unsignedInteger('version_numero')->nullable();
            $table->string('action', 40);

            $table->foreignId('acteur_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('acteur_nom', 150);

            // Empreinte, motif, nombre d'entrées. JAMAIS le contenu lui-même : le journal prouve
            // qu'un changement a eu lieu et par qui, l'instantané porte ce qui a changé.
            $table->json('details_json')->nullable();

            $table->char('empreinte_precedente', 64)->nullable();
            $table->char('empreinte', 64)->unique();

            $table->timestamp('cree_le');

            $table->index(['referentiel_code', 'cree_le'], 'idx_ref_journal_code_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('referentiel_journal');
        Schema::dropIfExists('referentiel_versions');
        Schema::dropIfExists('referentiels');
    }

    /**
     * Les trois invariants du cycle de vie, exprimés AU MOTEUR — pas seulement au service.
     *
     *  (a) le verrou d'unicité ne peut pas mentir sur le statut ;
     *  (b) quatre-yeux : le validateur n'est jamais l'auteur (§10 « double validation ») — miroir
     *      de « l'auteur ne peut pas se valider lui-même » (P7-C) et « approbateur ≠ calculateur »
     *      (P5.5b-1) ;
     *  (c) une proposition n'a pas de décideur ; toute version décidée en a un.
     *
     * POURQUOI DES TRIGGERS ET NON DES `CHECK` — les deux moteurs les refusent, pour deux raisons
     * différentes, et c'est le G2 live qui l'a établi :
     *
     *  - **MySQL 8.4 : erreur 3823** — « Column 'decide_par' cannot be used in a check constraint:
     *    needed in a foreign key constraint referential action ». Un `CHECK` ne peut pas porter sur
     *    une colonne qui subit une action référentielle : `propose_par`/`decide_par` sont
     *    `nullOnDelete` (l'audit doit survivre à la suppression d'un compte) et `referentiel_id`
     *    est `cascadeOnDelete`. Les trois conditions touchent au moins une de ces colonnes.
     *    C'est le cousin exact de l'erreur 1215 rencontrée en P6.1 sur la colonne générée.
     *  - **SQLite (tests)** refuse `ALTER TABLE … ADD CONSTRAINT` : un `CHECK` n'y existe qu'à la
     *    création de la table, forme que le Blueprint de Laravel n'exprime pas.
     *
     * Renoncer aux `nullOnDelete` pour sauver les `CHECK` aurait été le mauvais échange : la
     * suppression d'un compte serait alors bloquée par l'historique de gouvernance, ou pire,
     * l'emporterait. CDC_04 §139 prévoit exactement ce recours — « triggers : contrôle d'intégrité
     * métier ne pouvant être garanti autrement » — et P5.5a l'avait déjà retenu pour la même raison.
     * L'unicité, elle, reste pleinement déclarative (`UNIQUE(verrou_unicite)`).
     *
     * `COALESCE(…, 0)` n'est pas décoratif : une comparaison avec NULL vaut NULL, et un test
     * `WHEN NULL` ne déclencherait rien — la violation passerait sans bruit.
     */
    private function ajouterContraintes(): void
    {
        $mysql = DB::connection()->getDriverName() === 'mysql';

        // Les trois conditions. Seule la concaténation change de dialecte.
        $conditions = [
            'ck_ref_version_verrou' => $mysql
                ? "   (NEW.statut = 'proposition' AND NEW.verrou_unicite = CONCAT('P:', NEW.referentiel_id))
                   OR (NEW.statut = 'publiee'     AND NEW.verrou_unicite = CONCAT('V:', NEW.referentiel_id))
                   OR (NEW.statut IN ('archivee', 'rejetee') AND NEW.verrou_unicite IS NULL)"
                : "   (NEW.statut = 'proposition' AND NEW.verrou_unicite = 'P:' || NEW.referentiel_id)
                   OR (NEW.statut = 'publiee'     AND NEW.verrou_unicite = 'V:' || NEW.referentiel_id)
                   OR (NEW.statut IN ('archivee', 'rejetee') AND NEW.verrou_unicite IS NULL)",

            'ck_ref_version_quatre_yeux' =>
                'NEW.decide_par IS NULL OR NEW.propose_par IS NULL OR NEW.decide_par <> NEW.propose_par',

            'ck_ref_version_decision' =>
                "   (NEW.statut = 'proposition' AND NEW.decide_le IS NULL)
                 OR (NEW.statut <> 'proposition' AND NEW.decide_le IS NOT NULL)",
        ];

        foreach ($conditions as $nom => $condition) {
            foreach (['INSERT', 'UPDATE'] as $evenement) {
                $trigger = $nom.'_'.strtolower($evenement);

                DB::unprepared($mysql
                    ? "CREATE TRIGGER {$trigger}
                       BEFORE {$evenement} ON referentiel_versions
                       FOR EACH ROW
                       BEGIN
                           IF COALESCE(({$condition}), 0) = 0 THEN
                               SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$nom}';
                           END IF;
                       END"
                    : "CREATE TRIGGER {$trigger}
                       BEFORE {$evenement} ON referentiel_versions
                       WHEN COALESCE(({$condition}), 0) = 0
                       BEGIN SELECT RAISE(ABORT, '{$nom}'); END"
                );
            }
        }
    }
};
