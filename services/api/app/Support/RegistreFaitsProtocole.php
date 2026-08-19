<?php

namespace App\Support;

/**
 * P10b-1 — Liste blanche FERMÉE des faits qu'une condition de protocole peut interroger
 * (CDC_08 §4.3a).
 *
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 * POURQUOI FERMÉE — la même raison qu'en P7-C pour les sections du carnet et qu'en P6.3 pour les
 * référentiels : **le nom du fait arrive par la DONNÉE**, donc par l'écran d'authoring. Sans liste
 * blanche il deviendrait un choix libre du rédacteur, et une condition pourrait désigner n'importe
 * quoi.
 *
 * ═══ ET SURTOUT : UN FAIT INCONNU NE S'ÉVALUE PAS À FAUX ═══
 *
 * C'est le point central de tout le moteur. Si `MoteurProtocole` traitait un fait inconnu comme
 * « condition non remplie », un protocole entier deviendrait inapplicable **sans que rien ne le
 * signale** : les règles ne se déclencheraient jamais, l'écran resterait cohérent, et personne ne
 * saurait que la garantie est morte.
 *
 * C'est exactement la forme de défaut que P10a vient de refermer sur l'orientation (« orienter vers
 * un terme désactivé ne fait aucun bruit ») et que P6.8e a refermée sur les numéros d'urgence
 * (« une version où plus aucun numéro n'est actif ne casserait rien de visible »). On ne la rouvre
 * pas par la porte de derrière : le contrôle qualité **refuse la publication** d'une condition
 * portant un fait absent de cette liste, et le moteur **lève** s'il en rencontre un à l'exécution.
 *
 * ═══ ON NE DÉCLARE QUE CE QUE L'APPELANT PRODUIT RÉELLEMENT ═══
 *
 * Déclarer `temperature` ou `spo2` — que CDC_05 §5.2 cite — alors qu'aucun écran ne les collecte
 * permettrait d'écrire une règle qui ne se déclencherait jamais. Ce serait publier une garantie
 * inerte. Ces faits entreront quand leur collecte existera.
 *
 * `reponse.<cle>` (les réponses au questionnaire) est le manque le plus visible de cette liste : il
 * arrive en **P10b-3** avec le questionnaire adaptatif, où la clé pourra être confrontée aux
 * questions de la version publiée du référentiel des symptômes. L'ajouter aujourd'hui ouvrirait un
 * suffixe libre sans rien pour le vérifier.
 *
 * AJOUTER UN FAIT = ajouter une ligne ici et le produire dans l'assembleur. Le moteur ne bouge pas.
 */
final class RegistreFaitsProtocole
{
    /** Le fait porte un nombre entier. */
    public const TYPE_NOMBRE = 'nombre';

    /** Le fait porte une chaîne. */
    public const TYPE_TEXTE = 'texte';

    /** Le fait porte un booléen. */
    public const TYPE_BOOLEEN = 'booleen';

    /** Le fait porte une LISTE — seul `contient` a du sens dessus. */
    public const TYPE_LISTE = 'liste';

    /**
     * fait => [type, libellé lisible par un relecteur clinique du §7]
     *
     * Le libellé n'est pas décoratif : le §7 fait signer des médecins spécialistes. Leur présenter
     * `score >= 76` sans phrase reviendrait à leur faire signer du code.
     *
     * @var array<string, array{type: string, libelle: string}>
     */
    public const FAITS = [
        'age' => [
            'type'    => self::TYPE_NOMBRE,
            'libelle' => 'Âge du patient, en années',
        ],
        'sexe' => [
            'type'    => self::TYPE_TEXTE,
            'libelle' => 'Sexe déclaré du patient (M ou F)',
        ],

        // ═══ LE FAIT QUE LE PROTOCOLE DE TRIAGE FAIT VIVRE ═══
        //
        // `score` est MUTABLE en cours d'évaluation : l'action `DEFINIR_SCORE_MINIMUM` le relève,
        // et les règles suivantes voient la valeur relevée. C'est le chaînage avant d'un moteur
        // d'inférence (§2 « évaluation des règles (moteur d'inférence) »), et c'est ce qui permet
        // au drapeau rouge de primer sans qu'aucune priorité ne soit codée.
        'score' => [
            'type'    => self::TYPE_NOMBRE,
            'libelle' => 'Score de sévérité du triage, de 0 à 100',
        ],
        'score_symptomes' => [
            'type'    => self::TYPE_NOMBRE,
            'libelle' => 'Part du score venant du poids des symptômes',
        ],
        'score_reponses' => [
            'type'    => self::TYPE_NOMBRE,
            'libelle' => 'Part du score venant des réponses au questionnaire',
        ],
        'score_antecedents' => [
            'type'    => self::TYPE_NOMBRE,
            'libelle' => 'Part du score venant des antécédents du carnet',
        ],

        'drapeau_rouge' => [
            'type'    => self::TYPE_BOOLEEN,
            'libelle' => 'Au moins un symptôme ou une réponse critique a été signalé',
        ],

        'nb_symptomes' => [
            'type'    => self::TYPE_NOMBRE,
            'libelle' => 'Nombre de symptômes retenus',
        ],
        'symptome_id' => [
            'type'    => self::TYPE_LISTE,
            'libelle' => 'Identifiants des symptômes retenus',
        ],
        'symptome_categorie' => [
            'type'    => self::TYPE_LISTE,
            'libelle' => 'Catégories cliniques des symptômes retenus',
        ],
    ];

    public static function existe(string $fait): bool
    {
        return isset(self::FAITS[$fait]);
    }

    public static function type(string $fait): ?string
    {
        return self::FAITS[$fait]['type'] ?? null;
    }

    public static function libelle(string $fait): string
    {
        return self::FAITS[$fait]['libelle'] ?? $fait;
    }

    /** @return array<int, string> */
    public static function codes(): array
    {
        return array_keys(self::FAITS);
    }
}
