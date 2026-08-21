<?php

namespace Database\Seeders;

use App\Models\Protocole;
use App\Models\ProtocoleVersion;
use App\Services\Protocole\DiffusionProtocole;
use App\Services\Triage\ServiceNiveauTriage;
use App\Services\Triage\ServicePlafondAntecedents;
use App\Services\Triage\ServiceQuestionnaire;
use App\Support\NiveauTriage;
use App\Support\RegistreActionsProtocole;
use App\Support\RegistreContextesProtocole;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * P10b-1 — Le contenu de démonstration du registre des protocoles (CDC_08 §5, §13 étapes 5 et 8).
 *
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 * DEUX RÉGIMES, ET LA DIFFÉRENCE EST LA DÉCISION G1 N3 DU PROPRIÉTAIRE
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 *
 * **Les protocoles de TRIAGE (§5.4)** sont rédigés en brouillon complet : ils portent les seuils
 * qui vivaient jusqu'ici dans `TriageService::niveauDepuisScore()`. Ce ne sont pas des conduites
 * thérapeutiques mais des règles d'ORIENTATION, et elles existaient déjà — cet incrément ne les
 * invente pas, il les sort du code pour les rendre relisibles et corrigibles sans déploiement.
 *
 * **Les protocoles THÉRAPEUTIQUES (§5.1)** sont rédigés en brouillon **sans aucune validation**.
 * Ils démontrent la structure §4.1/§4.3 et l'écran d'authoring, et le moteur **refuse de les
 * appliquer** — {@see DiffusionProtocole} ne sait pas lire un brouillon.
 * Le §1.6 (« aucun protocole utilisable sans validation ») devient ainsi un **comportement
 * prouvable** au lieu d'une promesse.
 *
 * ═══ AUCUNE ATTRIBUTION N'EST FORGÉE, ET C'EST L'ESSENTIEL ═══
 *
 * Le §4.2 donne un exemple signé « Programme National de Lutte contre le Paludisme ». **Aucun
 * document du PNLP n'a été vu.** On en représente donc la STRUCTURE, jamais l'attribution :
 * `organisme` dit que la source manque, et le protocole reste un brouillon.
 *
 * La raison est plus forte qu'ailleurs dans ce projet. Publier un protocole thérapeutique
 * exigerait de seeder ses quatre validations (§7), donc d'inscrire **dans une chaîne d'audit
 * immuable** qu'un médecin spécialiste et le Ministère ont validé une posologie. Le §7 dit
 * « opposable » : c'est la pièce qu'on produirait devant un tribunal. Partout ailleurs un jeu de
 * démonstration fabrique une donnée fausse ; ici il fabriquerait **une validation clinique
 * fausse**.
 *
 * ═══ LE SEEDER NE PUBLIE RIEN ═══
 *
 * Il ouvre des brouillons, point. Publier depuis un seeder contournerait le quatre-yeux du §10 dès
 * le premier jour — précédent `ReferentielRegistreSeeder` (P6.3) et de la mise en vigueur de la v1
 * en L1+L2 : **la publication est une étape de déploiement, faite par deux agents habilités.**
 */
class ProtocoleSeeder extends Seeder
{
    /**
     * Le libellé d'organisme employé tant qu'aucun document source n'a été fourni.
     *
     * Il est écrit une fois : trois protocoles le portent, et *une phrase recopiée trois fois finit
     * par diverger deux fois*. Surtout, il ne doit jamais dériver vers un nom d'autorité — c'est la
     * ligne que ce seeder ne franchit pas.
     */
    private const SOURCE_ABSENTE = 'Source non fournie — aucun document d\'autorité consulté';

    public function run(): void
    {
        $this->protocoleDeNiveau();
        $this->protocoleQuestionnaire();
        $this->protocoleAntecedents();
        $this->protocoleRegional();
        $this->brouillonsTherapeutiques();
    }

