<?php

namespace App\Support;

/**
 * P10b-1 — Liste blanche FERMÉE des actions qu'une règle peut déclencher (CDC_08 §4.3a).
 *
 * Le §4.3a montre `ALORS Urgence / Hospitalisation / Traitement injectable / Surveillance
 * neurologique` : quatre actions de natures différentes. Elles sont typées ici, jamais en texte
 * libre — le §4.4 l'exige explicitement (« les conditions et actions utilisent les codes des
 * référentiels nationaux plutôt que du texte libre »).
 *
 * ═══ DEUX FAMILLES QUI NE SE MÉLANGENT PAS ═══
 *
 *  1. Les actions **de décision d'orientation**, que le moteur de triage sait consommer
 *     aujourd'hui : `DEFINIR_NIVEAU`, `DEFINIR_SCORE_MINIMUM`, `ORIENTER`.
 *  2. Les actions **de conduite à tenir** (§5.1), que seuls les protocoles thérapeutiques
 *     utilisent : `HOSPITALISATION`, `EXAMEN`, `TRAITEMENT`, `SURVEILLANCE`.
 *
 * Les secondes existent parce que les protocoles thérapeutiques doivent être RÉDIGEABLES — c'est
 * la décision G1 N3 : ils sont seedés en **brouillon non validé**, pour démontrer la structure
 * §4.1/§4.3 et l'écran d'authoring. Elles ne sont exécutées nulle part en P10b-1, et c'est voulu :
 * **aucun protocole thérapeutique n'est publié**, donc aucun n'est appliqué.
 *
 * Ce n'est pas une intention, c'est un comportement : le moteur ne lit que les versions à l'état
 * `actif`, et le §1.6 (« aucun protocole utilisable sans validation ») devient ainsi prouvable par
 * un vecteur au lieu d'être promis par un commentaire.
 *
 * ═══ CE QUI EST DÉLIBÉRÉMENT ABSENT ═══
 *
 * `DIAGNOSTIQUER`. Aucune action de ce moteur ne nomme une maladie. CDC_05 §1 : « le triage n'est
 * jamais un diagnostic » ; CDC_00 §4 range « triage présenté comme diagnostic » parmi les interdits
 * absolus. Une action qui poserait un diagnostic serait la porte par laquelle l'interdit
 * rentrerait — sous une forme que personne ne relirait, puisqu'elle viendrait de la donnée.
 */
final class RegistreActionsProtocole
{
    /** Fixe le niveau de priorité patient (CDC_05 §5.3). Valeur = un code de `NiveauTriage`. */
    public const DEFINIR_NIVEAU = 'DEFINIR_NIVEAU';

    /**
     * Relève le plancher du score. Valeur = un entier.
     *
     * ═══ C'EST ELLE QUI SORT `max($score, 90)` DU CODE ═══
     *
     * `TriageService` portait `if ($drapeauRouge) { $score = max($score, 90); }` : un seuil
     * clinique en dur, exactement le contre-exemple du §1.2. Il devient une règle d'ordre 1, et
     * comme le moteur chaîne vers l'avant, les règles de bande suivantes voient le score relevé.
     *
     * La priorité du drapeau rouge n'est donc plus une exception codée : c'est l'ORDRE de la
     * règle, une donnée relue par deux agents (§7).
     */
    public const DEFINIR_SCORE_MINIMUM = 'DEFINIR_SCORE_MINIMUM';

    /** Ajoute une spécialité à l'orientation. Valeur = un code du vocabulaire national (P6.8a). */
    public const ORIENTER = 'ORIENTER';

    /** Conduite à tenir (§5.1) — protocoles thérapeutiques, non exécutée en P10b-1. */
    public const HOSPITALISATION = 'HOSPITALISATION';

    public const EXAMEN = 'EXAMEN';

    public const TRAITEMENT = 'TRAITEMENT';

    public const SURVEILLANCE = 'SURVEILLANCE';

    /** Message affiché tel quel, sans interprétation. */
    public const MESSAGE = 'MESSAGE';

    /**
     * P10b-3-i — Pose une question du questionnaire. Valeur = la clé d'une `protocole_questions`.
     *
     * ═══ C'EST ELLE QUI PORTE L'ARBORESCENCE DU §4.3b ═══
     *
     *     SI symptome_categorie contient « respiratoire »  ALORS POSER_QUESTION(au_repos)
     *     SI reponse.fievre_sup_40 = vrai                  ALORS POSER_QUESTION(convulsions)
     *
     * L'arborescence n'est donc **pas** une structure de données à part : c'est le chaînage des
     * règles de P10b-1, qui produit `Fièvre → Durée ? → Difficulté respiratoire ? → …` sans que
     * `MoteurProtocole` ait à connaître la notion de question. Décision R1 du plan G1 — et c'est
     * ce qui explique que ce moteur ne soit pas modifié par cet incrément.
     */
    public const POSER_QUESTION = 'POSER_QUESTION';

