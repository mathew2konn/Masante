<?php

namespace App\Support;

/**
 * P10b-1 — Le moteur d'inférence (CDC_08 §2 « évaluation des règles », §4.3a, §11, §12).
 *
 * Classe **PURE** : aucun accès base, aucune horloge, aucun conteneur, tout par paramètre. Motif
 * `ReglesReversement` (P5.5a), `ReglesIntervalleReference` (P6.7a), `ReglesCalendrierVaccinal`
 * (P6.8b), `ReglesOrientation` (P10a). C'est ce qui rend le jugement vérifiable sans monter une
 * base — et le §12 exige justement des « tests unitaires du moteur d'inférence ».
 *
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 * CE QU'IL FAIT, ET SURTOUT CE QU'IL NE FAIT PAS
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 *
 * Il **applique des règles écrites par d'autres**. Il ne contient aucun seuil, aucune valeur
 * clinique, aucune préférence : tout vient du protocole compilé qu'on lui passe. Retirer le
 * protocole ne le rend pas permissif, il le rend inopérant — la même propriété que la clé privée
 * de P6.5b, où retirer le secret ne desserre pas la signature, il l'empêche.
 *
 * Il ne nomme aucune maladie et ne pose aucun diagnostic (CDC_05 §1, CDC_00 §4) : le registre des
 * actions n'a délibérément pas de `DIAGNOSTIQUER` ({@see RegistreActionsProtocole}).
 *
 * ═══ CHAÎNAGE AVANT : LES ACTIONS PEUVENT MODIFIER LES FAITS ═══
 *
 * Les règles sont évaluées **dans l'ordre**, et `DEFINIR_SCORE_MINIMUM` relève le fait `score` pour
 * les règles suivantes. C'est ce qui sort `if ($drapeauRouge) { $score = max($score, 90); }` du code
 * sans le remplacer par une exception codée ailleurs : la priorité du drapeau rouge devient
 * simplement **l'ordre d'une règle**, donnée relue par deux agents (§7).
 *
 * ═══ UN FAIT OU UN OPÉRATEUR INCONNU LÈVE — IL NE VAUT PAS « FAUX » ═══
 *
 * C'est la décision centrale de ce moteur, et elle mérite d'être dite en toutes lettres. Traiter
 * l'inconnu comme « condition non remplie » rendrait un protocole entier inapplicable **sans
 * qu'aucun écran ne change** : les règles ne se déclencheraient jamais, tout semblerait normal, et
 * personne ne saurait que la garantie est morte.
 *
 * C'est la forme de défaut que P10a vient de refermer (« orienter vers un terme désactivé ne fait
 * aucun bruit ») et que P6.8e a refermée sur les numéros d'urgence. Le contrôle qualité refuse
 * cette donnée à la publication ; si elle arrivait quand même jusqu'ici, **on préfère l'exception
 * au silence**.
 */