    /**
     * P10b-3-i — `TRIAGE-QUESTIONNAIRE` : les questions et leur impact (§4.3b, §13 étape 4).
     *
     * ═══ CE QUE CE PROTOCOLE CONTIENT, ET D'OÙ ÇA VIENT ═══
     *
     * Les huit questions et leurs impacts sont **transcrits** de
     * `symptomes.questions_complementaires_json`, où ils vivaient depuis le Module 1. Même régime
     * qu'en P10b-1 pour les seuils de niveau : on ne les invente pas, on les sort d'un endroit où
     * ils échappaient aux quatre validations du §7.
     *
     * ═══ DEUX ÉCARTS À LA TRANSCRIPTION, ANNONCÉS PLUTÔT QUE DÉCOUVERTS ═══
     *
     * **1. Le coefficient linéaire de l'échelle devient des bandes.** L'ancien impact était
     * `round(valeur × 1,2)` — une formule. Un moteur à liste blanche fermée n'en exprime pas, et
     * lui ajouter une action « multiplier » ouvrirait dans la donnée une arithmétique que personne
     * ne relirait. Trois bandes le remplacent, ce qui décale certains scores d'un ou deux points.
     * C'est un écart réel ; il est ici plutôt qu'ignoré. En contrepartie « douleur forte → +11 »
     * se relit et se signe, ce qu'un coefficient ne permettait pas.
     *
     * **2. Les conditions de déclenchement sont NEUVES.** Il n'en existait aucune : l'écran posait
     * toutes les questions de tous les symptômes cochés. Elles sont donc du contenu de
     * démonstration au même titre que les bandes de niveau — `niveau_preuve = 'D'`, source non
     * fournie, aucun validateur forgé.
     *
     * ═══ POURQUOI `symptome_id` ET NON `symptome_categorie` ═══
     *
     * Parce que c'est la transcription EXACTE : la question appartenait à un symptôme précis, pas
     * à sa famille. Conditionner sur la catégorie demanderait « depuis combien de jours ? » à qui
     * déclare une perte de connaissance, parce qu'elle est rangée en « neurologique » avec les
     * maux de tête.
     *
     * Le prix est réel et il est dit : un identifiant technique ne veut rien dire hors de cette
     * base — c'est ce que P10a reprochait à `specialite_id` avant de porter les orientations par
     * code. Les symptômes n'ont pas de code national ; tant qu'ils n'en auront pas, un protocole
     * qui les désigne est lié à cette installation. C'est une limite du G5, pas une élégance.
     */
    /**
     * P10b-3-ii — LA PART DES ANTÉCÉDENTS, transcrite depuis `TriageService::PLAFOND_ANTECEDENTS`.
     *
     * Comme le protocole de niveau en b-1, celui-ci ne fait que **transcrire** un seuil qui était
     * déjà codé : 20. Aucune valeur n'est inventée, aucune autorité n'est nommée, et le niveau de
     * preuve reste « D ». Ce qui change n'est pas le chiffre, c'est qu'il devient relisible,
     * corrigible sans déploiement, et signé par quatre validateurs (§7).
     */
    private function protocoleAntecedents(): void
    {
        $protocole = $this->enregistrer(ServicePlafondAntecedents::CODE, [
            'titre' => 'Part des antécédents dans le score de triage',
            'domaine' => Protocole::DOMAINE_TRIAGE,
            'niveau_source' => 'national',
            'contextes_json' => [RegistreContextesProtocole::TRIAGE_ANTECEDENTS],
            'organisme' => self::SOURCE_ABSENTE,
            'langue' => 'fr',
            'mots_cles_json' => ['triage', 'antecedents', 'score'],
        ]);

        if ($protocole->versions()->exists()) {
            return; // Idempotent.
        }

        $version = ProtocoleVersion::create([
            'protocole_id' => $protocole->id,
            'numero' => 1,
            'libelle' => '2026.1',
            'etat' => ProtocoleVersion::BROUILLON,
            'verrou_unicite' => ProtocoleVersion::verrouPour(ProtocoleVersion::BROUILLON, $protocole->id),
            'niveau_preuve' => 'D',
            'population' => 'Tous publics — triage déclaratif avec carnet renseigné',
            'conditions_utilisation' => 'Borne la part du score venant des antécédents DÉCLARÉS par '
                ."le patient lui-même. Cette déclaration n'est vérifiée par personne : la borne est "
                .'précisément la réponse à cette absence de vérification. Elle ne pose aucun '
                .'diagnostic (CDC_05 §1).',
            'motif' => "Sortie du seuil PLAFOND_ANTECEDENTS = 20, jusqu'ici codé dans TriageService "
                .'(CDC_08 §1.2).',
            'redige_le' => Carbon::now(),
        ]);

        // UNE SEULE RÈGLE, ET SANS CONDITION — le moteur le prévoit explicitement (« une règle
        // sans condition s'applique toujours »). Deux règles disjointes auraient exigé d'exprimer
        // « sinon, garder la somme telle quelle », valeur dynamique qu'aucune action à valeur
        // littérale ne sait dire.
        $this->regle($version, 1, 'La part des antécédents déclarés ne dépasse pas vingt points',
            [],
            [[RegistreActionsProtocole::BORNER_SCORE_ANTECEDENTS, 20,
                "Un patient qui déclare beaucoup d'antécédents ne doit pas pouvoir porter seul son "
                ."score à l'urgence : cette déclaration n'est vérifiée par personne."]]);

        $version->references()->create([
            'type' => 'recommandation',
            'libelle' => 'CDC_08 §1.2 — aucune règle médicale en dur',
            'citation' => 'Corpus MaSanté, cahier des charges n°8 section 1.2.',
        ]);

        $version->references()->create([
            'type' => 'document',
            'libelle' => 'Seuil transcrit du Module 1 — aucune validation clinique',
            'citation' => 'Valeur reprise de TriageService::PLAFOND_ANTECEDENTS = 20. À remplacer '
                ."par une décision d'autorité sanitaire lorsqu'elle sera fournie.",
        ]);
    }

