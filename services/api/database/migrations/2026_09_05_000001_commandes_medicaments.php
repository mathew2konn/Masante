<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * B3-d — PANIER ET COMMANDE DE MÉDICAMENTS (CDC_11 §9.5, §10.5). Plan `plan.md` PLAN 2.
 *
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 * LE PANIER N'EST PAS ICI, ET C'EST DÉLIBÉRÉ (F1)
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 * Un panier est un état éphémère et personnel qui n'engage rien — le contenu d'un panier de
 * médicaments dit ce dont on se soigne, une donnée de santé que le serveur n'a pas à conserver
 * pour le seul confort de survivre à un changement d'appareil. Le panier vit sur le téléphone
 * (store Zustand) ; le serveur ne reçoit que la COMMANDE, l'acte, jamais l'intention.
 *
 * ═══ F6, RÉÉCRIT APRÈS B4 (VALIDÉ G5) : LE RÈGLEMENT EN LIGNE EMPRUNTE LE CANAL RÉEL ═══
 * `commande_geniuspay_id` est le miroir exact de `factures_patient.facture_geniuspay_id` (B4-b) :
 * `ServiceWebhookGeniusPay::appliquer()` (Java) exige une vraie Facture pour solder un paiement,
 * dans la MÊME transaction que la transition vers SUCCESS — sans facture réelle, le règlement
 * échoue en silence. `commandes` porte SON PROPRE règlement, jamais `factures_patient` (table de
 * facturation DE SOINS — CMU, reste à charge — dont une commande de médicaments n'est PAS un
 * acte, arbitrage du propriétaire antérieur à B4).
 *
 * `CommissionService` N'A RIEN À VOIR ICI : la commission suit automatiquement le mécanisme déjà
 * générique de `PaiementNotificationController::calculerCommissionSiApplicable()` (B4-a), qui se
 * déclenche sur tout succès `canal=geniuspay` avec un établissement résoluble — quel que soit ce
 * qui est payé. `sur_place` : aucun appel réseau, littéralement — le §9.6 vérifié PAR CONSTRUCTION.
 *
 * ═══ GARDES DU MOTEUR — DÉCLENCHEURS DANS LES DEUX DIALECTES ═══
 * Un `CHECK` serait ici techniquement possible (aucune de ces colonnes ne subit d'action
 * référentielle, donc pas d'erreur 3823) — mais il ne peut pas porter de message. Les déclencheurs
 * nomment la garde violée, style tenu par tout le lot B3. `COALESCE(cond, 0) = 0` et non
 * `NOT(cond)` : une comparaison sur NULL ne déclencherait rien et la violation passerait sans
 * bruit (précédent P11.1, P6.3). Apostrophes DOUBLÉES dans les messages (piège trouvé au G2 de
 * B3-b : « ne se efface pas » a rendu une migration impossible à rejouer).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commandes', function (Blueprint $table) {
            $table->id();

            // Opaque et non séquentielle (`CMD-` + 10 aléatoires) — patron `DEM-` de P11.1 : un
            // compteur laisserait deviner le volume et énumérer les commandes des autres.
            $table->string('reference', 20)->unique();
            $table->string('pays_code', 2)->default('CI');

            $table->foreignId('membre_id')->constrained('membres_famille')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('structure_id')->constrained('structures_sanitaires')->restrictOnDelete();
            $table->foreignId('ordonnance_id')->nullable()->constrained('ordonnances')->nullOnDelete();

            $table->string('mode_retrait', 20);
            $table->text('adresse_livraison')->nullable();

            $table->string('statut', 20)->default('en_attente');

            $table->unsignedInteger('montant_indicatif_cfa')->nullable();
            $table->string('mode_reglement', 20)->default('sur_place');
            $table->timestamp('regle_le')->nullable();
            $table->string('reference_reglement', 30)->nullable()->unique();
            // F6 (réécrit, B4) — id de la vraie Facture Java, créée une fois et réutilisée.
            $table->string('commande_geniuspay_id', 36)->nullable();

            $table->string('commentaire', 500)->nullable();
            $table->string('motif_refus', 300)->nullable();

            $table->foreignId('traite_par_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamp('acceptee_le')->nullable();
            $table->timestamp('prete_le')->nullable();
            $table->timestamp('remise_le')->nullable();
            $table->timestamp('annulee_le')->nullable();

            $table->timestamps();

            $table->index(['structure_id', 'statut'], 'idx_commande_officine_statut');
            $table->index(['membre_id', 'created_at'], 'idx_commande_membre_date');
        });

        Schema::create('commande_lignes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('commande_id')->constrained('commandes')->cascadeOnDelete();

            // La table n'est PAS append-only (une commande change d'état, ses lignes non plus
            // après création — mais elles peuvent disparaître avec elle) : la nullification par
            // le moteur est sans danger ici, à la différence exacte de `traces_dispensation`
            // (B3-c §10.10), dont l'append-only interdit toute mise à NULL. Écrit pour qu'on ne
            // reproduise pas l'un à la place de l'autre.
            $table->foreignId('medicament_id')->nullable()->constrained('medicaments')->nullOnDelete();

            // FIGÉS à la commande (patron B3-a/B3-c) — jamais recalculés après coup.
            $table->string('medicament_code', 12)->nullable();
            $table->string('nom', 200);
            $table->string('dci', 200)->nullable();
            $table->string('dosage', 100)->nullable();
            $table->boolean('ordonnance_requise')->default(false);

            $table->foreignId('ordonnance_ligne_id')->nullable()->constrained('ordonnance_lignes')->nullOnDelete();

            $table->unsignedInteger('quantite');
            $table->unsignedInteger('prix_unitaire_indicatif_cfa')->nullable();

            $table->timestamps();

            // On n'ajoute pas deux fois le même produit, on augmente la quantité — l'unicité rend
            // le doublon INEXPRIMABLE plutôt que de le corriger après coup (geste de P6.8c).
            $table->unique(['commande_id', 'medicament_id'], 'uq_commande_ligne_medicament');
        });

        $this->poserGardes();
    }

    public function down(): void
    {
        $this->retirerGardes();

        Schema::dropIfExists('commande_lignes');
        Schema::dropIfExists('commandes');
    }

    private function poserGardes(): void
    {
        $pilote = Schema::getConnection()->getDriverName();

        if ($pilote === 'mysql') {
            foreach (['INSERT', 'UPDATE'] as $moment) {
                $nom = 'trg_commande_'.strtolower($moment);
                DB::unprepared("
                    CREATE TRIGGER {$nom} BEFORE {$moment} ON commandes
                    FOR EACH ROW
                    BEGIN
                        IF NEW.statut = 'refusee' AND COALESCE(TRIM(NEW.motif_refus) <> '', 0) = 0 THEN
                            SIGNAL SQLSTATE '45000'
                              SET MESSAGE_TEXT = 'Un refus de commande doit porter son motif.';
                        END IF;
                        IF NEW.mode_retrait = 'livraison' AND NEW.adresse_livraison IS NULL THEN
                            SIGNAL SQLSTATE '45000'
                              SET MESSAGE_TEXT = 'Une commande en livraison doit porter une adresse.';
                        END IF;
                        IF NEW.regle_le IS NOT NULL AND COALESCE(TRIM(NEW.reference_reglement) <> '', 0) = 0 THEN
                            SIGNAL SQLSTATE '45000'
                              SET MESSAGE_TEXT = 'Une commande reglee doit porter sa reference de reglement.';
                        END IF;
                    END
                ");
            }

            DB::unprepared('
                CREATE TRIGGER trg_commande_ligne_insert BEFORE INSERT ON commande_lignes
                FOR EACH ROW
                BEGIN
                    IF NEW.quantite < 1 THEN
                        SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT = \'Une ligne de commande porte une quantite d\'\'au moins 1.\';
                    END IF;
                END
            ');
            DB::unprepared('
                CREATE TRIGGER trg_commande_ligne_update BEFORE UPDATE ON commande_lignes
                FOR EACH ROW
                BEGIN
                    IF NEW.quantite < 1 THEN
                        SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT = \'Une ligne de commande porte une quantite d\'\'au moins 1.\';
                    END IF;
                END
            ');

            return;
        }

        foreach (['INSERT', 'UPDATE'] as $moment) {
            $nom = 'trg_commande_'.strtolower($moment);
            DB::unprepared("
                CREATE TRIGGER {$nom} BEFORE {$moment} ON commandes
                FOR EACH ROW
                WHEN (NEW.statut = 'refusee' AND COALESCE(TRIM(COALESCE(NEW.motif_refus, '')) <> '', 0) = 0)
                   OR (NEW.mode_retrait = 'livraison' AND NEW.adresse_livraison IS NULL)
                   OR (NEW.regle_le IS NOT NULL AND COALESCE(TRIM(COALESCE(NEW.reference_reglement, '')) <> '', 0) = 0)
                BEGIN
                    SELECT RAISE(ABORT, 'Un refus doit porter son motif ; une livraison doit porter une adresse ; un reglement doit porter sa reference.');
                END
            ");
        }

        foreach (['INSERT', 'UPDATE'] as $moment) {
            DB::unprepared('
                CREATE TRIGGER trg_commande_ligne_'.strtolower($moment)." BEFORE {$moment} ON commande_lignes
                FOR EACH ROW
                WHEN NEW.quantite < 1
                BEGIN
                    SELECT RAISE(ABORT, 'Une ligne de commande porte une quantite d''au moins 1.');
                END
            ");
        }
    }

    private function retirerGardes(): void
    {
        foreach (['insert', 'update'] as $moment) {
            DB::unprepared("DROP TRIGGER IF EXISTS trg_commande_{$moment}");
            DB::unprepared("DROP TRIGGER IF EXISTS trg_commande_ligne_{$moment}");
        }
    }
};