    /**
     * P10b-3-i — Ajoute des points au score. Valeur = un entier (négatif accepté).
     *
     * ═══ ELLE SORT DU RÉFÉRENTIEL LA DERNIÈRE RÈGLE MÉDICALE QUI Y RESTAIT (constat X3) ═══
     *
     * L'impact d'une réponse vivait en JSON dans le référentiel des symptômes :
     * `['points_si_vrai' => 15, 'drapeau_rouge_si_vrai' => true]` — le contre-exemple littéral du
     * §1.2, gouverné par deux signatures administratives (§10) mais **jamais validé** par les
     * quatre du §7. Il devient une règle :
     *
     *     SI reponse.fievre_sup_40 = vrai  ALORS AJOUTER_SCORE 15
     *                                       ET  DEFINIR_SCORE_MINIMUM 90
     *
     * ═══ ET LE DRAPEAU ROUGE CESSE D'ÊTRE UN BOOLÉEN CACHÉ ═══
     *
     * `drapeau_rouge_si_vrai` disparaît : ce qu'il faisait — faire primer une réponse — est déjà
     * dit par `DEFINIR_SCORE_MINIMUM`, créée en P10b-1 pour le drapeau rouge des symptômes. Une
     * seule façon d'écrire « ceci prime », au lieu de deux qui pouvaient diverger.
     *
     * ═══ CUMULATIVE, ET LE PIÈGE QUE CELA OUVRE ═══
     *
     * Les valeurs s'additionnent au sein d'UNE évaluation. Comme le questionnaire est itératif,
     * une même règle peut se déclencher au tour 1 **et** au tour 3 : cumuler les tours ferait
     * dépendre le score du **nombre d'allers-retours**, c'est-à-dire de la façon dont le client a
     * répondu et non de ce qu'il a répondu. D'où la décision R5 — les tours intermédiaires ne
     * servent qu'à savoir quoi demander, et **une seule évaluation finale fait autorité**.
     */
    public const AJOUTER_SCORE = 'AJOUTER_SCORE';

    /**
     * P10b-3-ii — Fixe la part du score venant des antécédents (CDC_08 §1.2).
     *
     * ═══ POURQUOI « BORNER » ET NON « DÉFINIR », APRÈS AVOIR ESSAYÉ L'INVERSE ═══
     *
     * La première écriture disait `SI brut > 20 ALORS DEFINIR 20`. Elle ne tient pas : il faut
     * alors une seconde règle pour le cas contraire — « sinon, garder la somme telle quelle » —
     * et **cette valeur-là est dynamique**, donc inexprimable par une action à valeur littérale.
     * Y mettre 0 aurait effacé les antécédents des patients qui en déclarent peu : le contraire
     * exact de ce que la règle veut dire.
     *
     * La borne, elle, s'écrit en **une seule règle sans condition** — « la part des antécédents ne
     * dépasse pas 20 » — et le service applique un `min`. La DÉCISION (le chiffre) est dans le
     * protocole, relue et signée ; l'ARITHMÉTIQUE reste dans le service, au même titre que la
     * somme et les bornes 0-100 qui y vivent déjà.
     */
    public const BORNER_SCORE_ANTECEDENTS = 'BORNER_SCORE_ANTECEDENTS';