    private function protocoleQuestionnaire(): void
    {
        $protocole = $this->enregistrer(ServiceQuestionnaire::CODE, [
            'titre' => 'Questionnaire adaptatif de triage',
            'domaine' => Protocole::DOMAINE_TRIAGE,
            'niveau_source' => 'national',
            'contextes_json' => [RegistreContextesProtocole::TRIAGE_QUESTIONNAIRE],
            'organisme' => self::SOURCE_ABSENTE,
            'langue' => 'fr',
            'mots_cles_json' => ['triage', 'questionnaire', 'interrogatoire'],
        ]);

        if ($protocole->versions()->exists()) {
            return; // Idempotent : re-seeder ne rouvre pas un brouillon ni n'en crée un second.
        }

        $version = ProtocoleVersion::create([
            'protocole_id' => $protocole->id,
            'numero' => 1,
            'libelle' => '2026.1',
            'etat' => ProtocoleVersion::BROUILLON,
            'verrou_unicite' => ProtocoleVersion::verrouPour(ProtocoleVersion::BROUILLON, $protocole->id),
            'niveau_preuve' => 'D',
            'population' => 'Tous publics — adultes et enfants, triage déclaratif',
            'conditions_utilisation' => 'Interrogatoire d\'orientation uniquement. Ces questions ne '
                .'posent aucun diagnostic et ne remplacent pas l\'examen d\'un professionnel de '
                .'santé (CDC_05 §1). Les impacts sont transcrits du Module 1 ; ils n\'ont été '
                .'confrontés à aucun protocole national, et les conditions de déclenchement sont '
                .'neuves.',
            'motif' => 'Mise en forme protocolaire du questionnaire, jusqu\'ici décrit en '
                .'JSON dans le référentiel des symptômes et calculé dans TriageService '
                .'(CDC_08 §1.2, §4.3b).',
            'redige_le' => Carbon::now(),
        ]);

        $this->questions($version);
        $this->reglesDePose($version);
        $this->reglesDImpact($version);

        // Le §4.1 exige une source, et le contrôle qualité refuse de publier sans elle. On cite ce
        // qu'on a réellement : le corpus pour l'exigence, et le Module 1 pour les impacts. Aucune
        // autorité sanitaire n'est nommée — c'est la ligne que ce seeder ne franchit pas.
        $version->references()->create([
            'type' => 'recommandation',
            'libelle' => 'CDC_08 §4.3b — Questionnaire arborescent ; CDC_05 §5.5.2 — questionnaire adaptatif',
            'citation' => 'Corpus MaSanté, cahiers des charges n°8 section 4.3b et n°5 section 5.5.2.',
        ]);

        $version->references()->create([
            'type' => 'document',
            'libelle' => 'Impacts transcrits du Module 1 — aucune validation clinique',
            'citation' => 'Questions et impacts repris de symptomes.questions_complementaires_json, '
                .'à ceci près que le coefficient linéaire de l\'échelle d\'intensité est remplacé '
                .'par trois bandes. Les conditions de déclenchement sont neuves. À remplacer par '
                .'un questionnaire national lorsqu\'il sera fourni.',
        ]);
    }

