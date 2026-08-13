<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P6.5a — Référentiel national des professionnels de santé (CDC_09 §5 ; ADR-024, ADR-031).
 *
 * STRICTEMENT ADDITIVE. `medecins` est référencée par `rendez_vous.medecin_id` (P4, validé G5) et
 * par `referents.medecin_id` (Module 5, voie du médecin référent) : aucune colonne existante n'est
 * modifiée ni supprimée, aucune n'est rendue obligatoire.
 *
 * LA TABLE N'EST PAS RENOMMÉE. CDC_04 §5.2 l'appelle `professionnels_sante` ; P6.4a avait le même
 * écart (`structures_sanitaires` vs `etablissements` du CDC) et a conservé le nom existant. Un
 * renommage ferait migrer 29 fichiers pour un gain de vocabulaire — ADR-024 dit enrichissement,
 * jamais remplacement.
 *
 * CE QUE LA MIGRATION APPORTE :
 *  1. `professionnel_compteurs` — la séquence du numéro professionnel, par pays.
 *  2. L'identité professionnelle du §5.2 sur `medecins` : numéro national, profession, ordre,
 *     AUTORISATION D'EXERCER, contacts, éléments de CDC_11 §3.4 (biographie, langues, modes de
 *     consultation).
 *  3. `professionnel_etablissement` — les exercices MULTIPLES du §5.2 (décision propriétaire P2),
 *     sans toucher au `structure_id` dont dépendent P3 et P4.
 *  4. `professionnel_diplomes` — CDC_04 §5.2, §5.2 « diplômes (optionnel) ».
 *
 * TOUT EST NULLABLE, comme en P6.4a et pour la même raison : la base porte des fiches qui n'ont
 * aucune de ces informations. Une valeur par défaut inventée — « autorisation : valide » sur un
 * praticien dont on ne sait rien — serait une donnée FAUSSE, pas une donnée manquante. Et ici
 * l'enjeu n'est pas cosmétique : c'est cette colonne que le §5.4 interrogera avant de laisser
 * signer une ordonnance. L'absence doit se dire, pour que le contrôle puisse refuser.
 */
