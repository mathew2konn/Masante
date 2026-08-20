<?php

namespace App\Services\Triage;

use App\Services\Protocole\CompilateurProtocole;
use App\Services\Protocole\DiffusionProtocole;
use App\Services\Protocole\SelecteurProtocoles;
use App\Support\MoteurProtocole;
use App\Support\RegistreActionsProtocole;
use App\Support\RegistreContextesProtocole;
use App\Support\RegistreFaitsProtocole;
use Illuminate\Validation\ValidationException;

/**
 * P10b-3-i — Le questionnaire adaptatif, gouverné par protocole (CDC_08 §4.3b, §13 étape 4 ;
 * CDC_05 §5.5.2).
 *
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 * CE QU'IL A REMPLACÉ, ET POURQUOI CE N'ÉTAIT PAS COSMÉTIQUE
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 *
 * `TriageService::evaluerReponses()` portait quatre `elseif` calculant l'impact d'une réponse à
 * partir d'un blob JSON du référentiel des symptômes :
 *
 *     'impact' => ['points_si_vrai' => 15, 'drapeau_rouge_si_vrai' => true]
 *
 * C'était le contre-exemple littéral du §1.2 (`if temperature > 39: urgence = True`), en donnée.
 * Gouverné par le quatre-yeux du §10 — deux signatures administratives — mais **jamais validé** au
 * sens du §7, qui en exige quatre dont la clinique et la réglementaire. P10b-1 avait soumis les
 * seuils de NIVEAU à ces quatre validations et laissé cette règle-ci derrière : c'est l'asymétrie
 * que cet incrément referme.
 *
 * L'impact est désormais une RÈGLE :
 *
 *     SI reponse.fievre_sup_40 = vrai  ALORS AJOUTER_SCORE 15  ET  DEFINIR_SCORE_MINIMUM 90
 *
 * et `drapeau_rouge_si_vrai` disparaît : faire primer une réponse se dit déjà avec l'action que
 * P10b-1 a créée pour le drapeau rouge des symptômes. Une seule façon d'écrire « ceci prime ».
 *
 * ═══ LA BOUCLE DE POINT FIXE, ET LA SEULE ÉVALUATION QUI FAIT AUTORITÉ ═══
 *
 * Le §4.3b décrit un questionnaire arborescent : `Fièvre → Durée ? → Difficulté respiratoire ? → …`.
 * Chaque réponse débloque les questions suivantes, par chaînage des règles.
 *
 * Une même règle peut donc se déclencher au tour 1 **et** au tour 3. Cumuler les `AJOUTER_SCORE`
 * de chaque tour ferait dépendre le score du **nombre d'allers-retours** — c'est-à-dire de la
 * façon dont le patient a répondu, pas de ce qu'il a répondu. C'est le piège central de cette
 * conception, et la parade est nette :
 *
 *   - {@see prochainesQuestions()} sert UNIQUEMENT à savoir quoi demander. Son résultat n'entre
 *     dans aucun score.
 *   - {@see impact()} fait UNE évaluation, sur le jeu de réponses complet, et c'est elle qui
 *     compte.
 *
 * Un vecteur dédié le prouve : mêmes réponses obtenues en 1 tour ou en 4 → même score, même niveau.
 *
 * ═══ REFUS BRUYANT, ET LA QUESTION A ÉTÉ REPOSÉE ═══
 *
 * Aucun questionnaire en vigueur → **503**, jamais de repli sur `questions_complementaires_json`.
 * Un repli laisserait un oubli de publication **invisible** : le triage poserait les anciennes
 * questions et personne ne saurait que la version gouvernée n'est pas en vigueur (décision de
 * L1+L2, reprise en P10a et P10b-1).
 *
 * P6.8e avait tranché l'inverse pour les numéros d'urgence — son argument (« le consommateur n'a
 * ni réseau, ni session, ni compte ») ne vaut pas ici : le triage est un appel API.
 */
