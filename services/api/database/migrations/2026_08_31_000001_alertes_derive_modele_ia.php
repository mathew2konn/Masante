<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P10c-3-ii lot B (F37→F39) — `alertes_drift` (CDC_04 §123 ; CDC_05 §8).
 *
 * ═══ NOM ADOPTÉ, PAS RÉINVENTÉ ═══
 *
 * CDC_04 §123 nomme littéralement `alertes_drift`. Le projet écrit son code en français, mais il
 * n'a jamais renommé un objet que le corpus désigne — principe tenu depuis P6.8a (`predictions_ia`,
 * `versions_modeles`, `jeux_donnees_entrainement` sont dans le même cas).
 *
 * ═══ DEUX NATURES DE DÉRIVE, JAMAIS FONDUES EN UN INDICATEUR (F38) ═══
 *
 * `entree` : la population a changé (PSI par feature). `performance` : le rappel sur `sous_triage`
 * a chuté entre le jeu de test et la production. Les fondre masquerait le cas le plus utile — *une
 * population stable et une performance qui s'effondre* ne se soigne pas comme *une population qui
 * change*. Chaque alerte porte donc sa nature, et une même journée peut en produire des deux.
 *
 * ═══ DÉTECTION SEULE — AUCUNE COLONNE NE PERMET D'AGIR (F39) ═══
 *
 * Il n'y a ni `action`, ni `modele_desactive`, ni `traitee`. Ce n'est pas un oubli : retirer
 * automatiquement un modèle du service sur un indice statistique serait une décision
 * d'exploitation prise par une machine — la ligne que ce projet tient depuis ADR-017 (« détection
 * seule, jamais de gel »). L'alerte prévient, un humain décide, et il dispose déjà du rollback de
 * F24.
 *
 * ═══ IDEMPOTENCE PAR LA CLÉ, MOTIF DES RAPPROCHEMENTS ═══
 *
 * `UNIQUE(version_id, date_rapport, nature)` : rejouer le calcul d'un jour met à jour la ligne au
 * lieu d'en empiler une seconde. Motif exact des rapprochements du paiement (P5.5c) et du routage
 * de fraude (B1) — un rapport quotidien qui se duplique à chaque relance ne se lit plus.
 *
 * ═══ `version_id` : UNE CLÉ ÉTRANGÈRE, ET C'EST VOULU ═══
 *
 * Contrairement aux identifiants d'audit (ADR-042 D1), une alerte de dérive SANS sa version n'a
 * plus aucun sens : elle ne dit plus de quel modèle elle parle. Ce n'est pas un journal, c'est une
 * mesure attachée à un objet — même raisonnement que `metriques_modeles` en P10c-3-i, et
 * `cascadeOnDelete` pour la même raison.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('alertes_drift', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('version_id')->constrained('versions_modeles')->cascadeOnDelete();
            $table->date('date_rapport');
            $table->enum('nature', ['entree', 'performance']);

            // Le niveau retenu : `leger` ou `fort` pour une dérive d'entrée, `chute` pour la
            // performance. `stable` n'est jamais écrit — une absence d'alerte se lit à l'absence de
            // ligne, et remplir la table de « rien à signaler » la rendrait illisible.
            $table->string('niveau', 20);

            // Ce qui a bougé : le nom de la feature pour une dérive d'entrée, `rappel_sous_triage`
            // pour la performance.
            $table->string('indicateur', 60);
            $table->decimal('valeur', 8, 4);
            $table->decimal('seuil', 8, 4);

            // Le détail du calcul — les deux distributions comparées pour une dérive d'entrée, les
            // deux rappels pour la performance. Ce n'est pas une duplication des données d'entrée
            // (§9.2) : ce sont des COMPTES par catégorie, jamais des lignes cliniques.
            $table->json('detail_json')->nullable();

            $table->unsignedInteger('nb_lignes_reference');
            $table->unsignedInteger('nb_lignes_observees');

            $table->timestamp('cree_le')->useCurrent();

            $table->unique(['version_id', 'date_rapport', 'nature', 'indicateur'], 'uq_derive_rapport');
            $table->index(['date_rapport', 'niveau'], 'idx_derive_lecture');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alertes_drift');
    }
};