    /**
     * `TRIAGE-NIVEAU` — les règles qui décident du niveau de priorité (§5.4, CDC_05 §5.3).
     *
     * ═══ CE QUE CE PROTOCOLE CONTIENT, ET D'OÙ ÇA VIENT ═══
     *
     * Les bandes remplacent le `match` de `TriageService`, qui portait 0-30 / 31-65 / 66-100 pour
     * TROIS niveaux. Le corpus en exige QUATRE côté patient (CDC_05 §5.3) : les bandes sont donc
     * redécoupées en quarts.
     *
     * **Ces seuils sont une base de démonstration, et ils l'étaient déjà.** Ceux du Module 1
     * n'avaient été confrontés à aucun protocole national ; ils étaient simplement invisibles,
     * enfouis dans une méthode privée. Le gain de cet incrément n'est pas qu'ils deviennent justes
     * — c'est qu'ils deviennent **relisibles, signés et corrigibles sans déploiement**. D'où
     * `niveau_preuve = 'D'` : le plus bas, et c'est la vérité.
     */
    private function protocoleDeNiveau(): void
    {
        $protocole = $this->enregistrer(ServiceNiveauTriage::CODE, [
            'titre' => 'Détermination du niveau de priorité du triage',
            'domaine' => Protocole::DOMAINE_TRIAGE,
            'niveau_source' => 'national',
            'contextes_json' => [RegistreContextesProtocole::TRIAGE],
            'organisme' => self::SOURCE_ABSENTE,
            'langue' => 'fr',
            'mots_cles_json' => ['triage', 'priorite', 'orientation'],
        ]);

        if ($protocole->versions()->exists()) {
            return; // Idempotent : re-seeder ne rouvre pas un brouillon ni n'en crée un second.
        }

        $version = ProtocoleVersion::create([
            'protocole_id' => $protocole->id,
            'numero' => 1,
            'libelle' => '2026.1',
            'etat' => ProtocoleVersion::BROUILLON,
            'verrou_unicite' => ProtocoleVersion::verrouPour(ProtocoleVersion::BROUILLON, $protocole->id),
            'niveau_preuve' => 'D',
            'population' => 'Tous publics — adultes et enfants, triage déclaratif',
            'conditions_utilisation' => 'Orientation uniquement. Ce protocole ne pose aucun '
                .'diagnostic et ne remplace pas l\'examen d\'un professionnel de santé '
                .'(CDC_05 §1). Les bandes de score sont une base de démonstration reprise du '
                .'Module 1 ; elles n\'ont été confrontées à aucun protocole national.',
            'motif' => 'Première mise en forme protocolaire des règles de niveau, '
                .'jusqu\'ici codées dans TriageService (CDC_08 §1.2).',
            'redige_le' => Carbon::now(),
        ]);

        // ═══ ORDRE 1 — LE DRAPEAU ROUGE, QUI ÉTAIT `max($score, 90)` DANS LE CODE ═══
        //
        // Il ne fixe PAS le niveau : il relève le score, et le chaînage avant fait que les bandes
        // suivantes voient la valeur relevée. C'est ce qui permet à la priorité d'être une donnée
        // — l'ORDRE de la règle — plutôt qu'une exception codée avant le calcul.
        $this->regle($version, 1, 'Un signe critique relève le score au niveau d\'urgence', [
            ['drapeau_rouge', '=', true],
        ], [
            [RegistreActionsProtocole::DEFINIR_SCORE_MINIMUM, 90,
                'Un symptôme ou une réponse critique impose une prise en charge urgente quel que '
                .'soit le total des autres points.'],
        ]);

        $bandes = [
            [2, 0, 25, NiveauTriage::FAIBLE,
                'Faible priorité',
                'Vos symptômes semblent bénins. Surveillance à domicile et conseils généraux ; '
                .'un pharmacien peut vous orienter. En cas d\'aggravation, refaites un triage.'],
            [3, 26, 50, NiveauTriage::RECOMMANDEE,
                'Consultation recommandée',
                'Une consultation est recommandée : prenez rendez-vous avec un médecin '
                .'généraliste ou un spécialiste.'],
            [4, 51, 75, NiveauTriage::RAPIDE,
                'Consultation rapide',
                'Consultez dans les 24 heures : rendez-vous sans tarder chez un médecin, dans un '
                .'Centre de Santé Urbain (CSU) ou une clinique.'],
            // ═══ LE NUMÉRO N'EST PAS ÉCRIT ICI ═══
            //
            // `{urgence:samu}` est résolu au référentiel national (P6.8e, CDC_02 §37). Écrire
            // « 185 » dans cette ligne aurait déplacé le problème d'un fichier PHP vers une ligne
            // de base — et l'aurait aggravé : corriger le numéro exigerait alors de refaire passer
            // le protocole par les quatre validations du §7, pour un changement sans rien de
            // clinique.
            [5, 76, 100, NiveauTriage::URGENCE,
                'Urgence',
                'Rendez-vous immédiatement au service des urgences d\'un CHU/CHR, ou appelez le '
                .'SAMU au {urgence:samu} (numéro vert, Côte d\'Ivoire).'],
        ];

        foreach ($bandes as [$ordre, $min, $max, $niveau, $titre, $message]) {
            $this->regle($version, $ordre, "Score de {$min} à {$max} : {$titre}", [
                ['score', 'entre', [$min, $max]],
            ], [
                [RegistreActionsProtocole::DEFINIR_NIVEAU, $niveau, null],
                // Le contrôle qualité EXIGE ce message : un niveau sans consigne laisse le citoyen
                // devant une couleur et un mot, alors que CDC_05 §5.3 associe une conduite à
                // chacun des quatre niveaux.
                [RegistreActionsProtocole::MESSAGE, $message, null],
            ]);
        }

        $version->references()->create([
            'type' => 'recommandation',
            'libelle' => 'CDC_05 §5.3 — Niveaux de priorité côté patient (4 niveaux)',
            'citation' => 'Corpus MaSanté, cahier des charges n°5, section 5.3.',
        ]);

        $version->references()->create([
            'type' => 'document',
            'libelle' => 'Base de démonstration héritée du Module 1 — aucune validation clinique',
            'citation' => 'Seuils repris de TriageService::niveauDepuisScore(), redécoupés en '
                .'quatre bandes. À remplacer par le protocole national de triage lorsqu\'il sera '
                .'fourni.',
        ]);
    }