final class ServiceQuestionnaire
{
    /**
     * Le protocole que le déploiement doit publier pour que le questionnaire fonctionne.
     *
     * Ce n'est PAS le protocole appliqué — le sélecteur les trouve tous par leur contexte, et un
     * questionnaire régional s'ajoute sans une ligne de code (acquis de P10b-2). C'est un repère
     * d'exploitation, comme `ServiceNiveauTriage::CODE`.
     */
    public const CODE = 'TRIAGE-QUESTIONNAIRE';

    public function __construct(
        private readonly SelecteurProtocoles $selecteur,
        private readonly CompilateurProtocole $compilateur,
        private readonly DiffusionProtocole $diffusion,
    ) {}

    /**
     * Y a-t-il au moins un questionnaire en vigueur ?
     *
     * NE REPLIE PAS ET NE LÈVE PAS — vérité brute pour un écran d'exploitation (motif
     * `ServiceNumerosUrgence::estEnVigueur()`, P6.8e).
     */
    public function estEnVigueur(): bool
    {
        return $this->selecteur
            ->selectionner(RegistreContextesProtocole::TRIAGE_QUESTIONNAIRE)['retenus'] !== [];
    }

    /**
     * Les questions à poser maintenant, compte tenu de ce que l'on sait déjà.
     *
     * C'est UN tour, pas la boucle entière : le client répond, rappelle, et ainsi de suite jusqu'à
     * ce que la liste soit vide. La boucle vit chez l'appelant parce que chaque tour suppose une
     * interaction humaine — la dérouler ici demanderait de deviner les réponses.
     *
     * ═══ TOUTES LES QUESTIONS DÉBLOQUÉES SONT RENDUES, PAS UNE SEULE ═══
     *
     * Le §4.3b dessine un arbre, ce qui pourrait suggérer une question à la fois. Mais chaque tour
     * coûte un aller-retour réseau, et la règle de frontière interdit de compiler l'arbre côté
     * client (ce serait une règle médicale dans le front). Rendre tout ce qui est déblocable
     * converge en quelques tours au lieu d'un par question — le coût est réduit, pas déguisé.
     *
     * @param  array<string, mixed>  $faits  Les faits du patient (symptômes, âge, sexe…).
     * @param  array<string, mixed>  $reponses  Les réponses déjà obtenues, indexées par clé.
     * @return array{questions: array<int, array<string, mixed>>, termine: bool, protocoles: array<int, array<string, mixed>>}
     */
    public function prochainesQuestions(array $faits, array $reponses = []): array
    {
        $evaluation = $this->evaluer($faits, $reponses);
        $questions = $this->compilateur->questionsDe($evaluation['instantane']);

        $aPoser = [];

        foreach ($evaluation['actions'] as $action) {
            if ($action['type'] !== RegistreActionsProtocole::POSER_QUESTION) {
                continue;
            }

            $cle = (string) $action['valeur'];

            // Déjà répondue : on ne la repose pas. Sans cette garde la boucle ne convergerait
            // jamais — une règle qui pose une question reste vraie après qu'on y a répondu.
            if (array_key_exists($cle, $reponses)) {
                continue;
            }

            // Le contrôle qualité refuse à la publication une règle qui pose une question
            // inexistante. Ce second filtre existe parce qu'un instantané publié AVANT ce
            // durcissement resterait en vigueur : mieux vaut ne rien demander que renvoyer au
            // client une clé qu'aucun écran ne sait rendre.
            if (! isset($questions[$cle]) || isset($aPoser[$cle])) {
                continue;
            }

            $aPoser[$cle] = $questions[$cle];
        }

        return [
            'questions' => array_values($aPoser),
            'termine' => $aPoser === [],
            'protocoles' => $evaluation['protocoles'],
        ];
    }

