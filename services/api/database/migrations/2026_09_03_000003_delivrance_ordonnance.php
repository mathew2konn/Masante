<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * B3-a — LE MAILLON MANQUANT : `Médecin → Prescription → Pharmacien` (CDC_11 §7.1).
 *
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 * 1. LES LIGNES D'ORDONNANCE — LE REPORT DE B2-c EST LEVÉ, ET POUR LA RAISON QU'IL POSAIT
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 *
 * B2-c écartait `ordonnance_lignes` en écrivant : « elle n'a AUCUN consommateur aujourd'hui ; sa
 * raison d'être est la délivrance en pharmacie, qui n'existe pas ». Ce lot livre ce consommateur.
 *
 * ET LA DÉCISION QUE B2-c AVAIT RENVOYÉE DOIT ÊTRE PRISE ICI : « l'interrogeabilité ne s'obtient
 * qu'en cessant de chiffrer — une décision qui mérite d'être prise pour elle-même ». La voici,
 * alignée sur la ligne que ce projet trace depuis `resultats_analyses` (constat Y9 de B2, où
 * `intitule` est en clair et seules les valeurs mesurées sont chiffrées) :
 *
 *   EN CLAIR  — `medicament_id`, `code_national`, `dci`, `dosage`, `nom` : ce qui IDENTIFIE un
 *               produit. Sans eux en clair, ni la délivrance, ni la vérification d'interactions,
 *               ni la traçabilité du §7.6 ne sont possibles.
 *   CHIFFRÉ   — `posologie`, `duree`, `instructions` : ce que le médecin a prescrit À CETTE
 *               PERSONNE.
 *
 * *Ce qui identifie un produit n'est pas ce qui décrit un traitement.*
 *
 * `medicaments_json` N'EST PAS SUPPRIMÉ (ADR-024) : les ordonnances antérieures le gardent, et il
 * reste la source des chemins patient. Conséquence assumée et dite : **les ordonnances antérieures
 * n'ont pas de lignes, donc ne sont pas délivrables électroniquement**. Aucune rétro-génération —
 * recréer des lignes depuis un JSON saisi librement produirait des lignes que personne n'a
 * vérifiées, sur un document parfois signé.
 *
 * ═══ 2. LE JETON — LE PHARMACIEN NE VOIT QUE L'ORDONNANCE ═══
 *
 * Le seul mécanisme existant (`qr.scan`) ouvre une SESSION DE DOSSIER : antécédents, vaccinations,
 * résultats d'analyses. *Un pharmacien n'a pas à lire les antécédents pour servir une boîte de
 * paracétamol* — minimisation, loi 2013-450, que ce projet applique déjà explicitement (P7-D2).
 *
 * Patron repris de la fiche de triage (P10a), non réinventé : 48 caractères aléatoires, hors
 * `$fillable` (un client qui choisirait son jeton pourrait le deviner), `$hidden`, comparaison en
 * temps constant, et **404 jamais 403** — un 403 confirmerait qu'une ordonnance existe là.
 *
 * ═══ 3. LA DÉLIVRANCE — UN EN-TÊTE ET DES LIGNES, AUCUN STATUT STOCKÉ ═══
 *
 * Une délivrance PARTIELLE est le cas normal : la pharmacie a deux médicaments sur trois. D'où des
 * lignes, et non un booléen.
 *
 * AUCUNE COLONNE « statut » : « cette ordonnance est-elle entièrement servie ? » se DÉDUIT des
 * lignes délivrées. Une valeur stockée recalculable finirait par diverger de ce qu'elle résume —
 * c'est la leçon du wallet (P5.3a), où le solde est une somme et jamais une colonne.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── Le jeton de partage, sur l'ordonnance ────────────────────────────────────────────
        Schema::table('ordonnances', function (Blueprint $table) {
            $table->string('jeton_partage', 64)->nullable()->unique()->after('pdf_url');
        });

        // Les ordonnances existantes en reçoivent un : sans cela, une ordonnance écrite hier ne
        // pourrait jamais être présentée, et il faudrait la ressaisir. (Elle ne sera pas délivrable
        // pour autant — elle n'a pas de lignes — mais elle reste consultable par la pharmacie.)
        DB::table('ordonnances')->whereNull('jeton_partage')->orderBy('id')->each(
            fn ($o) => DB::table('ordonnances')->where('id', $o->id)
                ->update(['jeton_partage' => Str::random(48)])
        );

        // ── Les lignes de la prescription ────────────────────────────────────────────────────
        Schema::create('ordonnance_lignes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ordonnance_id')->constrained('ordonnances')->cascadeOnDelete();

            // EN CLAIR — l'identité du produit. `nom` en fait partie : c'est un nom de produit, pas
            // une donnée sur la personne, et sans lui une ordonnance non rattachée au référentiel
            // serait indélivrable.
            $table->string('nom', 200);
            $table->foreignId('medicament_id')->nullable()->constrained('medicaments')->nullOnDelete();
            $table->string('code_national', 12)->nullable();
            $table->string('dci', 200)->nullable();
            $table->string('dosage', 100)->nullable();

            // CHIFFRÉ — ce qui décrit le traitement de cette personne.
            $table->text('posologie')->nullable();
            $table->text('duree')->nullable();
            $table->text('instructions')->nullable();

            // Le médecin ne la précise pas toujours : `nullable` dit l'absence plutôt que d'inventer
            // une quantité. Quand elle est là, elle borne le cumul délivré (garde de service).
            $table->unsignedInteger('quantite_prescrite')->nullable();

            $table->unsignedSmallInteger('rang')->default(1);
            $table->timestamps();

            $table->index('ordonnance_id', 'idx_ligne_ordonnance');
            $table->index('code_national', 'idx_ligne_code_national');
        });

        // ── L'acte de délivrance ─────────────────────────────────────────────────────────────
        Schema::create('delivrances', function (Blueprint $table) {
            $table->id();

            // `cascadeOnDelete` : une délivrance ne veut rien dire sans son ordonnance, et le
            // patient reste maître de son carnet. LA TRACE QUI DOIT SURVIVRE est celle du §7.6
            // (traçabilité nationale), qui vit dans un registre à part — c'est B3-c, et le dire
            // ici évite de faire porter à cette table une promesse qu'elle ne tient pas.
            $table->foreignId('ordonnance_id')->constrained('ordonnances')->cascadeOnDelete();

            $table->foreignId('structure_id')->constrained('structures_sanitaires')->cascadeOnDelete();

            // QUI A SERVI. Identifiant sans clé étrangère (ADR-042 D1) + nom FIGÉ : l'acte doit
            // continuer de nommer son auteur même si le compte est supprimé.
            $table->unsignedBigInteger('pharmacien_user_id')->nullable();
            $table->string('pharmacien_nom', 200);

            $table->timestamp('delivree_le')->useCurrent();
            $table->timestamps();

            $table->index('ordonnance_id', 'idx_delivrance_ordonnance');
            $table->index(['structure_id', 'delivree_le'], 'idx_delivrance_officine');
        });

        Schema::create('delivrance_lignes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('delivrance_id')->constrained('delivrances')->cascadeOnDelete();
            $table->foreignId('ordonnance_ligne_id')->constrained('ordonnance_lignes')->cascadeOnDelete();
            $table->unsignedInteger('quantite');
            $table->timestamps();

            // Une même ligne de prescription n'est servie qu'une fois PAR délivrance. Deux
            // délivrances successives sur la même ligne restent possibles — c'est exactement le cas
            // du patient qui repasse chercher le médicament manquant.
            $table->unique(['delivrance_id', 'ordonnance_ligne_id'], 'uq_delivrance_ligne');
        });

        // ── La garde du moteur ───────────────────────────────────────────────────────────────
        //
        // Une ligne servie doit appartenir à L'ORDONNANCE DE SA DÉLIVRANCE. Rien de déclaratif ne
        // l'exprime : c'est une cohérence entre trois tables. Un `CHECK` en est incapable, et il
        // serait de toute façon refusé (colonnes sous action référentielle, erreur 3823 — le mur de
        // P6.3). Un déclencheur, lui, PEUT interroger d'autres tables — il ne peut pas interroger
        // celle qu'il garde (erreur 1442, P6.4c), et ce n'est pas le cas ici.
        //
        // La quantité, elle, N'EST PAS gardée par le moteur : elle dépend d'une SOMME sur les
        // délivrances antérieures, qu'un déclencheur ne peut pas calculer sans lire la table qu'il
        // garde. Garde applicative, annoncée comme telle — jamais déguisée en garantie du moteur
        // (précédent P6.4c sur le quota d'images).
        $this->poserGardes();
    }

    public function down(): void
    {
        $this->retirerGardes();

        Schema::dropIfExists('delivrance_lignes');
        Schema::dropIfExists('delivrances');
        Schema::dropIfExists('ordonnance_lignes');

        Schema::table('ordonnances', function (Blueprint $table) {
            $table->dropUnique(['jeton_partage']);
            $table->dropColumn('jeton_partage');
        });
    }

    private function poserGardes(): void
    {
        $pilote = Schema::getConnection()->getDriverName();

        if ($pilote === 'mysql') {
            foreach (['INSERT', 'UPDATE'] as $moment) {
                $nom = 'trg_delivrance_ligne_'.strtolower($moment);
                DB::unprepared("
                    CREATE TRIGGER {$nom} BEFORE {$moment} ON delivrance_lignes
                    FOR EACH ROW
                    BEGIN
                        IF (SELECT o.ordonnance_id FROM ordonnance_lignes o WHERE o.id = NEW.ordonnance_ligne_id)
                           <> (SELECT d.ordonnance_id FROM delivrances d WHERE d.id = NEW.delivrance_id) THEN
                            SIGNAL SQLSTATE '45000'
                              SET MESSAGE_TEXT = 'Cette ligne appartient a une autre ordonnance que la delivrance.';
                        END IF;
                        IF NEW.quantite = 0 THEN
                            SIGNAL SQLSTATE '45000'
                              SET MESSAGE_TEXT = 'Une ligne delivree porte une quantite non nulle.';
                        END IF;
                    END
                ");
            }

            return;
        }

        // SQLite (suite de tests) : même garantie, dialecte différent. La faire vivre dans un seul
        // moteur la rendrait vraie en production et fausse en test — divergence refusée depuis
        // P6.8c (collation) et P6.8e (REGEXP).
        foreach (['INSERT', 'UPDATE'] as $moment) {
            $nom = 'trg_delivrance_ligne_'.strtolower($moment);
            DB::unprepared("
                CREATE TRIGGER {$nom} BEFORE {$moment} ON delivrance_lignes
                FOR EACH ROW
                WHEN (SELECT o.ordonnance_id FROM ordonnance_lignes o WHERE o.id = NEW.ordonnance_ligne_id)
                     <> (SELECT d.ordonnance_id FROM delivrances d WHERE d.id = NEW.delivrance_id)
                   OR NEW.quantite = 0
                BEGIN
                    SELECT RAISE(ABORT, 'Ligne d''une autre ordonnance, ou quantite nulle.');
                END
            ");
        }
    }

    private function retirerGardes(): void
    {
        foreach (['insert', 'update'] as $moment) {
            DB::unprepared("DROP TRIGGER IF EXISTS trg_delivrance_ligne_{$moment}");
        }
    }
};
