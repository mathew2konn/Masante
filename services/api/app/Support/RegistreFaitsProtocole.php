<?php

namespace App\Support;

use App\Models\ProtocoleQuestion;
use App\Models\ReferentielMesure;

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
     * P10c-1 — Le préfixe des CONSTANTES CLINIQUES du §5.2 (`constante.temperature`).
     *
     * ═══════════════════════════════════════════════════════════════════════════════════════════
     * CE COMMENTAIRE DISAIT « CES FAITS ENTRERONT QUAND LEUR COLLECTE EXISTERA ». ELLE EXISTE.
     * ═══════════════════════════════════════════════════════════════════════════════════════════
     *
     * L'en-tête ci-dessus posait la condition : déclarer `temperature` sans écran pour la collecter
     * aurait permis d'écrire une règle qui ne se déclenche jamais — une garantie inerte. P10c-1
     * livre la collecte ; le fait entre.
     *
     * ═══ POURQUOI UNE FAMILLE À PRÉFIXE ET NON SEPT LIGNES DANS `FAITS` ═══
     *
     * Sept entrées figées ici rendraient fausse la propriété que cet incrément cherche : la liste
     * des constantes collectables vient de la **version publiée** du référentiel `seuils_mesure`.
     * Ajouter un type au référentiel doit rester une **publication**, pas une livraison — c'est
     * l'argument V1 d'ADR-027 (une ville en donnée) et le gain de P10b-2 (un protocole régional
     * sans une ligne de code).
     *
     * ═══ LE PARTAGE EST LE MÊME QUE POUR `reponse.<cle>`, ET AUCUN NE RATTRAPE L'AUTRE ═══
     *
     * Ce registre ne connaît aucun référentiel — lui en donner un le rendrait dépendant de la base,
     * ce qu'il n'est pas. Il valide donc la **FORME** (préfixe + clé bien formée) ; c'est le
     * contrôle qualité qui refuse la publication d'une condition portant `constante.X` quand `X`
     * n'est pas un type de la version en vigueur, et le moteur qui **lève** s'il en rencontre une.
     *
     * ═══ LE TYPE, LUI, EST CONNU D'ICI — À LA DIFFÉRENCE D'UN `reponse.<cle>` ═══
     *
     * Une constante est **toujours** un nombre : `mesures_sante.valeur` est un `decimal`, et le
     * référentiel n'en décrit pas d'autre forme. `type()` peut donc répondre sans consulter quoi que
     * ce soit, et le contrôle de compatibilité fait/opérateur s'applique tel quel — `contient` sur
     * une température est refusé exactement comme il l'est sur `age`.
     *
     * ═══ CE QUI N'ENTRE PAS, ET C'EST LE POINT DE CONCEPTION ═══
     *
     * **Aucun fait `constante.temperature_statut`.** Le référentiel sait classer une valeur en
     * `critique` ({@see ReferentielMesure::statutPour()}), et un protocole pourrait
     * s'en servir. Il ne doit pas : `critique_haut` est gouverné par les **deux** signatures
     * administratives du §10, alors qu'un seuil décidant de l'urgence relève des **quatre**
     * validations du §7. C'est l'asymétrie refermée par P10b-3-i, qu'on ne rouvre pas un cran plus
     * bas. Le protocole compare la **valeur brute** — et c'est le §1.2 retourné à l'endroit.
     */
    public const PREFIXE_CONSTANTE = 'constante.';

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
            'libelle' => 'Part du score venant des antécédents du carnet, une fois bornée',
        ],

        // ═══ P10b-3-ii — LA SOMME AVANT BORNE, ET POURQUOI ELLE EST UN FAIT DISTINCT ═══
        //
        // `PLAFOND_ANTECEDENTS = 20` vivait dans `TriageService`. C'était un seuil, donc une
        // décision clinique : quel poids une déclaration NON VÉRIFIÉE du patient peut-elle avoir
        // sur son urgence ? La borne devient une règle relue et signée (§7), et il lui faut la
        // valeur brute pour la comparer — d'où deux faits, jamais un seul qui changerait de sens
        // en cours d'évaluation.
        'score_antecedents_brut' => [
            'type' => self::TYPE_NOMBRE,
            'libelle' => 'Somme des impacts déclarés des antécédents, avant toute borne',
        ],
        'nb_antecedents' => [
            'type' => self::TYPE_NOMBRE,
            'libelle' => "Nombre d'antécédents déclarés au carnet",
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
        // version ? », « ce type est-il au référentiel publié ? ») est vérifié par le contrôle
        // qualité, qui a la version sous les yeux.
        if (self::estReponse($fait)) {
            return preg_match(self::FORME_CLE, self::cleReponse($fait)) === 1;
        }

        return self::estConstante($fait)
            && preg_match(self::FORME_CLE, self::typeConstante($fait)) === 1;
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

    /** P10c-1 — Ce fait est-il une constante clinique du §5.2 ? */
    public static function estConstante(string $fait): bool
    {
        return str_starts_with($fait, self::PREFIXE_CONSTANTE);
    }

    /** Le type de mesure porté par `constante.<type>` — chaîne vide si ce n'en est pas un. */
    public static function typeConstante(string $fait): string
    {
        return self::estConstante($fait)
            ? substr($fait, strlen(self::PREFIXE_CONSTANTE))
            : '';
    }

    /**
     * Le type d'un fait, ou `null` s'il vient du questionnaire.
     *
     * `null` n'est pas un oubli : le type d'un `reponse.<cle>` est déclaré par la question, et ce
     * registre ne connaît aucun protocole. Un type par défaut inventé ici laisserait passer `>=`
     * sur une question booléenne — l'appelant doit le résoudre dans la version.
     *
     * P10c-1 — Une CONSTANTE, elle, est toujours un nombre : `mesures_sante.valeur` est un
     * `decimal` et le référentiel n'en décrit pas d'autre forme. Répondre ici ne consulte rien et
     * n'invente rien, si bien que le contrôle de compatibilité fait/opérateur s'applique tel quel.
     */
    public static function type(string $fait): ?string
    {
        if (self::estConstante($fait)) {
            return self::TYPE_NOMBRE;
        }

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

        // P10c-1 — Même raison : le §7 fait signer des médecins, et `constante.saturation_o2` brut
        // n'est pas une phrase. Le libellé exact et l'unité vivent dans le référentiel publié ;
        // les recopier ici en ferait une seconde vérité, capable de diverger après une correction.
        if (self::estConstante($fait)) {
            return 'Constante mesurée « '.self::typeConstante($fait).' »';
        }

        return $fait;
    }

    /** @return array<int, string> */
    public static function codes(): array
    {
        return array_keys(self::FAITS);
    }
}