    /**
     * L'impact des réponses sur le score — UNE évaluation, sur le jeu complet.
     *
     * @param  array<string, mixed>  $faits
     * @param  array<string, mixed>  $reponses  Réponses déjà NORMALISÉES par {@see normaliser()}.
     * @return array{points: int, plancher: int|null, lignes: array<int, array<string, mixed>>, protocoles: array<int, array<string, mixed>>, regles_declenchees: array<int, array<string, mixed>>}
     */
    public function impact(array $faits, array $reponses): array
    {
        $evaluation = $this->evaluer($faits, $reponses);
        $questions = $this->compilateur->questionsDe($evaluation['instantane']);

        $points = 0;

        foreach ($evaluation['actions'] as $action) {
            if ($action['type'] === RegistreActionsProtocole::AJOUTER_SCORE) {
                $points += (int) $action['valeur'];
            }
        }

        // ═══ LE PLANCHER TRAVERSE LA FRONTIÈRE ENTRE LES DEUX PROTOCOLES, ET C'EST VOULU ═══
        //
        // `DEFINIR_SCORE_MINIMUM` est consommé par le moteur : il relève `score` pour les règles
        // suivantes. Un questionnaire qui l'utilise pour dire « cette réponse prime » doit voir son
        // plancher remonter jusqu'au protocole de niveau, sinon l'action serait sans effet et le
        // drapeau rouge d'une réponse disparaîtrait en silence — ce que faisait l'ancien
        // `drapeau_rouge_si_vrai`, en pire.
        //
        // Ce n'est PAS le chaînage entre protocoles que P10b-2 interdit : ce n'est pas le moteur
        // qui transporte un fait d'un protocole à l'autre au sein d'une évaluation, c'est ce
        // service qui ASSEMBLE un fait avant de lancer l'évaluation suivante — ce que
        // `TriageService` fait déjà avec les poids des symptômes depuis P10b-1.
        //
        // La lecture est exacte parce que l'évaluation part de `score = 0`
        // ({@see faitsAvecReponses()}) : ce qu'on lit est le plancher lui-même, et non un maximum
        // pollué par une valeur d'entrée. Et elle vient de {@see evaluer()}, qui évalue les
        // protocoles directement — le sélecteur, lui, rendait les faits INITIAUX faute d'action
        // exclusive à retenir, et le plancher valait toujours 0.
        $plancher = (int) $evaluation['plancher'];

        $lignes = [];

        foreach ($reponses as $cle => $valeur) {
            if (! isset($questions[$cle])) {
                continue;
            }

            $lignes[] = [
                'cle' => $cle,
                // Le libellé est celui de la version PUBLIÉE au moment du triage, figé ensuite
                // dans `triage_reponses`. Republier le questionnaire ne réécrira pas ce que ce
                // patient a lu (motif P6.6b, P7-D2, P10a).
                'libelle' => (string) $questions[$cle]['libelle'],
                'valeur' => $valeur,
            ];
        }

        return [
            'points' => $points,
            'plancher' => $plancher,
            'lignes' => $lignes,
            'protocoles' => $evaluation['protocoles'],
            'regles_declenchees' => $evaluation['regles_declenchees'],
        ];
    }

    /**
     * Confronte les réponses brutes du client à la version PUBLIÉE, et refuse ce qui n'y entre pas.
     *
     * ═══ CE QUE CETTE MÉTHODE REFERME (constat X4 du G0) ═══
     *
     * Le référentiel publiait `min:1 max:10` et le serveur ne les regardait pas :
     * `intensite = 100` × `coef 1.2` produisait 120 points, le score saturait à 100, et le patient
     * obtenait le niveau le plus urgent avec une valeur hors de la plage publiée. Une clé inconnue,
     * elle, valait 0 point **en silence**.
     *
     * ═══ ON REFUSE, ON N'ÉCRÊTE PAS ═══
     *
     * Ramener 100 à 10 accepterait une saisie fausse en la corrigeant sans le dire : le patient
     * croirait avoir répondu 100, et son dossier porterait 10. Le refus nomme la question — motif
     * des refus « par leur motif » de P6.5b et P6.8e.
     *
     * @param  array<int, array<string, mixed>>  $brutes  [{cle, valeur}, …]
     * @return array<string, mixed> Réponses indexées par clé, typées.
     *
     * @throws ValidationException
     */
    public function normaliser(array $brutes): array
    {
        $questions = $this->compilateur->questionsDe($this->instantaneCourant());
        $normalisees = [];
        $erreurs = [];

        foreach ($brutes as $index => $brute) {
            $cle = (string) ($brute['cle'] ?? '');
            $valeur = $brute['valeur'] ?? null;
            $champ = "reponses.{$index}.valeur";

            if (! isset($questions[$cle])) {
                $erreurs["reponses.{$index}.cle"][] = "La question « {$cle} » ne fait pas partie de "
                    .'la version en vigueur du questionnaire.';

                continue;
            }

            $question = $questions[$cle];

            // Une réponse vide n'est pas une erreur : le questionnaire est facultatif depuis le
            // Module 1 (« Tout est facultatif »), et cet incrément ne change pas ce contrat.
            if ($valeur === null || $valeur === '') {
                continue;
            }

            $resultat = $this->normaliserUne($question, $valeur);

            if (is_string($resultat)) {
                $erreurs[$champ][] = $resultat;

                continue;
            }

            $normalisees[$cle] = $resultat['valeur'];
        }

        if ($erreurs !== []) {
            throw ValidationException::withMessages($erreurs);
        }

        return $normalisees;
    }

