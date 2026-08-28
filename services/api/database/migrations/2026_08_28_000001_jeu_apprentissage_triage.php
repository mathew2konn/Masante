<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P10c-2-i (F4) — Le jeu d'apprentissage §5.5.4/§7.2 : pseudonymisé, et validé avant export
 * (CDC_05 §7.2 « Anonymisation → Validation par les médecins → Jeu d'entraînement »).
 *
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 * UNE LIGNE NAÎT QUAND UN RETOUR EST DONNÉ, PAS AVANT — LE LABEL EST F3, PAS UNE ISSUE INOBSERVABLE
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 *
 * Le §5.5.4 attend un diagnostic final et une issue à 48 h ; ce projet n'observe ni l'un ni
 * l'autre (constat Y7 du G1 : aucune entité consultation, aucun suivi hospitalier). Le label vient
 * donc de l'appréciation du soignant sur l'ORIENTATION elle-même — {@see RegistreRetourTriage} —
 * la seule chose qu'un médecin peut juger au chevet, sans suivi, et qui n'est PAS dérivable des
 * bandes du protocole (donc hors de la tautologie A3 du plan racine).
 *
 * ═══ LES COLONNES SONT PLATES, ALORS QUE `triage_constantes` EST NORMALISÉE ═══
 *
 * `triage_constantes` porte une ligne par type de constante (motif P6.6a : une table, pas un
 * blob). Ici c'est l'inverse, délibérément : cette table est un **instantané d'apprentissage**, pas
 * un journal clinique — elle doit porter exactement ce qu'un vecteur de features contiendrait,
 * dans la forme où `RequeteTriageScore` du service Python le recevrait (schéma Pydantic, pas une
 * classe PHP — sans backslash pour que Pint ne lui invente pas un `use` vers une classe absente).
 * Deux tables, deux natures, deux formes — les confondre aurait produit une jointure à chaque ligne
 * pour un gain nul.
 *
 * ═══ AUCUNE IDENTITÉ — C'EST LE CŒUR DE LA PROMESSE F4 ═══
 *
 * Ni `patient_nom`, ni `membre_id`, ni `user_id`, ni NIS, ni date de naissance. `triage_id` est
 * conservé — **et c'est dit avant de coder, pas découvert après** : tant qu'il y reste, c'est une
 * PSEUDONYMISATION, pas une anonymisation (quiconque a la base peut remonter au patient via
 * `triages.membre_id`). Prétendre le contraire serait le genre d'affirmation que ce projet refuse
 * ailleurs (le « validé cliniquement » d'ADR-017, la source `demonstration` de P6.7a).
 * L'anonymisation devient effective à l'EXPORT, qui retire ce lien — et l'export est en P10c-3.
 *
 * `triage_id` est un IDENTIFIANT, pas une relation vivante (décision D1 d'ADR-042, précédent direct
 * dans ce même module : `acces_dossier.triage_id`) : une action référentielle le mettrait à NULL le
 * jour où un triage serait purgé, et effacerait de la base d'apprentissage la trace qui permet
 * l'idempotence (§9.2) — sans qu'aucune donnée personnelle n'en dépende, puisqu'il n'y en a pas ici.
 *
 * ═══ `niveau_protocole` EST NOT NULL, LES CONSTANTES SONT NULLABLES ═══
 *
 * Le niveau rendu est TOUJOURS connu (`TriageService::analyser()` ne retourne jamais sans lui) ;
 * les constantes du §5.2, elles, restent facultatives à l'écran (E1 de P10c-1) — une ligne sans
 * aucune constante est un vecteur pauvre, pas une ligne invalide.
 *
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 * `validations_medecins` — UNE LIGNE NON VALIDÉE N'ENTRE JAMAIS DANS UN EXPORT
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 *
 * Le §7.2 veut qu'un médecin juge le cas **sans voir de qui il s'agit** — ce que la pseudonymisation
 * ci-dessus permet déjà. La validation est un ACTE DE DÉCISION, pas un état par défaut : la ligne
 * n'existe qu'une fois la décision prise (`valide` ou `rejete`), jamais « en attente » — un statut
 * intermédiaire aurait fallu l'écrire puis le changer, deux écritures pour un fait qui n'en est
 * qu'un. Ce n'est pas un socle à vide (refusé par P6.3-D3) : le contrôle qui filtre l'export sur
 * cette table est prouvable par vecteur dès cet incrément, avant même qu'un export existe.
 *
 * Une seule validation par ligne (`UNIQUE jeu_id`) : ce n'est pas un vote, c'est une décision.
 * `cascadeOnDelete` sur `jeu_id` — contrairement à `triage_id` ci-dessus, ce n'est PAS un journal
 * d'audit : si la ligne d'apprentissage est purgée, sa validation n'a plus de sens et disparaît
 * avec elle. `valide_par` en `nullOnDelete` : la décision reste lisible si le compte du médecin est
 * supprimé, seule son identité se perd — régime ordinaire, cette table n'est pas une chaîne
 * hachée (contrairement à `protocole_applications`, dont le régime d'audit strict est réservé au
 * jour où cette table portera elle-même du contenu clinique explicable, F10).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('jeux_donnees_entrainement', function (Blueprint $table): void {
            $table->id();

            $table->unsignedBigInteger('triage_id')
                ->comment('Identifiant, pas une relation vivante (ADR-042 D1) — idempotence et traçabilité §9.2, aucune donnée personnelle.');
            $table->index('triage_id', 'idx_jeu_apprentissage_triage');

            // ═══ FEATURES — AUCUNE IDENTITÉ ═══
            $table->unsignedTinyInteger('age')->nullable();
            $table->enum('sexe', ['M', 'F'])->nullable();
            $table->json('symptomes_json');

            $table->decimal('temperature', 8, 2)->nullable();
            $table->decimal('pouls', 8, 2)->nullable();
            $table->decimal('saturation_o2', 8, 2)->nullable();
            $table->decimal('tension_systolique', 8, 2)->nullable();
            $table->decimal('tension_diastolique', 8, 2)->nullable();
            $table->decimal('poids', 8, 2)->nullable();

            $table->unsignedSmallInteger('duree_jours')->nullable();
            $table->unsignedTinyInteger('intensite')->nullable();
            $table->boolean('grossesse')->nullable();

            // Contexte d'issue (F3) : le niveau REELLEMENT rendu par ce triage. Toujours connu.
            $table->string('niveau_protocole', 60);

            // Le label — vocabulaire de {@see \App\Support\RegistreRetourTriage}, jamais un texte
            // libre : un jeu d'apprentissage aux étiquettes incomparables ne s'entraîne pas.
            $table->enum('label', ['adaptee', 'sur_triage', 'sous_triage']);

            $table->timestamp('cree_le')->useCurrent();
        });

        Schema::create('validations_medecins', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('jeu_id')
                ->unique()
                ->constrained('jeux_donnees_entrainement')
                ->cascadeOnDelete();

            $table->foreignId('valide_par')->nullable()->constrained('users')->nullOnDelete();

            $table->enum('statut', ['valide', 'rejete']);

            // Obligatoire sur un rejet, jamais sur une validation — même régime que la
            // justification d'écart de {@see \App\Services\Triage\ServiceRetourTriage} : on ne
            // motive pas un accord.
            $table->text('motif')->nullable();

            $table->timestamp('decidee_le')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('validations_medecins');
        Schema::dropIfExists('jeux_donnees_entrainement');
    }
};
