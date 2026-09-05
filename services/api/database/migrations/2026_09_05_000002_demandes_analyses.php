<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * B5-a — LA DEMANDE D'EXAMEN, ANALOGUE DE L'ORDONNANCE (CDC_11 §8.1, CDC_09 §7.4, CDC_04 §109).
 *
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 * LE CIRCUIT EST LA TRANSPOSITION DE B2-c → B3-a (décision L1 du plan G1)
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 *
 * Un praticien produit une pièce (B2-c, `ordonnances`) ; un professionnel d'un autre métier la lit
 * PAR UN JETON, sans ouvrir le dossier, et enregistre son acte (B3-a, `delivrances`). Le laboratoire
 * est le même parcours avec d'autres acteurs — `demandes_analyses` est l'analogue exact
 * d'`ordonnances`, et c'est pour cela qu'elle en reprend le schéma trait pour trait : `medecin_id`/
 * `structure_id` en FK `nullOnDelete` (patron B2-c), `jeton_partage` en secret d'accès (patron
 * B3-a), `source`/`added_by` réécrits par le serveur (patron F2.13).
 *
 * ═══ CE QUI IDENTIFIE UN EXAMEN N'EST PAS CE QUI DÉCRIT UN TRAITEMENT (L9, question REPOSÉE) ═══
 *
 * B3-a a tranché pour les médicaments : l'identité du produit en clair, le traitement de la
 * personne chiffré. La transposition littérale serait FAUSSE ici — une valeur biologique EST
 * elle-même une donnée de santé, là où « Paracétamol 500 mg » est une identité de produit. Ce lot
 * n'ouvre donc PAS `resultats_json` (K3) : il ne construit que la DEMANDE, où :
 *
 *   EN CLAIR  — `analyses_json.*.libelle`/`analyse_id` projetés en clair dans
 *               `demande_analyse_lignes` : sans eux, ni le laboratoire ne sait quoi analyser, ni
 *               le catalogue ne sert à rien (patron `ordonnance_lignes.nom`).
 *   CHIFFRÉ   — `renseignements_cliniques` (ce que le médecin dit au biologiste) et
 *               `conditions_prelevement` par ligne (à jeun, etc.) : ce sont des données de santé
 *               sur CETTE personne, pas l'identité d'un examen.
 *
 * `analyses_json` reste le contrat d'écriture chiffré (patron `medicaments_json`), source des
 * lignes projetées — jamais une seconde saisie (refus P6.6a).
 *
 * ═══ `consultation_id` EST UN IDENTIFIANT, PAS UNE RELATION VIVANTE (ADR-042 D1) ═══
 *
 * DIVERGENCE ASSUMÉE avec `ordonnances.consultation_id` (vraie FK `nullOnDelete`, B2-c) : le plan
 * G1 la pose ici en `bigint` SANS contrainte. Une demande d'examen a une valeur médico-légale
 * propre — elle ouvre un circuit vers un tiers externe (le laboratoire) — et ne doit dépendre
 * d'aucune action référentielle sur `consultations` pour rester lisible.
 *
 * ═══ LE JETON ET L'ÉTIQUETTE SONT DEUX CHOSES DIFFÉRENTES (L5) ═══
 *
 * `jeton_partage` est le secret d'accès à LA DEMANDE (patron B3-a/P10a : 64 caractères, hors
 * `$fillable`, `$hidden`, comparaison en temps constant, 404 jamais 403). L'ÉTIQUETTE du
 * prélèvement (celle qui circule sur un tube) est une chose distincte, posée en B5-b sur
 * `prelevements.identifiant` — les deux ne doivent jamais être confondues : mettre un secret
 * d'accès sur une étiquette qui circule reviendrait à distribuer la clé du dossier avec
 * l'échantillon.
 *
 * ═══ `statut` : DÉRIVÉ DU CIRCUIT, JAMAIS POSÉ À LA MAIN ═══
 *
 * `emise` à la création ; `servie`/`annulee` sont des transitions que SEULS des services dédiés
 * poseront (B5-b/B5-c pour `servie`), jamais un `$fillable` ouvert au client — d'où son absence du
 * `$fillable` du modèle.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('demandes_analyses', function (Blueprint $table) {
            $table->id();

            $table->foreignId('membre_id')->constrained('membres_famille')->cascadeOnDelete();

            // Identifiant SANS contrainte (ADR-042 D1) — décision explicite du plan G1, divergente
            // de `ordonnances.consultation_id` : voir le commentaire de classe.
            $table->unsignedBigInteger('consultation_id')->nullable();

            // Le prescripteur — patron B2-c (`ordonnances`) : vraie relation `nullOnDelete`, et le
            // nom FIGÉ survit à la suppression du compte ou du praticien du référentiel.
            $table->foreignId('medecin_id')->nullable()->constrained('medecins')->nullOnDelete();
            $table->foreignId('structure_id')->nullable()
                ->constrained('structures_sanitaires')->nullOnDelete();
            $table->string('medecin_nom', 200)->nullable();
            // Nommée `structure_sanitaire`, PAS `structure_nom` : c'est le nom de colonne littéral
            // qu'`EcritureSoignantService::ecrire()` vérifie pour la réécrire depuis la fiche du
            // soignant — même colonne que sur `antecedents`/`ordonnances` (patron B2-c).
            $table->string('structure_sanitaire', 200)->nullable();

            $table->date('date_demande');

            // Le contrat d'écriture, chiffré — patron `medicaments_json`. Source des lignes
            // projetées dans `demande_analyse_lignes`, jamais une seconde saisie.
            $table->text('analyses_json')->nullable();

            // Ce que le médecin dit au biologiste — donnée de santé, chiffrée (L9).
            $table->text('renseignements_cliniques')->nullable();

            // Le jeton d'accès à CETTE demande (L5) — patron B3-a/P10a.
            $table->string('jeton_partage', 64)->nullable()->unique();

            $table->enum('statut', ['emise', 'servie', 'annulee'])->default('emise');

            $table->enum('source', ['patient', 'medecin', 'structure'])->default('patient');
            $table->enum('added_by', ['patient', 'medecin'])->default('patient');

            $table->timestamps();

            $table->index('membre_id', 'idx_demande_analyse_membre');
            $table->index('consultation_id', 'idx_demande_analyse_consultation');
            $table->index('medecin_id', 'idx_demande_analyse_medecin');
        });

        Schema::create('demande_analyse_lignes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('demande_id')->constrained('demandes_analyses')->cascadeOnDelete();

            // EN CLAIR — l'identité de l'examen (L9). Les mots du médecin, jamais réécrits (patron
            // `ordonnance_lignes.nom` / `resultats_json.*.parametre`).
            $table->string('libelle', 200);

            // Le lien FACULTATIF au catalogue national (L2) — patron P6.6b/P6.7a/P6.7b : le code et
            // l'unité sont RELUS au catalogue et FIGÉS quand ce lien est fourni, jamais crus du
            // client.
            $table->foreignId('analyse_id')->nullable()->constrained('analyses')->nullOnDelete();
            $table->string('code_national', 12)->nullable();
            $table->string('unite', 40)->nullable();

            // CHIFFRÉ — ce que cette personne doit faire avant le prélèvement (à jeun, etc.).
            $table->text('conditions_prelevement')->nullable();

            $table->unsignedSmallInteger('rang')->default(1);
            $table->timestamps();

            $table->index('demande_id', 'idx_demande_ligne_demande');
            $table->unique(['demande_id', 'analyse_id'], 'uq_demande_ligne_analyse');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('demande_analyse_lignes');
        Schema::dropIfExists('demandes_analyses');
    }
};