    /**
     * Une réponse, confrontée à SA définition publiée.
     *
     * @param  array<string, mixed>  $question
     * @return array{valeur: mixed}|string La valeur typée, ou le message de refus.
     */
    private function normaliserUne(array $question, mixed $valeur): array|string
    {
        $libelle = (string) $question['libelle'];

        return match ((string) $question['type']) {
            'booleen' => ['valeur' => filter_var($valeur, FILTER_VALIDATE_BOOLEAN)],

            'nombre' => is_numeric($valeur)
                ? ['valeur' => (int) $valeur]
                : "« {$libelle} » attend un nombre.",

            'echelle' => $this->normaliserEchelle($question, $valeur, $libelle),

            // Le §4.4 veut des codes, pas du texte libre : une valeur hors des réponses possibles
            // ne serait comparée à rien et marquerait 0 point sans bruit — le défaut X5, dans
            // l'autre sens.
            'choix' => $this->estUneReponsePossible($question, $valeur)
                ? ['valeur' => (string) $valeur]
                : "« {$libelle} » : « {$valeur} » ne fait pas partie des réponses proposées "
                    .'('.implode(', ', $this->valeursPossibles($question)).').',

            default => "« {$libelle} » : type de question inconnu.",
        };
    }

    /**
     * @param  array<string, mixed>  $question
     * @return array{valeur: int}|string
     */
    private function normaliserEchelle(array $question, mixed $valeur, string $libelle): array|string
    {
        if (! is_numeric($valeur)) {
            return "« {$libelle} » attend un nombre.";
        }

        $entier = (int) $valeur;
        $min = $question['valeur_min'];
        $max = $question['valeur_max'];

        // Le contrôle qualité refuse de publier une échelle sans bornes. Si l'on arrive ici sans
        // elles, c'est un instantané publié avant ce durcissement : on ne peut rien opposer, et
        // l'inventer serait pire que de laisser passer.
        if ($min === null || $max === null) {
            return ['valeur' => $entier];
        }

        if ($entier < (int) $min || $entier > (int) $max) {
            return "« {$libelle} » : la réponse doit être comprise entre {$min} et {$max} "
                ."(reçu {$entier}). La valeur n'est pas ramenée dans la plage : votre dossier "
                .'porterait alors une réponse que vous n\'avez pas donnée.';
        }

        return ['valeur' => $entier];
    }

    /** @param  array<string, mixed>  $question */
    private function estUneReponsePossible(array $question, mixed $valeur): bool
    {
        return in_array((string) $valeur, $this->valeursPossibles($question), true);
    }

    /**
     * @param  array<string, mixed>  $question
     * @return array<int, string>
     */
    private function valeursPossibles(array $question): array
    {
        return array_map(
            static fn (array $r): string => (string) $r['valeur'],
            $question['reponses'] ?? [],
        );
    }

