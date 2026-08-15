<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * P6.8b — Vaccins disponibles et calendrier vaccinal national (CDC_09 §8, étape 8 du §14).
 *
 * ═══ LE DÉFAUT QUE CETTE MIGRATION OUTILLE ═══
 *
 * `vaccinations` porte deux colonnes que le CLIENT déclare librement : `obligatoire` et `statut`.
 * Ce sont EXACTEMENT — et uniquement — les deux que lit `FicheVitaleService`, l'écran montré à un
 * secouriste SANS authentification (carte vitale §5.1, SMS du bouton SOS §5.2, bris de glace §5.3),
 * sous un bloc intitulé « Vaccinations essentielles » et une icône de bouclier coché.
 *
 * Conséquence en deux sens : n'importe qui cochant « obligatoire + fait » fait apparaître au
 * secouriste une vaccination PRÉSENTÉE COMME ATTESTÉE ; et un BCG réellement administré, saisi sans
 * cocher la case, en est ABSENT. Le G1 de P6.8 classait ce constat comme un défaut de propreté du
 * modèle : il ne l'est pas.
 *
 * S'y ajoute que `statut` est écrit UNE FOIS, à la saisie, par les trois chemins d'écriture, et que
 * rien ne repasse jamais dessus : un « à faire » dépassé depuis six mois reste « à faire ». Un
 * statut qui ne peut pas changer avec le temps n'est pas un statut, c'est un souvenir — et il
 * s'affiche avec une pastille de couleur qui lui donne l'autorité d'un calcul.
 *
 * ═══ POURQUOI DEUX TABLES, ET NON UNE COLONNE D'ÂGE SUR LE VACCIN ═══
 *
 * Un calendrier vaccinal ne répond pas à « ce vaccin est-il fait ? » mais à « qu'est-ce qui est dû,
 * pour cette personne-là, aujourd'hui ? ». C'est la bascule de P6.7a, où une plage biologique s'est
 * révélée dépendre de la personne ; la variable est ici l'ÂGE.
 *
 * Le Pentavalent a trois doses, à 6, 10 et 14 semaines. Une colonne `age_du` sur `vaccins` n'en
 * porterait qu'une. D'où `calendrier_vaccinal` : UNE LIGNE PAR (vaccin, dose), exactement comme
 * `analyse_references` porte une ligne par strate.
 *
 * Et l'âge se compte EN JOURS, jamais en mois : un calendrier exprimé en mois ne sait pas dire
 * « six semaines », qui est l'échéance la plus dense du calendrier élargi de vaccination.
 *
 * ═══ UN VACCIN N'EST PAS UNE LIGNE DE `medicaments`, ET LA RAISON EST FONCTIONNELLE ═══
 *
 * La question a été posée, pas supposée. Un vaccin EST un médicament au sens réglementaire, mais ce
 * que le §8 demande est « vaccins disponibles ET calendrier vaccinal national » — et le calendrier
 * porte sur l'ANTIGÈNE (BCG, Pentavalent, Rougeole), jamais sur la présentation commerciale : deux
 * lots de Pentavalent de deux fabricants ne créent pas deux échéances à six semaines. Accrocher le
 * calendrier national à une ligne de `medicaments` ferait changer l'échéance de porteur le jour où
 * le fabricant change.
 *
 * En sens inverse, le produit réellement injecté reste un produit : `vaccinations.numero_lot` existe
 * déjà et ne bouge pas. Si un jour le lot doit désigner un produit commercial, `medicaments` est là
 * et le lien sera additif (ADR-024).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vaccin_compteurs', function (Blueprint $table) {
            $table->string('pays_code', 2)->primary();
            $table->unsignedInteger('dernier')->default(0);
            $table->timestamps();
        });

        Schema::create('vaccins', function (Blueprint $table) {
            $table->id();

            // Identité nationale — HORS `$fillable` : un client ne choisit pas un code national.
            // `VAC` + 6 chiffres, littéral et sans clé de contrôle : cinquième application du même
            // raisonnement après `ETS`, `PRO`, `MED` et `ANA`. Un vaccin est une INSTANCE (ce
            // produit-là), pas un terme de nomenclature — d'où un numéro, à la différence d'une
            // spécialité (P6.8a) ou d'une région (P6.4a), qui portent un code littéral.
            $table->string('code', 12)->nullable();
            $table->string('pays_code', 2)->default('CI');

            // L'ANTIGÈNE, pas la marque : « BCG », « Pentavalent », « Rougeole-Rubéole ».
            $table->string('libelle', 200);

            // La forme abrégée employée dans les carnets papier et par les agents de santé
            // (« Penta 1 », « VAR »). Elle sert à retrouver le vaccin, jamais à l'identifier.
            $table->string('abreviation', 40)->nullable();

            // Ce contre quoi il protège. TEXTE et non table de maladies : la CIM arrive en P6.8c,
            // et un lien vers une table qui n'existe pas encore serait une promesse, pas une donnée.
            $table->text('maladies_evitees')->nullable();

            $table->enum('voie_administration', [
                'intramusculaire', 'sous_cutanee', 'intradermique', 'orale', 'nasale',
            ])->nullable();

            // Le nombre de doses ATTENDUES au schéma complet. Redondant avec le nombre de lignes du
            // calendrier, et la redondance est ASSUMÉE avec son gardien : le contrôle qualité refuse
            // de publier un vaccin dont le calendrier ne compte pas ce nombre d'échéances. Sans
            // cette colonne, on ne pourrait pas distinguer « le schéma a trois doses » de « on n'a
            // saisi que trois des quatre échéances » — donc pas dire qu'un calendrier est incomplet.
            $table->unsignedTinyInteger('nb_doses')->default(1);

            // §6 le fait pour les médicaments et la raison vaut ici : un vaccin retiré est SIGNALÉ,
            // jamais bloqué. Refuser d'inscrire au carnet un vaccin réellement administré serait
            // effacer un fait médical (CDC_00 §4).
            $table->enum('statut_marche', ['disponible', 'rupture', 'retire'])->default('disponible');

            $table->text('description')->nullable();

            // On DÉSACTIVE, on ne supprime pas : des lignes de carnet le référencent.
            $table->boolean('actif')->default(true);

            $table->timestamps();

            // Le pays QUALIFIE le code, il ne s'écrit pas dedans (précédents ETS, PRO, MED, ANA).
            $table->unique(['pays_code', 'code'], 'uq_vaccin_code_pays');
            $table->index('statut_marche');
        });

        Schema::create('calendrier_vaccinal', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vaccin_id')->constrained('vaccins')->cascadeOnDelete();

            $table->unsignedTinyInteger('numero_dose')->default(1);

            // ═══ L'ÂGE DÛ, EN JOURS ═══
            //
            // 0 = à la naissance (BCG, VPO-0). 42 = six semaines. 270 = neuf mois. Aucune de ces
            // échéances ne s'exprime proprement en mois, et deux d'entre elles pas du tout.
            $table->unsignedInteger('age_jours_du');

            // LE DÉLAI DE GRÂCE EST UNE DONNÉE, JAMAIS UN NOMBRE DANS LE CODE (CDC_04 §20).
            // Entre l'âge dû et l'âge dû + tolérance, l'échéance est « à faire » ; au-delà elle est
            // « en retard ». Écrire ce délai dans une classe PHP en ferait une règle de santé
            // publique décidée par un développeur.
            $table->unsignedInteger('tolerance_jours')->default(0);

            // Borne haute de RATTRAPAGE : au-delà, l'échéance n'est plus rattrapable et cesse
            // d'être proposée. NULLABLE parce qu'une borne ouverte est légitime — un rappel adulte
            // n'a pas d'âge limite.
            $table->unsignedInteger('age_jours_limite')->nullable();

            // OBLIGATOIRE EST ICI, PAS SUR LE VACCIN, et pas dans le carnet : c'est un fait de
            // POLITIQUE NATIONALE attaché à une dose précise. Le laisser déclarer par l'utilisateur
            // au formulaire du carnet — ce que faisait le code — revenait à lui faire décrire la
            // politique vaccinale de son pays.
            $table->boolean('obligatoire')->default(false);

            // Ce que le parent lit : « À la naissance », « 6 semaines », « 9 mois ».
            $table->string('libelle_echeance', 120);

            // NON NULLE, et c'est la garde centrale du module — motif repris à l'identique de
            // `analyse_references.source` (P6.7a) : une échéance vaccinale sans provenance est une
            // rumeur, et le contrôle qualité refuse de publier un calendrier qui en contient.
            //
            // Je n'ai vu ni l'arrêté du PEV ivoirien, ni le calendrier officiel publié par le
            // Ministère. Le jeu livré est étiqueté `demonstration` et l'écran en affiche le compte
            // exact : charger le calendrier officiel sera de la DONNÉE, zéro code — et tant que ce
            // n'est pas fait, ce n'est pas un calendrier national.
            $table->enum('source', [
                'demonstration', 'autorite_nationale', 'oms', 'societe_savante', 'publication',
            ]);
            $table->string('source_detail', 200)->nullable();

            $table->timestamps();

            // Une dose d'un vaccin n'a qu'une échéance : deux lignes pour « Penta dose 2 » seraient
            // deux vérités sur la même question.
            $table->unique(['vaccin_id', 'numero_dose'], 'uq_echeance_vaccin_dose');
            $table->index('age_jours_du');
        });

        // ── Le lien du carnet vers le référentiel ─────────────────────────────────────────────────
        //
        // FACULTATIF, pour la raison qui vaut depuis P6.6b : un patient qui recopie un carnet papier
        // n'a pas la liste sous les yeux, et le référentiel est incomplet — l'imposer ferait de ses
        // LACUNES un blocage. Mais ce qui est lié est relu au référentiel et FIGÉ.
        //
        // `nullOnDelete` : un vaccin ne se supprime pas (il se désactive), mais si un jour une ligne
        // du référentiel disparaissait, une vaccination réellement administrée ne doit pas
        // disparaître du carnet avec elle.
        Schema::table('vaccinations', function (Blueprint $table) {
            $table->foreignId('vaccin_id')->nullable()->after('membre_id')
                ->constrained('vaccins')->nullOnDelete();

            // Figés à l'écriture — jamais relus à l'affichage. Une correction ultérieure du
            // référentiel ne doit pas réécrire ce qui a été inscrit au carnet (précédent P6.6b).
            $table->string('vaccin_code', 12)->nullable()->after('vaccin_id');
            $table->unsignedTinyInteger('numero_dose')->nullable()->after('vaccin_nom');
        });

        // ── `statut` DEVIENT NULLABLE, ET C'EST UNE DÉCLARATION ──────────────────────────────────
        //
        // La colonne n'est plus écrite par personne : le statut est CALCULÉ à la lecture
        // ({@see App\Models\Vaccination::statut}). La laisser NOT NULL aurait obligé à y déposer une
        // valeur à chaque insertion — donc à maintenir une seconde vérité, périmée dès le lendemain,
        // que rien ne consulte. C'est précisément le défaut U2 que cet incrément referme.
        //
        // ELLE N'EST PAS SUPPRIMÉE (ADR-024, additif) : les lignes existantes conservent la valeur
        // qu'elles portent. La réécrire, ou la retirer, effacerait ce que le carnet a réellement
        // enregistré — un mensonge d'archive (précédent L2, où les mesures antérieures à la bascule
        // restent sans version plutôt que d'en recevoir une fausse).
        //
        // Aucune requête SQL du projet ne filtre dessus : le seul lecteur, `FicheVitaleService`,
        // passe par la collection Eloquent, donc par l'accesseur. Vérifié avant d'écrire ceci.
        Schema::table('vaccinations', function (Blueprint $table) {
            $table->enum('statut', ['fait', 'a_faire', 'en_retard'])->nullable()->change();
        });

        $this->poserLaGardeDesBornes();
    }

    /**
     * UNE BORNE DE RATTRAPAGE ANTÉRIEURE À L'ÂGE DÛ EST REFUSÉE PAR LE MOTEUR.
     *
     * Le contrôle qualité le signale déjà — mais seulement au moment de publier. Une échéance
     * incohérente pourrait donc vivre des semaines dans le contenu de travail et être présentée à
     * un parent entre-temps, sous la forme « à rattraper avant un âge déjà passé à la date où elle
     * devient due ». *Une garantie qui ne tient qu'au chemin applicatif n'en est pas une* — leçon
     * du G2 de P6.6a, où l'index unique ne protégeait que le couple déjà ordonné.
     *
     * `CHECK` impossible : `vaccin_id` est `cascadeOnDelete`, donc soumise à une action
     * référentielle — **erreur 3823**, le mur de P6.3, cousin du 1215 de P6.1. D'où des triggers
     * dans les deux dialectes (CDC_04 §139). `COALESCE(cond, 1) = 0` et non `NOT(cond)` : une borne
     * NULL est légitime et ne doit rien déclencher — une comparaison NULL passerait sans bruit.
     */
    private function poserLaGardeDesBornes(): void
    {
        $mysql = DB::getDriverName() === 'mysql';
        $condition = 'NEW.age_jours_limite IS NULL OR NEW.age_jours_limite >= NEW.age_jours_du';
        $nom = 'ck_echeance_vaccinale_bornes';

        foreach (['INSERT', 'UPDATE'] as $evenement) {
            $trigger = $nom.'_'.strtolower($evenement);

            DB::unprepared($mysql
                ? "CREATE TRIGGER {$trigger}
                   BEFORE {$evenement} ON calendrier_vaccinal
                   FOR EACH ROW
                   BEGIN
                       IF COALESCE(({$condition}), 1) = 0 THEN
                           SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$nom}';
                       END IF;
                   END"
                : "CREATE TRIGGER {$trigger}
                   BEFORE {$evenement} ON calendrier_vaccinal
                   WHEN COALESCE(({$condition}), 1) = 0
                   BEGIN SELECT RAISE(ABORT, '{$nom}'); END"
            );
        }
    }

    public function down(): void
    {
        foreach (['insert', 'update'] as $evenement) {
            DB::unprepared("DROP TRIGGER IF EXISTS ck_echeance_vaccinale_bornes_{$evenement}");
        }

        Schema::table('vaccinations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('vaccin_id');
            $table->dropColumn(['vaccin_code', 'numero_dose']);
        });

        // Le retour en arrière ne peut pas inventer les statuts qu'on a cessé d'écrire : les lignes
        // créées depuis la bascule porteraient NULL sur une colonne redevenue NOT NULL. On les
        // ramène à `a_faire`, qui est ce que l'ancien code aurait écrit par défaut — et c'est dit
        // ici plutôt que découvert au moment du `migrate:rollback`.
        DB::table('vaccinations')->whereNull('statut')->update(['statut' => 'a_faire']);

        Schema::table('vaccinations', function (Blueprint $table) {
            $table->enum('statut', ['fait', 'a_faire', 'en_retard'])->nullable(false)->change();
        });

        Schema::dropIfExists('calendrier_vaccinal');
        Schema::dropIfExists('vaccins');
        Schema::dropIfExists('vaccin_compteurs');
    }
};
