<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * B3-b — LE STOCK RÉEL D'UNE OFFICINE (CDC_11 §7.3 et §7.5).
 *
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 * 1. POURQUOI UNE TABLE À PART, ET NON `prix_pharmacie` ENRICHIE
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 *
 * `prix_pharmacie` mélange déjà deux sources : le relevé d'un patient (`crowdsource_patient`) et la
 * déclaration de l'officine. Y ajouter lots et péremption ferait porter à une table DÉCLARATIVE des
 * données dont **l'officine seule répond**. C'est la ligne que ce projet trace depuis P6.4a : ce
 * qu'un humain identifié dépose délibérément d'un côté, ce qui est relevé ou recalculé de l'autre.
 *
 *   `prix_pharmacie`     — LE RELEVÉ PUBLIC, que lit le comparateur. Ne change pas de contrat.
 *   `stocks_officine`    — L'INVENTAIRE tenu par la pharmacie (§7.5).
 *
 * **L'inventaire ALIMENTE le relevé, il ne le double pas.** Une seule valeur publique de prix et de
 * disponibilité — sinon le comparateur et la fiche officine pourraient se contredire, et *le patient
 * ne saurait pas laquelle croire* (motif P6.7b, où le délai du laboratoire prime mais où les deux
 * sont portés).
 *
 * ═══ 2. AUCUNE COLONNE `quantite` SUR L'ARTICLE — LE STOCK EST UNE SOMME ═══
 *
 * Une entrée, une sortie, une péremption sont des **faits datés**. Le stock courant en est la
 * somme, jamais une valeur qu'on corrige : c'est la partie double du wallet (P5.3a), dont la leçon
 * est exactement celle-ci — *une valeur stockée recalculable finit par diverger de ce qu'elle
 * résume*, et l'écart ne se voit qu'au moment où il coûte cher.
 *
 * `quantite` est SIGNÉE, comme les contributions du grand livre de P5.5b-1 (« Σ = 0 par
 * contributions signées, aucun `abs()` ») : une entrée est positive, une sortie négative, et
 * `SUM(quantite)` est le stock. Le `type` dit la NATURE du mouvement, et un déclencheur refuse
 * qu'il contredise le signe — une « entrée » négative serait une ligne qui ment.
 *
 * ═══ 3. APPEND-ONLY ═══
 *
 * Un mouvement ne se modifie ni ne s'efface : une erreur de saisie se corrige par un mouvement
 * d'`ajustement`, qui la laisse visible. Deux niveaux, comme `protocole_applications` (P10b-2) :
 * le modèle refuse, et le moteur refuse aussi.
 *
 * ═══ 4. LA FICHE OFFICINE (§7.4) ═══
 *
 * `structures_sanitaires` porte déjà nom, adresse, GPS, téléphone et horaires. Manquent quatre
 * champs, et ils ne sont pas de même nature — critère d'ADR-026 appliqué tel quel :
 *
 *   `pharmacien_responsable`, `numero_licence` — ils ENGAGENT une autorité, comme le numéro
 *      d'autorisation d'un établissement. Ils entrent dans la projection gouvernée, et **cela fait
 *      diverger le référentiel jusqu'à la publication suivante** : ce n'est pas une dérive, c'est
 *      ce que la projection est censée porter (précédent `forme_juridique`, P6.4d).
 *   `livraison_disponible`, `rayon_livraison_km` — OPÉRATIONNELS, comme les horaires. Les gouverner
 *      ferait d'un changement de zone de livraison un acte soumis au quatre-yeux.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── L'article en rayon (§7.5) ────────────────────────────────────────────────────────
        Schema::create('stocks_officine', function (Blueprint $table) {
            $table->id();
            $table->foreignId('structure_id')->constrained('structures_sanitaires')->cascadeOnDelete();
            $table->foreignId('medicament_id')->constrained('medicaments')->cascadeOnDelete();

            // Le prix de vente de CETTE officine. Il alimente le relevé public quand il change —
            // il ne le double pas : `prix_pharmacie` reste la seule valeur que lit le comparateur.
            $table->unsignedInteger('prix_cfa')->nullable();

            // §7.3 « stock minimum » : au-dessous, l'officine est alertée. `nullable` — toutes les
            // officines n'en fixent pas, et zéro voudrait dire « alerte jamais », pas « non fixé ».
            $table->unsignedInteger('seuil_alerte')->nullable();

            $table->timestamps();

            // Un article par produit et par officine : deux lignes pour le même médicament
            // laisseraient deux stocks courants pour une seule réalité.
            $table->unique(['structure_id', 'medicament_id'], 'uq_stock_officine_produit');
            $table->index('structure_id', 'idx_stock_officine');
        });

        // ── Les mouvements, append-only ──────────────────────────────────────────────────────
        Schema::create('mouvements_stock', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_id')->constrained('stocks_officine')->cascadeOnDelete();

            $table->enum('type', ['entree', 'sortie', 'peremption', 'ajustement']);

            // SIGNÉE : `SUM(quantite)` est le stock courant. Le déclencheur refuse qu'un type
            // contredise le signe.
            $table->integer('quantite');

            // §7.3 « péremption » : le lot et sa date. `nullable` — une officine qui ne suit pas ses
            // lots reste servie par le reste du mécanisme, l'absence se dit plutôt qu'elle ne
            // bloque.
            $table->string('lot', 60)->nullable();
            $table->date('date_peremption')->nullable();

            $table->string('motif', 200)->nullable();

            // QUI a fait le mouvement. Identifiant sans clé étrangère (ADR-042 D1) + nom FIGÉ :
            // le fait doit continuer de nommer son auteur même si le compte disparaît.
            $table->unsignedBigInteger('agent_user_id')->nullable();
            $table->string('agent_nom', 200);

            // B3-a — la sortie provoquée par une délivrance. Identifiant, pas clé étrangère : le
            // mouvement de stock est un fait de l'officine, il ne disparaît pas si le patient
            // supprime son ordonnance de son carnet.
            $table->unsignedBigInteger('delivrance_id')->nullable();

            $table->timestamp('created_at')->useCurrent();

            $table->index('stock_id', 'idx_mouvement_stock');
            $table->index('delivrance_id', 'idx_mouvement_delivrance');
            $table->index('date_peremption', 'idx_mouvement_peremption');
        });

        // ── La fiche officine (§7.4) ─────────────────────────────────────────────────────────
        Schema::table('structures_sanitaires', function (Blueprint $table) {
            $table->string('pharmacien_responsable', 200)->nullable()->after('directeur');
            $table->string('numero_licence', 60)->nullable()->after('pharmacien_responsable');
            $table->boolean('livraison_disponible')->default(false)->after('numero_licence');
            $table->unsignedSmallInteger('rayon_livraison_km')->nullable()->after('livraison_disponible');
        });

        $this->poserGardes();
    }

    public function down(): void
    {
        $this->retirerGardes();

        Schema::table('structures_sanitaires', function (Blueprint $table) {
            $table->dropColumn([
                'pharmacien_responsable', 'numero_licence',
                'livraison_disponible', 'rayon_livraison_km',
            ]);
        });

        Schema::dropIfExists('mouvements_stock');
        Schema::dropIfExists('stocks_officine');
    }

    private function poserGardes(): void
    {
        $pilote = Schema::getConnection()->getDriverName();

        // La cohérence type ⇄ signe, et l'append-only. Un `CHECK` suffirait pour la première (ni
        // `type` ni `quantite` ne subissent d'action référentielle, donc pas d'erreur 3823), mais
        // SQLite refuse `ALTER TABLE … ADD CONSTRAINT` : la garantie ne vaudrait qu'en production.
        // Déclencheurs dans les DEUX dialectes — divergence refusée depuis P6.8c et P6.8e.
        if ($pilote === 'mysql') {
            DB::unprepared("
                CREATE TRIGGER trg_mouvement_stock_insert BEFORE INSERT ON mouvements_stock
                FOR EACH ROW
                BEGIN
                    IF NEW.quantite = 0 THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Un mouvement de stock porte une quantite non nulle.';
                    END IF;
                    IF NEW.type = 'entree' AND NEW.quantite < 0 THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Une entree de stock est positive.';
                    END IF;
                    IF NEW.type IN ('sortie', 'peremption') AND NEW.quantite > 0 THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Une sortie ou une peremption est negative.';
                    END IF;
                END
            ");

            // L'apostrophe est DOUBLÉE : ce texte part dans une chaîne SQL entre apostrophes, et
            // « ne s'efface » y refermerait la chaîne. Trouvé au G2 live, la migration refusant de
            // se rejouer — le genre de défaut qu'aucune relecture ne montre.
            foreach (['UPDATE' => 'ne se modifie pas', 'DELETE' => "ne s''efface pas"] as $moment => $verbe) {
                DB::unprepared('
                    CREATE TRIGGER trg_mouvement_stock_'.strtolower($moment)." BEFORE {$moment} ON mouvements_stock
                    FOR EACH ROW
                    BEGIN
                        SIGNAL SQLSTATE '45000'
                          SET MESSAGE_TEXT = 'Un mouvement de stock {$verbe} : corrigez par un ajustement.';
                    END
                ");
            }

            return;
        }

        DB::unprepared("
            CREATE TRIGGER trg_mouvement_stock_insert BEFORE INSERT ON mouvements_stock
            FOR EACH ROW
            WHEN NEW.quantite = 0
              OR (NEW.type = 'entree' AND NEW.quantite < 0)
              OR (NEW.type IN ('sortie', 'peremption') AND NEW.quantite > 0)
            BEGIN
                SELECT RAISE(ABORT, 'Quantite nulle, ou signe contraire au type du mouvement.');
            END
        ");

        foreach (['UPDATE', 'DELETE'] as $moment) {
            DB::unprepared('
                CREATE TRIGGER trg_mouvement_stock_'.strtolower($moment)." BEFORE {$moment} ON mouvements_stock
                FOR EACH ROW
                BEGIN
                    SELECT RAISE(ABORT, 'Un mouvement de stock ne se modifie ni ne s''efface : corrigez par un ajustement.');
                END
            ");
        }
    }

    private function retirerGardes(): void
    {
        foreach (['insert', 'update', 'delete'] as $moment) {
            DB::unprepared("DROP TRIGGER IF EXISTS trg_mouvement_stock_{$moment}");
        }
    }
};
