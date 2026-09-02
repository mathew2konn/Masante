<?php

use App\Support\StatutVersionModeleIa;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P10c-3-i — L'export anonymisant + le registre de gouvernance des modèles IA
 * (CDC_05 §7.2/§8/§9 ; CDC_13 §7.3/§10/§12 ; CDC_04 §5.2/§123).
 *
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 * `exports_jeu_entrainement` — LÀ OÙ LA PSEUDONYMISATION DEVIENT UNE ANONYMISATION (F4, F20)
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 *
 * P10c-2-i l'avait dit avant de coder : `jeux_donnees_entrainement` porte `triage_id`, donc
 * quiconque a la base peut remonter au patient — c'est une PSEUDONYMISATION. Cette table est le
 * mécanisme qui rend l'anonymisation EFFECTIVE : elle ne porte plus `triage_id`, plus l'âge exact
 * (généralisé en bande, config `masante.triage_ia.bandes_age`), plus la date exacte (réduite au
 * mois — colonne `annee_mois` dans l'instantané). C'est la définition technique de CDC_13 §12 :
 * « suppression des identifiants directs, généralisation des quasi-identifiants ».
 *
 * `instantane_json` — motif du « référentiel entier + instantané JSON » de P6.3-D1 : une COPIE
 * anonymisée, jamais une vue recalculée sur `jeux_donnees_entrainement` (qui, elle, reste
 * pseudonymisée pour toujours — motif P6.3, un référentiel diffusé doit être ce qu'on a vraiment
 * relu). `k_estime` est CALCULÉ et AFFICHÉ, jamais un seuil bloquant : sur un volume de dizaines de
 * lignes, un seuil de k-anonymat bloquant rendrait l'export perpétuellement impossible sans protéger
 * personne de plus — même raisonnement que P6.7a sur les codes LOINC (« un contrôle qu'on ne peut
 * pas satisfaire n'est pas une exigence, c'est un mur »).
 *
 * `cree_par` en `nullOnDelete` : un export est un ARTEFACT d'audit (qui l'a produit, quand), pas une
 * relation vivante — supprimer le compte de l'agent ne doit pas faire disparaître la trace de
 * l'export (motif ADR-042 D1, transposé ici à un utilisateur réel, pas à un identifiant de journal).
 *
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 * `versions_modeles` — LE REGISTRE (§8), SÉPARÉ DU TRACKING MLFLOW
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 *
 * MLflow (`file:./mlruns`, côté `triage-service`, motif ADR-017) trace les EXPÉRIENCES — params,
 * métriques et artefacts d'UN run d'entraînement. Cette table porte la GOUVERNANCE — qui a entraîné,
 * qui a validé, quand, avec quel statut. Les deux ne se confondent pas : MLflow n'a aucune notion de
 * permission ni de quatre-yeux, et la gouvernance clinique du §9 n'a pas sa place dans un tracker
 * d'expériences.
 *
 * `statut` : vocabulaire ADOPTÉ du §8 — voir {@see StatutVersionModeleIa}. `actif` et
 * `archive` existent dans l'ENUM sans être atteignables ici, même motif que `predictions_ia.mode`
 * portant `hybride` avant que P10c-2-i ne le rende accessible.
 *
 * `entraine_par`/`valide_par` en `nullOnDelete` — identifiants d'audit, pas des relations vivantes
 * (ADR-042 D1) : un modèle candidat ne doit pas disparaître si le compte qui l'a produit est purgé.
 *
 * `mlflow_run_id` : la SEULE clé qui relie cette ligne à son run MLflow — délibérément pas de FK
 * (MLflow vit dans un système de fichiers du service Python, hors de portée d'une contrainte SQL).
 *
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 * `metriques_modeles` — CLÉ/VALEUR, PAS DES COLONNES LARGES
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 *
 * exactitude/precision/rappel/f1/rappel_sous_triage/auc (F16) — une ligne de plus (une nouvelle
 * métrique suivie demain) n'est jamais une migration. `cascadeOnDelete` sur `version_id` :
 * contrairement aux identifiants d'audit ci-dessus, une métrique SANS sa version n'a plus aucun
 * sens — ce n'est pas un journal, c'est une décomposition d'un même fait.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exports_jeu_entrainement', function (Blueprint $table): void {
            $table->id();
            $table->char('pays_code', 2);
            $table->unsignedInteger('numero_export');
            $table->unique(['pays_code', 'numero_export'], 'uq_export_pays_numero');

            // Les lignes anonymisées elles-mêmes — voir l'en-tête pour ce qui en sort
            // (triage_id, âge exact, date exacte) et ce qui y reste (constantes/symptômes,
            // à précision clinique : les généraliser détruirait le signal, F20).
            $table->json('instantane_json');

            $table->unsignedInteger('nb_lignes');

            // Taille du plus petit groupe (bande d'âge, sexe, mois-année) — CALCULÉE et AFFICHÉE,
            // jamais bloquante (voir en-tête). Nullable : un export à 0 ligne n'a pas de groupe.
            $table->unsignedInteger('k_estime')->nullable();

            $table->foreignId('cree_par')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cree_le')->useCurrent();
        });

        Schema::create('versions_modeles', function (Blueprint $table): void {
            $table->id();
            $table->char('pays_code', 2);
            $table->unsignedInteger('numero_version');
            $table->unique(['pays_code', 'numero_version'], 'uq_version_modele_pays_numero');

            $table->foreignId('export_id')->constrained('exports_jeu_entrainement')->restrictOnDelete();

            $table->enum('statut', ['candidat', 'valide', 'actif', 'archive'])
                ->default('candidat');

            $table->string('mlflow_run_id', 64);

            $table->foreignId('entraine_par')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('valide_par')->nullable()->constrained('users')->nullOnDelete();

            // Nullable et JAMAIS rétroactive : posée seulement au moment RÉEL de la validation
            // (précédent constant du projet — L2, `protocole_code`, `modele_version`).
            $table->timestamp('date_validation_clinique')->nullable();

            $table->timestamp('cree_le')->useCurrent();
        });

        Schema::create('metriques_modeles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('version_id')->constrained('versions_modeles')->cascadeOnDelete();
            $table->string('cle', 40);
            $table->decimal('valeur', 8, 5);
            $table->timestamp('mesure_le')->useCurrent();

            $table->unique(['version_id', 'cle'], 'uq_metrique_version_cle');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('metriques_modeles');
        Schema::dropIfExists('versions_modeles');
        Schema::dropIfExists('exports_jeu_entrainement');
    }
};