    /**
     * `TRIAGE-NIVEAU-REGIONAL` — P10b-2 : le second protocole, sans lequel le sélecteur et la
     * cascade §3/§8 ne seraient exercés par rien (décision G1 O1).
     *
     * ═══════════════════════════════════════════════════════════════════════════════════════════
     * POURQUOI CE CONTENU EXISTE, ET EN QUOI IL DIFFÈRE DE LA DÉCISION N3
     * ═══════════════════════════════════════════════════════════════════════════════════════════
     *
     * `TRIAGE-NIVEAU` **transcrivait** des seuils qui existaient déjà dans le code. Celui-ci est du
     * contenu **inventé** — il faut le dire, parce que c'est une différence réelle.
     *
     * Ce qui le rend acceptable, et qui manquait à un protocole thérapeutique :
     *   - ce n'est **pas une posologie**. Le §7 dit « opposable » : la pièce qu'on produirait
     *     devant un tribunal pour une dose de médicament n'est pas de même nature qu'une règle
     *     d'orientation ;
     *   - il porte les **mêmes étiquettes d'honnêteté** que `TRIAGE-NIVEAU` : `niveau_preuve = 'D'`,
     *     organisme « source non fournie », auteur absent ;
     *   - c'est le régime de **tous** les référentiels de P6 (18 médicaments, 21 maladies,
     *     9 vaccins, 8 analyses) : contenu de démonstration, étiqueté comme tel, remplaçable sans
     *     migration.
     *
     * Et sans lui, le sélecteur rendrait toujours le même protocole et le résolveur de conflits ne
     * se déclencherait jamais : « un contrôle toujours vert ne prouve rien » (P5.3b-4), et une
     * table de conflits vide serait le socle à vide refusé par la décision D3 de P6.3.
     *
     * ═══ CE QU'IL DÉMONTRE, EN DEUX ACTIONS DE NATURES OPPOSÉES ═══
     *
     * Sur le même cas — un enfant de moins de cinq ans dont le score tombe dans la bande médiane —
     * il émet :
     *   - un `DEFINIR_NIVEAU` **divergent** du national : action EXCLUSIVE, donc départagée. Le
     *     national gagne par le RANG (§3-1), la divergence est consignée, et le régional
     *     n'obtient rien ;
     *   - un `ORIENTER` vers la pédiatrie : action CUMULATIVE, donc **conservée**. Elle s'ajoute
     *     à ce que le national a produit.
     *
     * *C'est cette asymétrie qui rend le §8 vérifiable : un protocole écarté sur un point ne l'est
     * pas sur les autres.* Un protocole entièrement ignoré dès qu'il perd une fois ferait du §3 un
     * ordre d'exclusion, alors qu'il est un ordre de départage.
     *
     * ═══ CE PROTOCOLE N'EST PAS PUBLIÉ ICI ═══
     *
     * Comme les autres : le seeder ouvre un brouillon, la publication reste une étape de
     * déploiement faite par deux agents habilités (§10). Il ne s'appliquera donc que le jour où
     * quelqu'un décidera qu'il s'applique.
     */
    private function protocoleRegional(): void
    {
        $protocole = $this->enregistrer('TRIAGE-NIVEAU-REGIONAL', [
            'titre' => 'Adaptation régionale du triage — priorité de l\'enfant de moins de 5 ans',
            'domaine' => Protocole::DOMAINE_TRIAGE,
            // §3 rang 2 : « protocoles ministériels régionaux ». C'est ce rang qui le fera perdre
            // face au national — et qui rend sa publication possible, puisque le contrôle de
            // conflits refuse une version que seule la DATE départagerait.
            'niveau_source' => 'regional',
            'contextes_json' => [RegistreContextesProtocole::TRIAGE],
            'organisme' => self::SOURCE_ABSENTE,
            'langue' => 'fr',
            'mots_cles_json' => ['triage', 'pediatrie', 'adaptation regionale'],
        ]);

        if ($protocole->versions()->exists()) {
            return; // Idempotent, comme les autres.
        }

        $version = ProtocoleVersion::create([
            'protocole_id' => $protocole->id,
            'numero' => 1,
            'libelle' => '2026.1',
            'etat' => ProtocoleVersion::BROUILLON,
            'verrou_unicite' => ProtocoleVersion::verrouPour(ProtocoleVersion::BROUILLON, $protocole->id),
            'niveau_preuve' => 'D',
            'population' => 'Enfants de moins de 5 ans',
            'conditions_utilisation' => 'Orientation uniquement, aucun diagnostic (CDC_05 §1). '
                .'CONTENU DE DÉMONSTRATION : cette adaptation régionale n\'a été fournie par '
                .'aucune direction régionale de la santé et n\'a été confrontée à aucun protocole '
                .'national. Elle existe pour exercer la sélection et la résolution de conflits '
                .'(CDC_08 §3, §8).',
            'motif' => 'Second protocole de triage — sans lui, l\'ordre de priorité du §3 '
                .'et la résolution de conflits du §8 ne seraient exercés par aucun contenu réel.',
            'redige_le' => Carbon::now(),
        ]);

        $this->regle(
            $version,
            1,
            'Enfant de moins de 5 ans en bande médiane : consultation rapide et orientation pédiatrique',
            [
                ['age', '<', 5],
                ['score', 'entre', [26, 50]],
            ],
            [
                // EXCLUSIVE — diverge du national, qui rend « recommandee » sur cette bande.
                // Elle sera écartée par le rang, et la divergence consignée.
                [RegistreActionsProtocole::DEFINIR_NIVEAU, NiveauTriage::RAPIDE,
                    'Contenu de démonstration : aucune source d\'autorité.'],
                // Le contrôle de b-1 l'exige toujours : un niveau sans consigne laisse le
                // citoyen devant un mot et une couleur.
                [RegistreActionsProtocole::MESSAGE,
                    'Consultez un médecin dans les 24 heures ; un service de pédiatrie est '
                    .'à privilégier pour un enfant de cet âge.', null],
                // CUMULATIVE — conservée malgré la perte sur l'action ci-dessus.
                [RegistreActionsProtocole::ORIENTER, 'pediatrie',
                    'Un enfant de moins de 5 ans relève d\'une consultation pédiatrique.'],
            ]
        );

        $version->references()->create([
            'type' => 'document',
            'libelle' => 'Contenu de démonstration — aucune direction régionale consultée',
            'citation' => 'Rédigé pour exercer le sélecteur et la cascade §3/§8 de CDC_08. '
                .'À remplacer par une adaptation régionale réelle lorsqu\'elle sera fournie.',
        ]);
    }