    /**
     * Une évaluation du ou des questionnaires en vigueur.
     *
     * @param  array<string, mixed>  $faits
     * @param  array<string, mixed>  $reponses
     * @return array{actions: array<int, array<string, mixed>>, faits: array<string, mixed>, instantane: array<string, mixed>, protocoles: array<int, array<string, mixed>>, regles_declenchees: array<int, array<string, mixed>>}
     */
    /**
     * ═══ POURQUOI CE SERVICE N'UTILISE PAS `SelecteurProtocoles::evaluer()` ═══
     *
     * Il l'a fait, et **un vecteur a montré que c'était faux**. Le sélecteur ne restitue les faits
     * issus du chaînage avant que **du protocole RETENU** — et « retenu » veut dire : celui qui a
     * emporté une action **exclusive**. Or un questionnaire n'en produit aucune (ni
     * `POSER_QUESTION` ni `AJOUTER_SCORE` ne sont exclusives, et c'est voulu). Il n'y avait donc
     * jamais de protocole retenu, le sélecteur rendait les faits **initiaux**, et le plancher posé
     * par `DEFINIR_SCORE_MINIMUM` valait toujours 0 : *le drapeau rouge d'une réponse était perdu
     * en silence*, ce que cet incrément était précisément censé refermer.
     *
     * Ce n'est pas un défaut de P10b-2 : son comportement est juste pour SON consommateur, qui
     * affiche un score à côté du niveau que ce même protocole a décidé — « prendre ceux d'un autre
     * protocole afficherait un score qui contredit la décision ».
     *
     * On évalue donc ici, sur les mêmes instantanés publiés (cache partagé), et l'on cumule
     * soi-même. **Rien n'est perdu** : la cascade du §3 sert à DÉPARTAGER des actions exclusives, et
     * un questionnaire n'en a pas — deux questionnaires qui posent des questions différentes
     * s'additionnent, ils ne se contredisent pas. La sélection (contexte, pays, expiration, ordre
     * §3) reste celle du sélecteur, via {@see retenus()}.
     *
     * @param  array<string, mixed>  $faits
     * @param  array<string, mixed>  $reponses
     * @return array{actions: array<int, array<string, mixed>>, plancher: int, instantane: array<string, mixed>, protocoles: array<int, array<string, mixed>>, regles_declenchees: array<int, array<string, mixed>>}
     */
    private function evaluer(array $faits, array $reponses): array
    {
        $faits = $this->faitsAvecReponses($faits, $reponses);

        $actions = [];
        $declenchees = [];
        $protocoles = [];
        $questions = [];
        $vues = [];
        $plancher = 0;

        foreach ($this->retenus() as $candidat) {
            $publie = $this->diffusion->lire((string) $candidat['code']);

            $resultat = MoteurProtocole::evaluer($publie['contenu']['regles'] ?? [], $faits);

            foreach ($resultat['actions'] as $action) {
                $actions[] = $action;
            }

            foreach ($resultat['regles_declenchees'] as $regle) {
                $declenchees[] = $regle;
            }

            // ═══ LE PLANCHER SE PREND AU MAXIMUM, ET C'EST LA SÉMANTIQUE DÉJÀ DÉCLARÉE ═══
            //
            // P10b-2 l'écrit noir sur blanc en excluant `DEFINIR_SCORE_MINIMUM` des actions
            // exclusives : « deux planchers ne se contredisent pas, le plus haut s'applique ».
            // On ne fait qu'appliquer cette règle, sans en inventer une.
            $plancher = max($plancher, (int) ($resultat['faits']['score'] ?? 0));

            $protocoles[] = [
                'code' => $publie['code'],
                'version' => $publie['version'],
                'numero' => $publie['numero'],
            ];

            foreach ($publie['contenu']['questions'] ?? [] as $question) {
                $cle = (string) ($question['cle'] ?? '');

                if ($cle !== '' && ! isset($vues[$cle])) {
                    $vues[$cle] = true;
                    $questions[] = $question;
                }
            }
        }

        return [
            'actions' => $actions,
            'plancher' => $plancher,
            'instantane' => ['questions' => $questions],
            'protocoles' => $protocoles,
            'regles_declenchees' => $declenchees,
        ];
    }