return new class extends Migration
{
    /**
     * Les onze métiers du §5.1, dans son ordre.
     *
     * POURQUOI CETTE LISTE EST ÉCRITE EN TOUTES LETTRES ICI alors que `App\Support\ProfessionsSante`
     * se présente comme la source unique : une migration est un ENREGISTREMENT HISTORIQUE. Si elle
     * lisait la classe applicative, le jour où l'on ajoute un métier, cette migration — déjà jouée
     * en production — changerait rétroactivement de sens, et `migrate:fresh` produirait un schéma
     * différent de celui qu'a réellement obtenu la base de production.
     *
     * La duplication est donc voulue, mais elle n'est pas laissée sans garde : un test dédié
     * compare cette liste à `ProfessionsSante::codes()` et casse le build à la moindre divergence.
     * Même dispositif que les vecteurs partagés TS↔PHP de P6.1 — on ne fait pas confiance à la
     * discipline, on outille.
     */
    private const PROFESSIONS = [
        'medecin_generaliste', 'medecin_specialiste', 'chirurgien', 'dentiste', 'sage_femme',
        'infirmier', 'pharmacien', 'biologiste', 'radiologue', 'psychologue', 'kinesitherapeute',
    ];

    public function up(): void
    {
        // ─────────────────────────────────────────────────────────────────────────────
        // 1. Séquence du numéro professionnel — une ligne par pays.
        // ─────────────────────────────────────────────────────────────────────────────
        //
        // Même forme qu'`etablissement_compteurs` (P6.4a) : pas d'année dans la clé. Un
        // professionnel n'est pas daté par son numéro, et §5.2 n'en montre aucune trace.
        Schema::create('professionnel_compteurs', function (Blueprint $table) {
            $table->char('pays_code', 2)->primary();
            $table->unsignedBigInteger('dernier')->default(0);
            $table->timestamps();
        });

        // ─────────────────────────────────────────────────────────────────────────────
        // 2. L'identité professionnelle sur `medecins` (§5.2 + CDC_11 §3.4).
        // ─────────────────────────────────────────────────────────────────────────────
        Schema::table('medecins', function (Blueprint $table) {
            // Numéro national — `PRO` + 6 chiffres, LITTÉRAL, sans clé de contrôle (le §3.2 impose
            // un checksum pour le NIS et pas ici). NULLABLE : les fiches en place n'en ont pas
            // encore, la commande de backfill les sert. L'unicité est garantie dès maintenant.
            $table->string('numero_professionnel', 12)->nullable()->after('id');

            // Le pays QUALIFIE le numéro, il ne s'écrit pas dedans — d'où l'unicité sur le COUPLE,
            // plus bas. Deux pays peuvent tous deux avoir un `PRO000001` : le numéro est national,
            // pas mondial (P6.4a ; le NIS, lui, discrimine DANS sa valeur parce qu'un patient
            // traverse les frontières, pas un ordre professionnel).
            $table->char('pays_code', 2)->default('CI')->after('numero_professionnel');

            // Le périmètre du §5.1. `specialite` reste le libellé libre affiché (décision P4 :
            // le référentiel des spécialités est l'étape 8) ; `profession` dit le MÉTIER, qui
            // n'est pas la même chose — un radiologue et un cardiologue sont tous deux médecins.
            $table->enum('profession', self::PROFESSIONS)->nullable()->after('specialite');
            $table->string('sous_specialite', 100)->nullable()->after('profession');

            $table->enum('sexe', ['M', 'F'])->nullable()->after('prenom');
            $table->date('date_naissance')->nullable()->after('sexe');

            // Ordre professionnel (§5.2). Deux colonnes et non une : l'ordre est l'institution,
            // le numéro d'ordre est l'inscription. Un praticien peut être connu de l'ordre sans
            // que l'on dispose de son numéro, et l'inverse n'aurait pas de sens.
            $table->string('ordre_professionnel', 150)->nullable();
            $table->string('numero_ordre', 60)->nullable();

            // ═══ AUTORISATION D'EXERCER — le bloc que le §5.4 interrogera ═══
            //
            // §5.4 exige de vérifier « autorisation d'exercer » ET « expiration » avant CHAQUE
            // signature. Ce sont deux contrôles distincts et non un seul : une autorisation peut
            // être retirée avant son terme (statut), ou simplement arriver à échéance (date).
            // Les confondre laisserait passer l'un des deux cas.
            $table->string('autorisation_numero', 60)->nullable();
            $table->enum('autorisation_statut', ['valide', 'suspendue', 'retiree'])->nullable();
            $table->date('autorisation_delivree_le')->nullable();
            $table->date('autorisation_expire_le')->nullable();

            // Formation (§5.2 : « diplômes (optionnel), université (optionnel), date d'obtention
            // (optionnel) »). Le détail multi-diplômes vit dans `professionnel_diplomes` ; ces
            // trois colonnes portent le diplôme principal, celui qu'affiche la fiche.
            $table->string('universite', 150)->nullable();
            $table->unsignedSmallInteger('annee_diplome')->nullable();
            $table->unsignedTinyInteger('experience_annees')->nullable();

            $table->string('telephone', 30)->nullable();
            $table->string('email', 190)->nullable();
            $table->text('biographie')->nullable();

            // CDC_11 §3.4. Listes plutôt que colonnes plates : « langues parlées » n'a pas de
            // cardinalité fixe.
            $table->json('langues_json')->nullable();
            $table->boolean('consultation_en_ligne')->default(false);
            $table->boolean('consultation_physique')->default(true);

            $table->unique(['pays_code', 'numero_professionnel'], 'uq_professionnel_numero');
            $table->index(['pays_code', 'profession'], 'idx_professionnel_profession');
        });

        // ─────────────────────────────────────────────────────────────────────────────
        // 3. Exercices multiples (§5.2 « établissementS d'exercice » ; décision P2).
        // ─────────────────────────────────────────────────────────────────────────────
        //
        // POURQUOI UNE TABLE PLUTÔT QUE DE RENDRE `structure_id` MULTIPLE : `medecins.structure_id`
        // est NOT NULL et lu par P3 (annuaire), P4 (rendez-vous) et les référents, tous validés
        // G5. Il demeure et devient l'exercice PRINCIPAL. La table le complète, elle ne le
        // remplace pas — ADR-024.
        //
        // Le principal est donc représenté DEUX FOIS après backfill : dans `structure_id` et dans
        // une ligne `est_principal = true`. C'est une redondance assumée : la supprimer d'un côté
        // ou de l'autre casserait soit les modules G5, soit la complétude du référentiel. La
        // commande de backfill garantit qu'elles disent la même chose.
        Schema::create('professionnel_etablissement', function (Blueprint $table) {
            $table->id();

            $table->foreignId('medecin_id')->constrained('medecins')->cascadeOnDelete();
            $table->foreignId('structure_id')->constrained('structures_sanitaires')->cascadeOnDelete();
            // Le service d'exercice dans CET établissement. Nullable : on peut savoir qu'un
            // praticien exerce dans un hôpital sans savoir dans quel service.
            $table->foreignId('service_id')->nullable()
                ->constrained('services_etablissement')->nullOnDelete();

            $table->boolean('est_principal')->default(false);
            $table->boolean('actif')->default(true);
            $table->date('debut_le')->nullable();
            $table->date('fin_le')->nullable();

            $table->timestamps();

            // Un praticien n'exerce qu'une fois dans un même établissement : deux lignes
            // identiques ne voudraient rien dire de plus, et fausseraient tout dénombrement.
            $table->unique(['medecin_id', 'structure_id'], 'uq_exercice_professionnel');
            $table->index(['structure_id', 'actif'], 'idx_exercice_structure');
        });

        // ─────────────────────────────────────────────────────────────────────────────
        // 4. Diplômes (CDC_04 §5.2 `professionnel_diplomes`).
        // ─────────────────────────────────────────────────────────────────────────────
        Schema::create('professionnel_diplomes', function (Blueprint $table) {
            $table->id();

            $table->foreignId('medecin_id')->constrained('medecins')->cascadeOnDelete();

            $table->string('intitule', 200);
            $table->string('universite', 150)->nullable();
            $table->string('pays_obtention', 100)->nullable();
            $table->unsignedSmallInteger('annee_obtention')->nullable();

            $table->timestamps();

            $table->index('medecin_id', 'idx_diplome_professionnel');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('professionnel_diplomes');
        Schema::dropIfExists('professionnel_etablissement');

        Schema::table('medecins', function (Blueprint $table) {
            $table->dropUnique('uq_professionnel_numero');
            $table->dropIndex('idx_professionnel_profession');
            $table->dropColumn([
                'numero_professionnel', 'pays_code', 'profession', 'sous_specialite',
                'sexe', 'date_naissance', 'ordre_professionnel', 'numero_ordre',
                'autorisation_numero', 'autorisation_statut',
                'autorisation_delivree_le', 'autorisation_expire_le',
                'universite', 'annee_diplome', 'experience_annees',
                'telephone', 'email', 'biographie', 'langues_json',
                'consultation_en_ligne', 'consultation_physique',
            ]);
        });

        Schema::dropIfExists('professionnel_compteurs');
    }
};