    /**
     * Les protocoles thérapeutiques du §5.1 — BROUILLONS, sans validation, jamais applicables.
     *
     * Ils existent pour trois raisons, et aucune n'est de soigner :
     *   - démontrer que la structure §4.1/§4.3 représente un vrai protocole clinique ;
     *   - donner à l'écran d'authoring quelque chose à montrer ;
     *   - **prouver le §1.6** — un vecteur vérifie qu'aucun d'eux n'est appliqué par le moteur.
     */
    private function brouillonsTherapeutiques(): void
    {
        // L'exemple imposé du §4.2. Sa STRUCTURE est reproduite fidèlement ; son ATTRIBUTION ne
        // l'est pas, et c'est délibéré — voir l'en-tête de la classe.
        $palu = $this->enregistrer('PROT-CI-PALU-SIMPLE', [
            'titre' => 'Paludisme simple — prise en charge de l\'adulte',
            'domaine' => 'infectieux',
            'niveau_source' => 'national',
            'contextes_json' => [RegistreContextesProtocole::CONSULTATION, RegistreContextesProtocole::URGENCE],
            'organisme' => self::SOURCE_ABSENTE,
            'langue' => 'fr',
            'mots_cles_json' => ['paludisme', 'TDR', 'ACT'],
        ]);

        if (! $palu->versions()->exists()) {
            $version = ProtocoleVersion::create([
                'protocole_id' => $palu->id,
                'numero' => 1,
                'libelle' => '2026.2',
                'etat' => ProtocoleVersion::BROUILLON,
                'verrou_unicite' => ProtocoleVersion::verrouPour(ProtocoleVersion::BROUILLON, $palu->id),
                'population' => 'Adultes',
                'conditions_utilisation' => 'BROUILLON NON VALIDÉ — ne doit être appliqué à aucun '
                    .'patient. Le document source du programme national n\'a pas été fourni ; '
                    .'les conduites ci-dessous reproduisent la forme de l\'exemple du CDC_08 §4.2 '
                    .'et non une recommandation thérapeutique vérifiée.',
                'motif' => 'Démonstration de la structure §4.1/§4.3 — en attente du '
                    .'document source et des quatre validations du §7.',
                'redige_le' => Carbon::now(),
            ]);

            $this->regle($version, 1, 'Fièvre avec TDR positif et sans signe de gravité', [
                ['symptome_categorie', 'contient', 'fievre'],
                ['drapeau_rouge', '=', false],
            ], [
                [RegistreActionsProtocole::TRAITEMENT, 'Combinaison thérapeutique à base '
                    .'d\'artémisinine (ACT) — posologie à renseigner depuis le document source',
                    'Exemple du §4.2. La molécule, la dose et la durée ne sont pas renseignées : '
                    .'les inventer serait une prescription.'],
                [RegistreActionsProtocole::SURVEILLANCE, 'Contrôle à J3', null],
            ]);

            $version->references()->create([
                'type' => 'document',
                'libelle' => 'CDC_08 §4.2 — exemple imposé (format, non recommandation)',
                'citation' => 'Aucun document du Programme National de Lutte contre le Paludisme '
                    .'n\'a été consulté.',
            ]);
        }

        // Un second brouillon, d'un autre domaine, pour montrer que la structure ne dépend pas du
        // sujet — §4.3a en donne l'exemple (« Âge > 60 ans ET Diabète ET HTA »).
        $hta = $this->enregistrer('PROT-CI-HTA-SUIVI', [
            'titre' => 'Hypertension artérielle — suivi du patient à risque',
            'domaine' => 'chronique',
            'niveau_source' => 'national',
            'contextes_json' => [RegistreContextesProtocole::CONSULTATION],
            'organisme' => self::SOURCE_ABSENTE,
            'langue' => 'fr',
            'mots_cles_json' => ['HTA', 'cardiovasculaire', 'suivi'],
        ]);

        if ($hta->versions()->exists()) {
            return;
        }

        $version = ProtocoleVersion::create([
            'protocole_id' => $hta->id,
            'numero' => 1,
            'libelle' => '2026.1',
            'etat' => ProtocoleVersion::BROUILLON,
            'verrou_unicite' => ProtocoleVersion::verrouPour(ProtocoleVersion::BROUILLON, $hta->id),
            'population' => 'Adultes de plus de 60 ans',
            'conditions_utilisation' => 'BROUILLON NON VALIDÉ — ne doit être appliqué à aucun '
                .'patient. Aucun document d\'autorité n\'a été consulté.',
            'motif' => 'Démonstration de la structure §4.3a — en attente du document '
                .'source et des quatre validations du §7.',
            'redige_le' => Carbon::now(),
        ]);

        $this->regle($version, 1, 'Patient de plus de 60 ans présentant un signe cardiaque', [
            ['age', '>', 60],
            ['symptome_categorie', 'contient', 'cardiaque'],
        ], [
            [RegistreActionsProtocole::EXAMEN, 'Électrocardiogramme', null],
            [RegistreActionsProtocole::EXAMEN, 'Bilan biologique', null],
        ]);

        $version->references()->create([
            'type' => 'document',
            'libelle' => 'CDC_08 §4.3a — exemple de règle déclarative (format, non recommandation)',
            'citation' => 'Aucun document d\'autorité n\'a été consulté.',
        ]);
    }