    /**
     * type => [valeur attendue ?, famille, libellé lisible]
     *
     * @var array<string, array{valeur: bool, famille: string, libelle: string}>
     */
    public const ACTIONS = [
        self::DEFINIR_NIVEAU => [
            'valeur' => true,
            'famille' => 'orientation',
            'libelle' => 'Définir le niveau de priorité',
        ],
        self::DEFINIR_SCORE_MINIMUM => [
            'valeur' => true,
            'famille' => 'orientation',
            'libelle' => 'Relever le score au minimum à',
        ],
        self::ORIENTER => [
            'valeur' => true,
            'famille' => 'orientation',
            'libelle' => 'Orienter vers la spécialité',
        ],
        self::HOSPITALISATION => [
            'valeur' => false,
            'famille' => 'conduite',
            'libelle' => 'Hospitalisation',
        ],
        self::EXAMEN => [
            'valeur' => true,
            'famille' => 'conduite',
            'libelle' => 'Examen à réaliser',
        ],
        self::TRAITEMENT => [
            'valeur' => true,
            'famille' => 'conduite',
            'libelle' => 'Traitement',
        ],
        self::SURVEILLANCE => [
            'valeur' => true,
            'famille' => 'conduite',
            'libelle' => 'Surveillance',
        ],
        self::MESSAGE => [
            'valeur' => true,
            'famille' => 'conduite',
            'libelle' => 'Message',
        ],

        // P10b-3-i — famille `questionnaire` : ni orientation ni conduite à tenir. Ces deux
        // actions ne disent rien au patient et ne prescrivent rien ; elles construisent
        // l'interrogatoire et le score. Les ranger dans « conduite » les ferait apparaître parmi
        // les recommandations affichées (§10), ce qu'elles ne sont pas.
        self::POSER_QUESTION => [
            'valeur' => true,
            'famille' => 'questionnaire',
            'libelle' => 'Poser la question',
        ],
        self::AJOUTER_SCORE => [
            'valeur' => true,
            'famille' => 'questionnaire',
            'libelle' => 'Ajouter au score',
        ],

        // Même famille : elle construit le score, elle ne dit rien au patient et ne prescrit rien.
        self::BORNER_SCORE_ANTECEDENTS => [
            'valeur' => true,
            'famille' => 'questionnaire',
            'libelle' => 'Borner la part des antécédents à',
        ],
    ];

    /**
     * P10b-2 — Les actions dont UNE SEULE valeur peut prévaloir (CDC_08 §8).
     *
     * ═══ SANS CETTE DISTINCTION, LE §8 SE DÉCLENCHERAIT SUR DES FAUX CONFLITS ═══
     *
     * Deux protocoles qui orientent vers deux spécialités ne se contredisent pas : ils
     * s'additionnent — c'est exactement ce que P10a fait déjà en agrégeant les orientations d'un
     * même symptôme. Deux `MESSAGE` non plus. Traiter ces cas comme des divergences ferait
     * consigner des conflits là où il n'y a qu'un cumul, et le journal du §8 deviendrait
     * illisible pour les vraies divergences.
     *
     * `DEFINIR_NIVEAU` seul y figure aujourd'hui : un patient a UN niveau de priorité, pas deux.
     *
     * `DEFINIR_SCORE_MINIMUM` reste CUMULATIF, et ce n'est pas un oubli : deux planchers ne se
     * contredisent pas, le plus haut s'applique. Deux protocoles qui relèvent le score, l'un à 70
     * l'autre à 90, disent la même chose avec des forces différentes.
     *
     * P10b-3-i — `POSER_QUESTION` et `AJOUTER_SCORE` n'y figurent pas non plus, pour la même
     * raison qu'`ORIENTER` : deux protocoles qui posent la même question ne se contredisent pas,
     * la question est posée une fois ; deux qui ajoutent des points ne se contredisent pas
     * davantage, les points s'additionnent. Les déclarer exclusives remplirait le journal du §8 de
     * divergences imaginaires — le défaut que cette liste existe pour éviter.
     *
     * @var array<int, string>
     */
    // P10b-3-ii — `BORNER_SCORE_ANTECEDENTS` est EXCLUSIVE, à la différence de
    // `DEFINIR_SCORE_MINIMUM`. Deux planchers ne se contredisent pas (le plus haut s'applique,
    // b-2 le déclare) ; deux BORNES, si : rien ne dit laquelle vaut, et en choisir une au hasard
    // reviendrait à inventer une sémantique que personne n'a signée.
    public const EXCLUSIVES = [self::DEFINIR_NIVEAU, self::BORNER_SCORE_ANTECEDENTS];

    /** Une seule valeur peut-elle prévaloir pour ce type d'action ? */
    public static function estExclusive(string $type): bool
    {
        return in_array($type, self::EXCLUSIVES, true);
    }

    public static function existe(string $type): bool
    {
        return isset(self::ACTIONS[$type]);
    }

    public static function attendUneValeur(string $type): bool
    {
        return self::ACTIONS[$type]['valeur'] ?? false;
    }

    public static function libelle(string $type): string
    {
        return self::ACTIONS[$type]['libelle'] ?? $type;
    }

    public static function famille(string $type): ?string
    {
        return self::ACTIONS[$type]['famille'] ?? null;
    }

    /** @return array<int, string> */
    public static function codes(): array
    {
        return array_keys(self::ACTIONS);
    }
}