    /**
     * Les faits du patient enrichis des réponses, sous le nom que les conditions leur donnent.
     *
     * @param  array<string, mixed>  $faits
     * @param  array<string, mixed>  $reponses
     * @return array<string, mixed>
     */
    private function faitsAvecReponses(array $faits, array $reponses): array
    {
        foreach ($reponses as $cle => $valeur) {
            $faits[RegistreFaitsProtocole::PREFIXE_REPONSE.$cle] = $valeur;
        }

        // ═══ LE QUESTIONNAIRE PART D'UN SCORE À ZÉRO, ET CE N'EST PAS UN OUBLI ═══
        //
        // Au moment où l'on interroge le patient, le score n'est PAS ENCORE CLOS : c'est ce
        // questionnaire qui l'alimente. Lui passer un score partiel ferait juger une règle sur un
        // total qui n'existe pas encore, et le résultat dépendrait du moment de l'évaluation
        // plutôt que des faits — la même famille de défaut que celui refermé par R5 sur le nombre
        // de tours.
        //
        // Un rédacteur qui veut conditionner sur ce qui est DÉJÀ connu dispose de
        // `score_symptomes` et `score_antecedents`, qui sont clos, eux. Le contrôle qualité refuse
        // d'ailleurs `score` dans un protocole de questionnaire, en nommant ces deux-là.
        //
        // Conséquence directe : `faits['score']` après évaluation ne contient que les planchers
        // posés par `DEFINIR_SCORE_MINIMUM`, ce qui rend leur lecture exacte dans `impact()`.
        $faits['score'] = 0;

        return $faits;
    }

    /**
     * L'instantané des questions en vigueur, fusionné si plusieurs questionnaires cohabitent.
     *
     * ═══ POURQUOI UNE FUSION ET NON UN ARBITRAGE ═══
     *
     * Deux questionnaires qui posent des questions différentes ne se contredisent pas : ils
     * s'additionnent, comme deux protocoles qui orientent vers deux spécialités (P10a, puis
     * `EXCLUSIVES` en P10b-2). En cas de clé identique, le premier retenu par la cascade du §3
     * l'emporte — c'est l'ordre que le sélecteur a déjà établi, on ne le refait pas ici.
     *
     * ═══ LE CONTENU VIENT DE LA DIFFUSION, PAS DE LA SÉLECTION ═══
     *
     * `selectionner()` ne rend que de quoi trier (code, niveau de source, preuve, expiration) ;
     * le contenu publié se lit par `DiffusionProtocole::lire()`, qui sert l'instantané **mis en
     * cache à clé versionnée**. C'est la même lecture que fait le sélecteur pour évaluer : un
     * `UPDATE` direct sur `protocole_questions` ne change donc rien tant qu'on n'a pas republié.
     *
     * @return array<string, mixed>
     */
    private function instantaneCourant(): array
    {
        $questions = [];
        $vues = [];

        foreach ($this->retenus() as $candidat) {
            $publie = $this->diffusion->lire((string) $candidat['code']);

            foreach ($publie['contenu']['questions'] ?? [] as $question) {
                $cle = (string) ($question['cle'] ?? '');

                if ($cle !== '' && ! isset($vues[$cle])) {
                    $vues[$cle] = true;
                    $questions[] = $question;
                }
            }
        }

        return ['questions' => $questions];
    }

    /**
     * Les questionnaires en vigueur, ou refus bruyant.
     *
     * @return array<int, array<string, mixed>>
     */
    private function retenus(): array
    {
        $retenus = $this->selecteur
            ->selectionner(RegistreContextesProtocole::TRIAGE_QUESTIONNAIRE)['retenus'];

        if ($retenus === []) {
            $this->refuser();
        }

        return $retenus;
    }

    /**
     * @return never
     */
    private function refuser(): void
    {
        // 503 et non 404 : le questionnaire existe peut-être en brouillon, c'est sa MISE EN VIGUEUR
        // qui manque. Même distinction qu'en P10a pour les symptômes et qu'en P10b-1 pour le
        // niveau.
        abort(503, 'Aucun questionnaire de triage n\'est en vigueur : aucune question ne peut être '
            .'posée tant qu\'une version n\'a pas franchi les quatre validations du §7 et n\'a pas '
            .'été publiée (CDC_08 §1.6).');
    }
}