    /**
     * P10b-3-i — Les huit questions, transcrites telles quelles du référentiel des symptômes.
     *
     * Les énoncés ne sont pas retouchés : ce sont ceux que les patients lisent depuis le Module 1,
     * et les réécrire ferait passer une modification rédactionnelle pour une transcription.
     */
    private function questions(ProtocoleVersion $version): void
    {
        $questions = [
            ['duree_jours', 'Depuis combien de jours ?', 'nombre', 'jours', null, null, []],
            ['intensite', 'Intensité de 1 à 10 ?', 'echelle', null, 1, 10, []],
            ['fievre_sup_40', 'Température supérieure à 40°C ?', 'booleen', null, null, null, []],
            ['type_toux', 'Type de toux ?', 'choix', null, null, null, [
                ['seche', 'Sèche'],
                ['grasse', 'Grasse'],
            ]],
            ['au_repos', 'Gêne respiratoire même au repos ?', 'booleen', null, null, null, []],
            ['selles_eau_de_riz', 'Selles liquides « eau de riz » très fréquentes ?', 'booleen', null, null, null, []],
            ['photophobie', 'Gêne importante à la lumière ?', 'booleen', null, null, null, []],
            ['deformation_visible', 'Déformation visible ou impossibilité de bouger ?', 'booleen', null, null, null, []],
        ];

        foreach ($questions as $ordre => [$cle, $libelle, $type, $unite, $min, $max, $reponses]) {
            $question = $version->questions()->create([
                'cle' => $cle,
                'libelle' => $libelle,
                'type' => $type,
                'unite' => $unite,
                'valeur_min' => $min,
                'valeur_max' => $max,
                'ordre' => $ordre + 1,
            ]);

            foreach ($reponses as $i => [$valeur, $etiquette]) {
                $question->reponses()->create([
                    'valeur' => $valeur,
                    'libelle' => $etiquette,
                    'ordre' => $i + 1,
                ]);
            }
        }
    }

    /**
     * P10b-3-i — QUAND poser chaque question (§4.3b).
     *
     * ═══ CE QUI CHANGE POUR LE PATIENT ═══
     *
     * Avant, l'écran posait toutes les questions de tous les symptômes cochés. Désormais une
     * question n'apparaît que si une règle la déclenche — c'est tout l'objet du §4.3b et de
     * l'« éviter 100 questions inutiles » de CDC_05 §5.5.2.
     *
     * La dernière règle est la seule à se déclencher sur une RÉPONSE et non sur un symptôme :
     * c'est elle qui fait du questionnaire un arbre plutôt qu'une liste conditionnelle, et elle
     * exerce le chaînage que le §4.3b décrit (`Fièvre → Durée ? → …`).
     */
    private function reglesDePose(ProtocoleVersion $version): void
    {
        // [id du symptôme, son nom (pour le libellé lisible du §7), clés des questions à poser]
        $paires = [
            [1, 'une fièvre élevée', ['duree_jours', 'fievre_sup_40']],
            [2, 'des frissons', ['duree_jours']],
            [3, 'des courbatures', ['duree_jours', 'intensite']],
            [4, 'des maux de tête', ['duree_jours', 'intensite']],
            [5, 'une toux', ['duree_jours', 'type_toux']],
            [6, 'une difficulté respiratoire', ['au_repos']],
            [7, 'une douleur thoracique', ['intensite']],
            [8, 'une diarrhée', ['duree_jours', 'selles_eau_de_riz']],
            [9, 'des vomissements', ['duree_jours']],
            [10, 'une douleur abdominale', ['duree_jours', 'intensite']],
            [13, 'une raideur de la nuque', ['photophobie']],
            [14, 'une douleur dentaire', ['intensite']],
            [15, 'une douleur auriculaire', ['duree_jours', 'intensite']],
            [16, 'une douleur oculaire', ['duree_jours']],
            [17, 'une éruption cutanée', ['duree_jours']],
            [19, 'des douleurs pelviennes', ['duree_jours', 'intensite']],
            [20, 'un traumatisme possible', ['deformation_visible']],
        ];

        $ordre = 1;

        foreach ($paires as [$symptomeId, $nom, $cles]) {
            $this->regle(
                $version,
                $ordre++,
                'Si le patient déclare '.$nom.', préciser l\'interrogatoire',
                [['symptome_id', 'contient', $symptomeId]],
                array_map(
                    fn (string $cle): array => [RegistreActionsProtocole::POSER_QUESTION, $cle, null],
                    $cles,
                ),
            );
        }

        // ═══ LA SEULE RÈGLE CHAÎNÉE SUR UNE RÉPONSE — C'EST ELLE QUI FAIT L'ARBRE ═══
        //
        // Une gêne respiratoire au repos est un signe de gravité : on demande alors l'intensité,
        // question qui n'aurait pas été posée pour ce symptôme. Sans au moins une règle de cette
        // forme, le questionnaire resterait une liste conditionnelle et le §4.3b ne serait pas
        // exercé — *un contrôle toujours vert ne prouve rien* (leçon P5.3b-4).
        $this->regle(
            $version,
            $ordre,
            'Si la gêne respiratoire persiste au repos, évaluer son intensité',
            [['reponse.au_repos', '=', true]],
            [[RegistreActionsProtocole::POSER_QUESTION, 'intensite', null]],
        );
    }

