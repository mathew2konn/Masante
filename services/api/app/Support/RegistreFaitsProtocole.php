<?php

namespace App\Support;

use App\Models\ProtocoleQuestion;

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
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 * P10b-3-i — `reponse.<cle>` : LA SEULE FAMILLE OUVERTE, ET CE QUI LA REFERME
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 *
 * Ce commentaire disait jusqu'ici : *« l'ajouter aujourd'hui ouvrirait un suffixe libre sans rien
 * pour le vérifier »*. C'était la bonne raison d'attendre, et la condition posée est maintenant
 * remplie : les questions vivent dans la VERSION du protocole (`protocole_questions`), donc le
 * suffixe est confrontable.
 *
 * **Le registre à lui seul ne peut pas vérifier le suffixe** — il ne connaît aucun protocole, et
 * lui en donner un le rendrait dépendant de la base, ce qu'il n'est pas. Il valide donc la FORME
 * (préfixe + clé bien formée) ; c'est le **contrôle qualité** qui refuse la publication d'une
 * condition portant `reponse.X` quand `X` n'est pas une question de cette version, et le moteur
 * qui **lève** s'il en rencontre une à l'exécution.
 *
 * Autrement dit la garantie est en deux morceaux, et aucun ne rattrape l'autre : la forme ici, le
 * fond au contrôle qualité. C'est le partage déjà retenu pour les orientations en P10a (la clé
 * étrangère garantit l'existence, le contrôle qualité garantit que le terme est vivant).
 *
 * **Le TYPE d'un `reponse.<cle>` n'est pas connu d'ici** : il est déclaré par la question
 * ({@see ProtocoleQuestion::typeDeFait()}). `type()` renvoie donc `null`, et l'appelant
 * qui a besoin du type doit le résoudre dans la version — ce que fait le contrôle de compatibilité
 * fait/opérateur. Inventer un type par défaut ici ferait passer `>=` sur une question booléenne.
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
     * P10b-3-i — Le préfixe des faits venant du questionnaire (`reponse.intensite`).
     *
     * Écrit ICI et nulle part ailleurs : le compilateur, le contrôle qualité, le service et le
     * modèle `ProtocoleQuestion` s'y réfèrent tous. *Une chaîne recopiée quatre fois finit par
     * diverger trois fois* — précédent `MENTION_PROVENANCE` (P6.8d).
     */
    public const PREFIXE_REPONSE = 'reponse.';

    /**
     * La forme admise d'une clé de question : minuscules, chiffres, tiret bas.
     *
     * Fermée délibérément. Une clé accentuée ou espacée traverserait quatre couches (base, JSON de
     * l'instantané, condition, requête HTTP) où chacune la normaliserait à sa façon — c'est le
     * défaut trouvé en P6.8a, où `iconv('ASCII//TRANSLIT')` dépendait du locale et produisait
     * `gyn_ecologie` sur ce poste et autre chose ailleurs.
     */
    private const FORME_CLE = '/^[a-z0-9_]{1,60}$/';

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
            'type' => self::TYPE_NOMBRE,
            'libelle' => 'Âge du patient, en années',
        ],
        'sexe' => [
            'type' => self::TYPE_TEXTE,
            'libelle' => 'Sexe déclaré du patient (M ou F)',
        ],

        // ═══ LE FAIT QUE LE PROTOCOLE DE TRIAGE FAIT VIVRE ═══
        //
        // `score` est MUTABLE en cours d'évaluation : l'action `DEFINIR_SCORE_MINIMUM` le relève,
        // et les règles suivantes voient la valeur relevée. C'est le chaînage avant d'un moteur
        // d'inférence (§2 « évaluation des règles (moteur d'inférence) »), et c'est ce qui permet
        // au drapeau rouge de primer sans qu'aucune priorité ne soit codée.
        'score' => [
            'type' => self::TYPE_NOMBRE,
            'libelle' => 'Score de sévérité du triage, de 0 à 100',
        ],
        'score_symptomes' => [
            'type' => self::TYPE_NOMBRE,
            'libelle' => 'Part du score venant du poids des symptômes',
        ],
        'score_reponses' => [
            'type' => self::TYPE_NOMBRE,
            'libelle' => 'Part du score venant des réponses au questionnaire',
        ],
        'score_antecedents' => [
            'type' => self::TYPE_NOMBRE,
            'libelle' => 'Part du score venant des antécédents du carnet',
        ],

        'drapeau_rouge' => [
            'type' => self::TYPE_BOOLEEN,
            'libelle' => 'Au moins un symptôme ou une réponse critique a été signalé',
        ],

        'nb_symptomes' => [
            'type' => self::TYPE_NOMBRE,
            'libelle' => 'Nombre de symptômes retenus',
        ],
        'symptome_id' => [
            'type' => self::TYPE_LISTE,
            'libelle' => 'Identifiants des symptômes retenus',
        ],
        'symptome_categorie' => [
            'type' => self::TYPE_LISTE,
            'libelle' => 'Catégories cliniques des symptômes retenus',
        ],
    ];

    public static function existe(string $fait): bool
    {
        if (isset(self::FAITS[$fait])) {
            return true;
        }

        // La FORME seulement — voir l'en-tête. Le fond (« cette question existe-t-elle dans cette
        // version ? ») est vérifié par le contrôle qualité, qui a la version sous les yeux.
        return self::estReponse($fait)
            && preg_match(self::FORME_CLE, self::cleReponse($fait)) === 1;
    }

    /** Ce fait vient-il du questionnaire ? */
    public static function estReponse(string $fait): bool
    {
        return str_starts_with($fait, self::PREFIXE_REPONSE);
    }

    /** La clé de question portée par un fait `reponse.<cle>` — chaîne vide si ce n'en est pas un. */
    public static function cleReponse(string $fait): string
    {
        return self::estReponse($fait)
            ? substr($fait, strlen(self::PREFIXE_REPONSE))
            : '';
    }

    /**
     * Le type d'un fait, ou `null` s'il vient du questionnaire.
     *
     * `null` n'est pas un oubli : le type d'un `reponse.<cle>` est déclaré par la question, et ce
     * registre ne connaît aucun protocole. Un type par défaut inventé ici laisserait passer `>=`
     * sur une question booléenne — l'appelant doit le résoudre dans la version.
     */
    public static function type(string $fait): ?string
    {
        return self::FAITS[$fait]['type'] ?? null;
    }

    public static function libelle(string $fait): string
    {
        if (isset(self::FAITS[$fait])) {
            return self::FAITS[$fait]['libelle'];
        }

        // Repli lisible quand l'énoncé n'est pas résolu (une condition citée hors de sa version).
        // Le §7 fait signer des médecins : leur montrer `reponse.fievre_sup_40` brut reviendrait à
        // leur faire signer du code. L'énoncé réel, lui, est figé dans l'instantané.
        if (self::estReponse($fait)) {
            return 'Réponse à la question « '.self::cleReponse($fait).' »';
        }

        return $fait;
    }

    /** @return array<int, string> */
    public static function codes(): array
    {
        return array_keys(self::FAITS);
    }
}