final class MoteurProtocole
{
    /**
     * Évalue un protocole compilé sur un jeu de faits.
     *
     * @param  array<int, array{ordre: int, libelle: string, conditions: array<int, array{fait: string, operateur: string, valeur: mixed}>, actions: array<int, array{type: string, valeur: mixed, justification?: string|null}>}>  $regles
     *         Les règles compilées de la version ACTIVE, telles que l'instantané les porte.
     * @param  array<string, mixed>  $faits  Ce que l'appelant sait du patient. Une clé absente
     *         signifie « on ne sait pas », jamais « c'est faux » — voir `existe` / `absent`.
     *
     * @return array{actions: array<int, array{type: string, valeur: mixed, justification: string|null, regle: string}>, faits: array<string, mixed>, regles_declenchees: array<int, array{ordre: int, libelle: string}>}
     *
     * @throws \InvalidArgumentException si une règle porte un fait, un opérateur ou une action
     *         que le registre ne connaît pas.
     */
    public static function evaluer(array $regles, array $faits): array
    {
        // Les faits sont COPIÉS : le chaînage avant les modifie, et l'appelant doit pouvoir
        // comparer ce qu'il a fourni à ce que l'évaluation a produit (`details_score` du triage).
        $courants = $faits;
        $actions = [];
        $declenchees = [];

        // L'ordre est une donnée clinique, pas un détail d'implémentation : c'est lui qui fait
        // primer le drapeau rouge. On trie explicitement plutôt que de se fier à l'ordre du
        // tableau — leçon `NumeroUrgence::scopeOrdonne` (P6.8e) et du tri total de
        // `ReglesOrientation` (P10a) : sans critère explicite, la même donnée répondrait
        // différemment d'un moteur de base à l'autre.
        usort($regles, static fn (array $a, array $b): int => ($a['ordre'] ?? 0) <=> ($b['ordre'] ?? 0));

        foreach ($regles as $regle) {
            if (! self::conditionsRemplies($regle['conditions'] ?? [], $courants)) {
                continue;
            }

            $declenchees[] = [
                'ordre'   => (int) ($regle['ordre'] ?? 0),
                'libelle' => (string) ($regle['libelle'] ?? ''),
            ];

            foreach ($regle['actions'] ?? [] as $action) {
                $type = (string) ($action['type'] ?? '');

                if (! RegistreActionsProtocole::existe($type)) {
                    throw new \InvalidArgumentException(
                        "Action de protocole inconnue : « {$type} ». Le contrôle qualité aurait dû "
                        .'refuser cette publication.'
                    );
                }

                $valeur = $action['valeur'] ?? null;

                // Chaînage avant : la seule action qui modifie les faits en cours d'évaluation.
                // Elle est traitée ici et NON ajoutée à la liste restituée — ce n'est pas une
                // recommandation à afficher, c'est un effet sur le raisonnement.
                if ($type === RegistreActionsProtocole::DEFINIR_SCORE_MINIMUM) {
                    $courants['score'] = max(
                        (int) ($courants['score'] ?? 0),
                        (int) $valeur,
                    );

                    continue;
                }

                $actions[] = [
                    'type'          => $type,
                    'valeur'        => $valeur,
                    'justification' => $action['justification'] ?? null,
                    // De quelle règle vient cette action. §9.1 attend une « justification » et
                    // §10 « les recommandations affichées » : sans le libellé de la règle, une
                    // recommandation serait une affirmation sans origine.
                    'regle'         => (string) ($regle['libelle'] ?? ''),
                ];
            }
        }

        return [
            'actions'            => $actions,
            'faits'              => $courants,
            'regles_declenchees' => $declenchees,
        ];
    }

    /**
     * Toutes les conditions d'une règle doivent être remplies (`ET` du §4.3a).
     *
     * IL N'Y A PAS DE `OU`, et c'est délibéré : le §4.3a n'en montre aucun, et introduire une
     * algèbre booléenne complète dans des données relues par des médecins rendrait une règle
     * difficile à lire — donc difficile à valider au sens du §7. Deux cas alternatifs s'écrivent
     * en deux règles, ce qui les rend **visibles séparément** dans le dossier de validation.
     *
     * @param  array<int, array{fait: string, operateur: string, valeur: mixed}>  $conditions
     * @param  array<string, mixed>  $faits
     */
    private static function conditionsRemplies(array $conditions, array $faits): bool
    {
        foreach ($conditions as $condition) {
            if (! self::conditionRemplie($condition, $faits)) {
                return false;
            }
        }

        // Une règle sans condition s'applique toujours. C'est un cas légitime (une consigne
        // systématique), pas un oubli : le contrôle qualité le signale au rédacteur sans le
        // bloquer, parce que le distinguer d'un oubli demanderait de lire dans ses intentions.
        return true;
    }