    /**
     * P10b-3-i — L'IMPACT de chaque réponse sur le score (§4.3a).
     *
     * ═══ CE QUI ÉTAIT UN BLOB JSON DEVIENT UNE RÈGLE RELUE ET SIGNÉE ═══
     *
     * `['points_si_vrai' => 15, 'drapeau_rouge_si_vrai' => true]` se lit désormais « si la
     * température dépasse 40 °C, ajouter 15 points ET relever le score à 90 ». Un médecin
     * spécialiste peut valider cela au sens du §7 ; il ne pouvait pas valider le JSON.
     *
     * `drapeau_rouge_si_vrai` disparaît comme mécanisme : il est remplacé par
     * `DEFINIR_SCORE_MINIMUM`, l'action que P10b-1 a créée pour le drapeau rouge des symptômes.
     * Une seule façon d'écrire « ceci prime », au lieu de deux qui pouvaient diverger.
     *
     * Les trois bandes d'intensité remplacent `round(valeur × 1,2)` — écart annoncé en tête de
     * `protocoleQuestionnaire()`.
     */
    private function reglesDImpact(ProtocoleVersion $version): void
    {
        $ordre = 100;

        $this->regle($version, $ordre++, 'Des symptômes qui durent plus de trois jours pèsent davantage',
            [['reponse.duree_jours', '>', 3]],
            [[RegistreActionsProtocole::AJOUTER_SCORE, 8, null]]);

        $this->regle($version, $ordre++, 'Douleur légère (1 à 3 sur 10)',
            [['reponse.intensite', 'entre', [1, 3]]],
            [[RegistreActionsProtocole::AJOUTER_SCORE, 3, null]]);

        $this->regle($version, $ordre++, 'Douleur modérée (4 à 7 sur 10)',
            [['reponse.intensite', 'entre', [4, 7]]],
            [[RegistreActionsProtocole::AJOUTER_SCORE, 7, null]]);

        $this->regle($version, $ordre++, 'Douleur forte (8 à 10 sur 10)',
            [['reponse.intensite', 'entre', [8, 10]]],
            [[RegistreActionsProtocole::AJOUTER_SCORE, 11, null]]);

        $this->regle($version, $ordre++, 'Une température supérieure à 40 °C est un signe de gravité',
            [['reponse.fievre_sup_40', '=', true]],
            [
                [RegistreActionsProtocole::AJOUTER_SCORE, 15, null],
                [RegistreActionsProtocole::DEFINIR_SCORE_MINIMUM, 90,
                    'Une hyperthermie majeure impose une prise en charge urgente quel que soit le '
                    .'total des autres points.'],
            ]);

        $this->regle($version, $ordre++, 'Toux sèche',
            [['reponse.type_toux', '=', 'seche']],
            [[RegistreActionsProtocole::AJOUTER_SCORE, 3, null]]);

        $this->regle($version, $ordre++, 'Toux grasse',
            [['reponse.type_toux', '=', 'grasse']],
            [[RegistreActionsProtocole::AJOUTER_SCORE, 5, null]]);

        $this->regle($version, $ordre++, 'Une gêne respiratoire au repos est un signe de gravité',
            [['reponse.au_repos', '=', true]],
            [
                [RegistreActionsProtocole::AJOUTER_SCORE, 10, null],
                [RegistreActionsProtocole::DEFINIR_SCORE_MINIMUM, 90,
                    'Une dyspnée de repos impose une prise en charge urgente.'],
            ]);

        $this->regle($version, $ordre++, 'Des selles « eau de riz » évoquent une déshydratation rapide',
            [['reponse.selles_eau_de_riz', '=', true]],
            [[RegistreActionsProtocole::AJOUTER_SCORE, 15, null]]);

        $this->regle($version, $ordre++, 'Une photophobie associée à une raideur de nuque pèse davantage',
            [['reponse.photophobie', '=', true]],
            [[RegistreActionsProtocole::AJOUTER_SCORE, 12, null]]);

        $this->regle($version, $ordre, 'Une déformation visible évoque une lésion à prendre en charge sans délai',
            [['reponse.deformation_visible', '=', true]],
            [
                [RegistreActionsProtocole::AJOUTER_SCORE, 20, null],
                [RegistreActionsProtocole::DEFINIR_SCORE_MINIMUM, 90,
                    'Une déformation ou une impotence fonctionnelle impose un avis urgent.'],
            ]);
    }

    /**
     * Enregistre un protocole au registre, sans version. Idempotent.
     *
     * `code` et `pays_code` sont hors `$fillable` : posés explicitement, ils ne peuvent pas venir
     * d'une assignation de masse (précédent `identifiant_national` en P6.4a).
     *
     * @param  array<string, mixed>  $attributs
     */
    private function enregistrer(string $code, array $attributs): Protocole
    {
        $pays = config('referentiels.pays_defaut', 'CI');

        $protocole = Protocole::query()->where('pays_code', $pays)->where('code', $code)->first();

        if ($protocole !== null) {
            return $protocole;
        }

        $protocole = new Protocole($attributs);
        $protocole->code = $code;
        $protocole->pays_code = $pays;
        $protocole->save();

        return $protocole;
    }

    /**
     * @param  array<int, array{0: string, 1: string, 2: mixed}>  $conditions
     * @param  array<int, array{0: string, 1: mixed, 2: string|null}>  $actions
     */
    private function regle(
        ProtocoleVersion $version,
        int $ordre,
        string $libelle,
        array $conditions,
        array $actions,
    ): void {
        $regle = $version->regles()->create(['ordre' => $ordre, 'libelle' => $libelle]);

        foreach ($conditions as $i => [$fait, $operateur, $valeur]) {
            $regle->conditions()->create([
                'ordre' => $i + 1,
                'fait' => $fait,
                'operateur' => $operateur,
                // Une valeur simple est rangée comme `[x]`, un intervalle comme `[min, max]` —
                // voir `ProtocoleCondition::valeur()`, qui déballe au même endroit pour tous.
                'valeur_json' => is_array($valeur) ? $valeur : [$valeur],
            ]);
        }

        foreach ($actions as $i => [$type, $valeur, $justification]) {
            $regle->actions()->create([
                'ordre' => $i + 1,
                'type' => $type,
                'valeur_json' => is_array($valeur) ? $valeur : [$valeur],
                'justification' => $justification,
            ]);
        }
    }
}
