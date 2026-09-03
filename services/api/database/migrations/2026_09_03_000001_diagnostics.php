<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * B2-b — LE DIAGNOSTIC POSÉ EN CONSULTATION (CDC_11 §5.2, CDC_04 §103).
 *
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 * UN DIAGNOSTIC DE CONSULTATION N'EST PAS UN ANTÉCÉDENT — ET C'EST LA DÉCISION CENTRALE
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 *
 * La table la plus proche existait déjà : `antecedents` porte `maladie_id`, un libellé figé et une
 * `description`. Y écrire chaque diagnostic aurait été bien plus court, et le code du projet dit
 * déjà pourquoi c'est faux — `RegistreRetourTriage` (P10c-2-i) :
 *
 *   « le seul endroit où une maladie codée se pose aujourd'hui est un ANTÉCÉDENT — or `antecedents`
 *     porte aussi `impact_triage`, qui alimente le score des triages suivants : y consigner chaque
 *     grippe la transformerait en antécédent permanent pesant sur toutes les orientations futures.
 *     On dégraderait l'orientation qu'on cherche à améliorer. »
 *
 * Un antécédent est un fait qui SUIT le patient ; un diagnostic de consultation est un fait qui
 * DATE d'un épisode. Le passage de l'un à l'autre existe (`antecedent_id` ci-dessous) mais c'est
 * un **acte délibéré du médecin**, jamais une conséquence automatique de la saisie.
 *
 * ═══ CE QUI N'EST DÉLIBÉRÉMENT PAS DANS CETTE TABLE ═══
 *
 *  - **Pas de `membre_id`.** Le patient est celui de la consultation. Le dénormaliser créerait une
 *    ligne capable de désigner un autre patient que son propre acte — deux vérités sur le fait le
 *    plus grave qui soit. Une jointure coûte moins cher qu'une incohérence.
 *  - **Pas de `principal`/`secondaire`, pas de `certitude`.** Le §5.2 dit « poser un diagnostic »
 *    et le CDC_04 §103 nomme la table sans plus. Inventer une hiérarchie clinique serait une
 *    affirmation non sourcée — refus déjà opposé par P6.8c à une colonne `categorie`. Additif le
 *    jour où un consommateur réel l'exige.
 *  - **Pas d'auteur.** La consultation porte déjà `soignant_user_id`/`soignant_nom`, et elle n'a
 *    qu'un auteur (garde 4 de B2-a). Le recopier ici, c'est la même chose écrite deux fois.
 *
 * ═══ POURQUOI AUCUN DÉCLENCHEUR DE COHÉRENCE SUR `maladie_*` ═══
 *
 * Il serait tentant d'exiger « `maladie_id` nul ⟺ `maladie_code` nul ». Ce serait le piège exact
 * qu'ADR-042 a documenté : `maladie_id` est une clé étrangère `nullOnDelete` (comme sur
 * `antecedents`, P6.8c), donc supprimer une maladie du référentiel la met à NULL **sans toucher**
 * le code et le libellé figés — et le déclencheur crierait alors sur une ligne que personne n'a
 * modifiée. Les valeurs figées doivent SURVIVRE à la disparition de leur source : c'est leur
 * raison d'être. Un garde-fou plus strict que sa propre règle est un défaut, même quand il refuse
 * par prudence (leçon P6.8c sur la collation).
 *
 * La seule garantie du moteur ici est donc déclarative — `UNIQUE(antecedent_id)` : un antécédent
 * ne peut pas être la promotion de deux diagnostics. On n'invente pas un déclencheur pour la
 * forme ; « un contrôle toujours vert ne prouve rien » (P5.3b-4).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('diagnostics', function (Blueprint $table) {
            $table->id();

            // Un diagnostic n'existe QUE dans sa consultation : `cascadeOnDelete` dit exactement
            // cela. (La consultation, elle, ne se supprime pas — B2-a n'a pas d'état « annulée » :
            // un acte ouvert a eu lieu.)
            $table->foreignId('consultation_id')->constrained('consultations')->cascadeOnDelete();

            // Les mots du médecin. Contenu clinique → chiffré, comme `antecedents.description` et
            // `notes_observations.contenu`. JAMAIS réécrit par le serveur (leçon P6.7a, dont la
            // réécriture inscrivait le nom du mauvais médecin) : le lien s'ajoute À CÔTÉ.
            $table->text('libelle');

            // Le lien FACULTATIF au référentiel national, relu et FIGÉ par le serveur
            // (`ServiceLienMaladie`, P6.8c). Facultatif parce que le référentiel livré est un jeu
            // de démonstration et qu'une maladie émergente n'est dans aucune nomenclature au moment
            // où elle émerge : l'imposer ferait de nos lacunes un blocage clinique.
            $table->foreignId('maladie_id')->nullable()->constrained('maladies')->nullOnDelete();
            $table->string('maladie_code', 12)->nullable();
            $table->string('maladie_libelle', 200)->nullable();

            // La promotion vers les antécédents, quand le médecin la décide. `nullOnDelete` et non
            // un identifiant figé : si le patient retire l'antécédent de son carnet, le diagnostic
            // ne doit pas rester marqué « promu » — sinon plus personne ne pourrait le promouvoir
            // à nouveau, et l'écran mentirait sur l'état du dossier.
            $table->foreignId('antecedent_id')->nullable()->constrained('antecedents')->nullOnDelete();

            $table->timestamps();

            $table->unique('antecedent_id', 'uq_diagnostic_antecedent');
            $table->index('consultation_id', 'idx_diagnostic_consultation');
            $table->index('maladie_code', 'idx_diagnostic_maladie_code');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('diagnostics');
    }
};