    /**
     * @param  array{fait: string, operateur: string, valeur: mixed}  $condition
     * @param  array<string, mixed>  $faits
     */
    private static function conditionRemplie(array $condition, array $faits): bool
    {
        $nom = (string) ($condition['fait'] ?? '');
        $operateur = (string) ($condition['operateur'] ?? '');

        if (! RegistreFaitsProtocole::existe($nom)) {
            throw new \InvalidArgumentException(
                "Fait de protocole inconnu : « {$nom} ». Une condition portant un fait inconnu ne "
                .'peut pas être évaluée — la traiter comme « non remplie » rendrait le protocole '
                .'inapplicable en silence.'
            );
        }

        if (! RegistreOperateursProtocole::existe($operateur)) {
            throw new \InvalidArgumentException(
                "Opérateur de protocole inconnu : « {$operateur} » sur le fait « {$nom} »."
            );
        }

        $connu = array_key_exists($nom, $faits) && $faits[$nom] !== null;
        $valeurFait = $faits[$nom] ?? null;
        $attendue = $condition['valeur'] ?? null;

        // ═══ `existe` / `absent` PORTENT SUR LA CONNAISSANCE, PAS SUR LA VALEUR ═══
        //
        // Ils se traitent AVANT le test de connaissance ci-dessous : ce sont les deux seuls
        // opérateurs dont l'ignorance est précisément le sujet.
        if ($operateur === 'existe') {
            return $connu;
        }

        if ($operateur === 'absent') {
            return ! $connu;
        }

        // ═══ UN FAIT INCONNU NE REMPLIT AUCUNE AUTRE CONDITION ═══
        //
        // Et ce n'est PAS la contradiction du principe défendu plus haut. Lever ici serait
        // absurde : un triage anonyme ne renseigne pas le sexe, et cela ne rend pas le protocole
        // défectueux. La différence est entre un fait **que le système ne sait pas produire**
        // (défaut de conception, on lève) et un fait **que ce patient n'a pas renseigné** (cas
        // normal, la condition n'est pas remplie).
        //
        // C'est le raisonnement de `ReglesOrientation` : « on n'agit que sur ce qu'on sait », et
        // celui des trois silences de P7-D2.
        if (! $connu) {
            return false;
        }

        return match ($operateur) {
            '='   => self::comparables($valeurFait, $attendue),
            '!='  => ! self::comparables($valeurFait, $attendue),
            '<'   => (float) $valeurFait < (float) $attendue,
            '<='  => (float) $valeurFait <= (float) $attendue,
            '>'   => (float) $valeurFait > (float) $attendue,
            '>='  => (float) $valeurFait >= (float) $attendue,

            // Bornes inclusives des deux côtés — la convention est déclarée dans
            // `RegistreOperateursProtocole` et le contrôle de couverture s'appuie dessus.
            'entre' => is_array($attendue)
                && count($attendue) === 2
                && (float) $valeurFait >= (float) $attendue[0]
                && (float) $valeurFait <= (float) $attendue[1],

            'contient'        => is_array($valeurFait) && self::listeContient($valeurFait, $attendue),
            'ne_contient_pas' => is_array($valeurFait) && ! self::listeContient($valeurFait, $attendue),

            default => false,
        };
    }

    /**
     * Égalité tolérante au type, mais JAMAIS lâche.
     *
     * `'M' == 0` vaut `true` avec `==` en PHP historique : une comparaison lâche ferait matcher un
     * sexe contre un nombre. On compare donc en chaînes, ce qui rend `1` et `'1'` égaux (ce qu'un
     * rédacteur attend en écrivant `age = 5`) sans jamais rapprocher deux natures différentes.
     *
     * Les booléens sont normalisés d'abord : un protocole écrit `drapeau_rouge = true`, la donnée
     * JSON porte `true`, le fait porte `true` — mais un `'1'` venu d'un formulaire ne doit pas
     * rater la comparaison.
     */
    private static function comparables(mixed $a, mixed $b): bool
    {
        if (is_bool($a) || is_bool($b)) {
            return filter_var($a, FILTER_VALIDATE_BOOLEAN) === filter_var($b, FILTER_VALIDATE_BOOLEAN);
        }

        return (string) $a === (string) $b;
    }

    /** @param  array<int, mixed>  $liste */
    private static function listeContient(array $liste, mixed $valeur): bool
    {
        foreach ($liste as $element) {
            if (self::comparables($element, $valeur)) {
                return true;
            }
        }

        return false;
    }
}
