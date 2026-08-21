<?php

namespace App\Services;

use App\Models\Symptome;
use App\Services\Protocole\MessagesProtocole;
use App\Services\Triage\FaitsTriage;
use App\Services\Triage\ServiceNiveauTriage;
use App\Services\Triage\ServicePlafondAntecedents;
use App\Services\Triage\ServiceQuestionnaire;
use App\Services\Triage\ServiceSymptomesTriage;
use App\Support\NiveauTriage;
use App\Support\RegistreActionsProtocole;
use App\Support\ReglesOrientation;
use Illuminate\Support\Collection;

/**
 * TriageService — assemblage du triage médical (Module 1, F1.3 / §5.1.3 ; CDC_08 §1.2).
 *
 * ═══ CE COMMENTAIRE DISAIT AUTRE CHOSE JUSQU'À P10b-1, ET IL FAUT LE DIRE ═══
 *
 * Il annonçait trois niveaux et leurs bandes : « LÉGER : 0 à 30 · MODÉRÉ : 31 à 65 · URGENT :
 * 66 à 100 », et « tout symptôme critique force immédiatement le niveau URGENT ». C'était exact,
 * et c'était le problème : **ces seuils étaient dans ce fichier**. CDC_08 §1.2 les y interdit, et
 * CDC_05 §5.3 en exige quatre, pas trois.
 *
 * Ce service ne décide plus d'aucun niveau. Il **assemble des faits** — poids des symptômes,
 * impact du questionnaire, impact des antécédents — et les remet au protocole en vigueur
 * ({@see ServiceNiveauTriage}), qui rend le verdict. Même séparation que `ReglesOrientation`
 * (P10a) et `ReglesCalendrierVaccinal` (P6.8b), poussée d'un cran : la règle ne vit même plus
 * dans une classe PHP, elle vit en base, versionnée et signée par quatre validateurs (§7).
 *
 * Ce qui reste ici est de l'arithmétique — une somme et deux bornes — et le plafond des
 * antécédents, seul seuil survivant, annoncé comme limite avec son porteur (**P10b-3-ii**).
 *
 * Les poids et drapeaux des symptômes vivent dans le référentiel gouverné, lu en version publiée
 * depuis P10a ({@see ServiceSymptomesTriage}).
 *
 * ═══ P10b-3-i — LES QUESTIONS N'Y SONT PLUS ═══
 *
 * Ce commentaire annonçait « les poids, **questions** et drapeaux » ensemble. Les questions ont
 * déménagé dans le protocole `TRIAGE-QUESTIONNAIRE` ({@see ServiceQuestionnaire}), parce que
 * l'impact d'une réponse est une règle qui décide de l'urgence et devait passer sous les quatre
 * validations du §7 — pas sous les deux signatures administratives du §10.
 *
 * `symptomes.questions_complementaires_json` reste en base et n'est plus ni publiée ni lue
 * (ADR-024 ; précédents `specialite_hint` en P10a, `vaccinations.statut` en P6.8b).
 */
class TriageService
{
    /**
     * P6.8e — LE NUMÉRO N'EST PLUS UNE CONSTANTE DE CE SERVICE.
     *
     * Il vivait ici depuis le Module 1 (`NUMERO_SAMU = '185'`), et en double dans le mobile. CDC_02
     * §37 l'interdit nommément : « rien en dur — **y compris les numéros d'urgence** ». Il est
     * désormais lu au référentiel national publié, avec un repli déclaré et journalisé
     * ({@see ServiceNumerosUrgence}).
     *
     * Ce service ne décide toujours de rien sur ce point : il insère une donnée dans un texte.
     */
    /**
     * P10a — LES SYMPTÔMES NE VIENNENT PLUS DE LA TABLE.
     *
     * `Symptome::actif()->whereIn(...)` lisait la table de travail : un `UPDATE` direct changeait le
     * triage sans version, sans trace et sans relecture. C'est le constat F3 du G0 de P6.3, et la
     * limite L1 d'ADR-025 dont L1+L2 avait refermé l'autre moitié. Le service ci-dessous lit la
     * **version publiée** et refuse bruyamment tant qu'il n'y en a pas.
     */
    /**
     * P10b-1 — LE NIVEAU N'EST PLUS DÉCIDÉ ICI.
     *
     * Ce service portait `niveauDepuisScore()` — un `match` sur trois seuils — et
     * `if ($drapeauRouge) { $score = max($score, 90); }`. CDC_08 §1.2 donne son contre-exemple
     * littéral (`if temperature > 39: urgence = True`) : c'étaient des **règles médicales en dur**
     * décidant du niveau d'urgence d'un citoyen. Elles vivent désormais dans le protocole
     * `TRIAGE-NIVEAU`, relu et signé par quatre validateurs (§7).
     *
     * `ServiceNumerosUrgence` a disparu des dépendances : le numéro n'est plus inséré ici mais
     * résolu dans le message du protocole ({@see MessagesProtocole}), pour que la consigne reste au
     * protocole et le numéro au référentiel.
     */
    /**
     * P10b-3-i — LE QUESTIONNAIRE N'EST PLUS CALCULÉ ICI.
     *
     * `evaluerReponses()` portait quatre `elseif` appliquant un blob JSON du référentiel des
     * symptômes : `['points_si_vrai' => 15, 'drapeau_rouge_si_vrai' => true]` — le contre-exemple
     * littéral du §1.2, gouverné par deux signatures administratives mais **jamais validé** par
     * les quatre du §7. C'était la dernière règle médicale que P10b-1 avait laissée derrière lui.
     *
     * L'impact d'une réponse est désormais une règle de protocole ({@see ServiceQuestionnaire}).
     */
    public function __construct(
        private readonly ServiceSymptomesTriage $symptomes,
        private readonly ServiceNiveauTriage $protocole,
        private readonly ServiceQuestionnaire $questionnaire,
        private readonly ServicePlafondAntecedents $plafond,
        private readonly MessagesProtocole $messages,
    ) {}

    /**
     * P10b-3-ii — LE DERNIER SEUIL DE CE SERVICE A QUITTÉ LE CODE.
     *
     * `PLAFOND_ANTECEDENTS = 20` bornait la part des antécédents. Il ne décidait d'aucun niveau,
     * mais il pesait sur l'urgence : c'était la réponse à « quel poids une déclaration NON VÉRIFIÉE
     * du patient peut-elle avoir ? ». Une décision clinique, désormais relue et signée par quatre
     * validateurs ({@see ServicePlafondAntecedents}).
     *
     * Ce service ne porte plus aucun seuil. Ce qui reste est de l'arithmétique — une somme et deux
     * bornes d'échelle — et l'ORDRE des phases, qui ne dépend d'aucune donnée.
     */

    /**
     * Analyse un triage et renvoie le résultat complet (sans persistance).
     *
     * @param  array  $symptomesIds  IDs des symptômes sélectionnés.
     * @param  array<string, mixed>  $reponses  Réponses NORMALISÉES, indexées par clé de question
     *                                          ({@see ServiceQuestionnaire::normaliser()}).
     * @param  int|null  $age  Âge du patient (repli pédiatrique de l'orientation).
     * @param  string|null  $sexe  'M' ou 'F' (restriction d'orientation).
     * @param  array  $antecedents  [{libelle, impact_triage}, ...] (carnet Module 2).
     */
    public function analyser(
        array $symptomesIds,
        array $reponses = [],
        ?int $age = null,
        ?string $sexe = null,
        array $antecedents = []
    ): array {
        /** @var Collection<int,Symptome> $symptomes */
        $symptomes = $this->symptomes->retenus($symptomesIds);

        // ═══ 1) LES FAITS SONT ASSEMBLÉS EN UN SEUL ENDROIT ═══
        //
        // Ils l'étaient en trois exemplaires — ici deux fois, et une troisième dans
        // `TriageController::questions()`. Constat Z1 du G0 : `score_antecedents` était passé par
        // les deux sites du service et PAS par celui du contrôleur, si bien qu'une règle de
        // questionnaire qui s'en serait servie aurait fait tomber un endpoint sur deux (un fait
        // inconnu lève, depuis P10b-1).
        $base = FaitsTriage::base($symptomes, $age, $sexe);

        $scoreSymptomes = (int) $base['score_symptomes'];
        $drapeauRouge = (bool) $base['drapeau_rouge'];

        // ═══ 2) LA PART DES ANTÉCÉDENTS EST DÉCIDÉE PAR UN PROTOCOLE, PLUS PAR UNE CONSTANTE ═══
        //
        // Les faits des antécédents ne sont PAS passés au questionnaire (phase 3) : celui-ci
        // s'exécute aussi depuis `POST /triage/questions`, où le membre — donc son carnet — est
        // inconnu. Une règle de questionnaire qui en dépendrait répondrait différemment selon
        // l'endpoint ; le contrôle qualité le refuse, et cette séparation le rend vérifiable.
        $partAntecedents = $this->plafond->part(FaitsTriage::avecAntecedents($base, $antecedents));
        $scoreAntecedents = $partAntecedents['valeur'];

        // ═══ 3) LE QUESTIONNAIRE EST UNE PHASE, ÉVALUÉE AVANT QUE LE SCORE NE SOIT CLOS ═══
        //
        // Ce n'est PAS le chaînage entre protocoles que P10b-2 interdit — celui-là vise le moteur
        // transportant un fait d'un protocole à l'autre au sein d'une évaluation. Ici c'est ce
        // service qui ASSEMBLE, en phases, exactement comme il assemblait déjà le poids des
        // symptômes avant d'appeler le protocole de niveau.
        //
        // L'ordre des phases est fixe et ne dépend d'aucune donnée : questionnaire → assemblage
        // du score → protocole de niveau. Deux protocoles distincts, deux cycles de validation
        // distincts (§6.1, §7) : corriger un énoncé de question ne fait pas re-signer les seuils
        // de niveau par quatre validateurs, et l'inverse.
        $impact = $this->questionnaire->impact($base, $reponses);

        // 4) Score total borné à [0, 100]. Arithmétique, pas décision : le §1.2 vise les seuils
        //    et les conclusions, pas l'addition de trois nombres.
        $score = max(0, min(100, $scoreSymptomes + $impact['points'] + $scoreAntecedents));

        // ═══ LE PLANCHER POSÉ PAR UNE RÉPONSE REMONTE JUSQU'ICI ═══
        //
        // C'est ce qui remplace `drapeau_rouge_si_vrai` : une réponse critique ne lève plus un
        // booléen caché, elle relève le score par `DEFINIR_SCORE_MINIMUM` — la même action que
        // P10b-1 a créée pour le drapeau rouge des symptômes. Une seule façon de dire « ceci
        // prime », au lieu de deux qui pouvaient diverger.
        //
        // Le fait `drapeau_rouge` conserve donc exactement le sens que le registre lui donne
        // (« au moins un symptôme OU une réponse critique a été signalé ») : sans cette ligne, il
        // se serait rétréci aux symptômes sans que son libellé change — un libellé qui ment.
        $score = max($score, $impact['plancher']);
        $drapeauRouge = $drapeauRouge || $impact['plancher'] > 0;

        // ═══ 5) LE PROTOCOLE DÉCIDE — CE SERVICE NE DÉCIDE PLUS ═══
        //
        // Les faits sont ASSEMBLÉS ici et JUGÉS ailleurs. C'est la même séparation que
        // `ReglesOrientation` (P10a) et `ReglesCalendrierVaccinal` (P6.8b) : le service rassemble
        // l'état, la règle rend le verdict. Ici la règle ne vit même plus dans une classe PHP —
        // elle vit en base, versionnée et signée.
        // `array_merge` et NON l'union `+` : sur une clé commune, l'union garde la valeur de
        // GAUCHE. `drapeau_rouge` est présent dans la base (symptômes seuls) et vient d'être
        // relevé par le plancher d'une réponse — avec `+`, cette mise à jour serait ignorée en
        // silence, et le drapeau rouge d'une réponse disparaîtrait pour la seconde fois.
        $decision = $this->protocole->appliquer(array_merge(
            FaitsTriage::avecAntecedents($base, $antecedents),
            [
                'score' => $score,
                'score_reponses' => $impact['points'],
                'score_antecedents' => $scoreAntecedents,
                'drapeau_rouge' => $drapeauRouge,
            ],
        ));

        $niveau = $decision['niveau'];

        // Le score APRÈS chaînage avant : c'est celui que le protocole a réellement utilisé. Le
        // plancher du drapeau rouge est désormais une action de règle, plus une ligne de PHP.
        $score = $decision['score'];

        // 6) Orientation (§5.1.3) puis texte de recommandation.
        $codes = $this->orienter($symptomes, $age, $sexe);

        // Les codes, accompagnés de leur libellé — c'est ce que le mobile affiche et ce dont il se
        // sert pour chercher les établissements (D3 : le serveur renvoie les deux).
        $specialites = array_map(fn (string $code): array => [
            'code' => $code,
            'libelle' => $code === ServiceSymptomesTriage::CODE_PEDIATRIE
                ? $this->symptomes->libellePediatrie()
                : $this->symptomes->libelle($code),
        ], $codes);

        // ═══ `specialite_requise` SURVIT, ET CE N'EST PAS DE LA COMPLAISANCE ═══
        //
        // La colonne existe depuis le Module 1 et l'historique la porte : la réécrire changerait ce
        // qu'un patient a réellement lu. Elle reste **l'affichage principal** — le libellé de la
        // première orientation — pendant que `specialites_json` devient la donnée. Même partage
        // qu'entre `vaccinations.statut` et le calcul (P6.8b) : la colonne dit ce qu'on a montré,
        // la donnée dit ce qu'on a décidé.
        $specialite = $specialites[0]['libelle'] ?? null;
        $recommandation = $this->construireRecommandation($niveau, $specialite, $decision['actions']);

        return [
            'score_severite' => $score,
            'niveau' => $niveau,
            'niveau_libelle' => NiveauTriage::libelle($niveau),
            'specialite_requise' => $specialite,
            'specialites' => $specialites,
            'referentiel_version' => $this->symptomes->version(),

            // ═══ L'ESTAMPILLE DU §6.1 — EXIGENCE MÉDICO-LÉGALE NON NÉGOCIABLE ═══
            //
            // « Chaque décision clinique conserve la version exacte du protocole utilisée ». Sans
            // elle, corriger une bande de score rendrait tout triage antérieur inexplicable —
            // c'était le constat F3 du G0 de P6.3 pour les référentiels, ici pour les protocoles.
            'protocole_code' => $decision['protocole']['code'],
            'protocole_version' => $decision['protocole']['numero'],
            'protocole_libelle' => $decision['protocole']['version'],

            // Ce que le protocole a réellement déclenché. §9.1 attend une « justification » et §10
            // « les recommandations affichées » : sans la trace des règles, une recommandation
            // serait une affirmation sans origine.
            'regles_declenchees' => $decision['regles_declenchees'],

            // P10b-2 — L'ÉVALUATION COMPLÈTE, transmise telle quelle au journal du §10 :
            // protocoles évalués, protocoles écartés et leur motif, divergences et le critère
            // qui les a départagées. Ce service ne l'interprète pas — il ne saurait pas quoi en
            // faire, et lui donner un sens ici mettrait une décision de gouvernance dans un
            // assembleur de score.
            'evaluation' => $decision['evaluation'],

            'recommandation_texte' => $recommandation,
            'drapeau_rouge' => $drapeauRouge,
            'symptomes' => $symptomes->map(fn (Symptome $s) => [
                'id' => $s->id,
                'nom' => $s->nom_fr,
                'poids' => $s->poids_severite,
            ])->values()->all(),
            // Les réponses avec leur ÉNONCÉ tel que la version publiée le portait au moment du
            // triage. C'est ce libellé que `triage_reponses` fige (§115) : republier le
            // questionnaire ne doit pas réécrire ce que ce patient a lu.
            'reponses' => $impact['lignes'],

            // Le protocole de QUESTIONNAIRE, distinct de celui de niveau. Les confondre rendrait
            // un triage inexplicable dès que l'un des deux évolue — ils ont des cycles séparés.
            'questionnaire' => $impact['protocoles'],

            'details_score' => [
                'symptomes' => $scoreSymptomes,
                'reponses' => $impact['points'],
                'antecedents' => $scoreAntecedents,
            ],
        ];
    }

    /**
     * P10a — L'orientation (§5.1.3), agrégée à partir des ANNOTATIONS publiées des symptômes.
     *
     * ═══ CE QUE CETTE MÉTHODE FAISAIT AVANT, ET POURQUOI C'ÉTAIT UN DÉFAUT ═══
     *
     * Elle comparait des SOUS-CHAÎNES d'un libellé libre :
     *
     *     ->reject(fn ($h) => str_contains(mb_strtolower($h), 'gyn') && $sexe !== 'F')
     *     $hints->first(fn ($h) => str_contains($h, 'urgenc') || str_contains($h, 'cardio'))
     *     if ($age < 15) return 'Pédiatrie';
     *
     * Trois **règles médicales en dur** (CDC_00 §4) que personne n'avait vues parce qu'elles ne
     * ressemblent pas à des règles — et qui portaient sur du texte modifiable : écrire « Urgence »
     * au singulier aurait supprimé la priorité **sans que rien ne le signale**. Un libellé rendait
     * du même coup impossible de représenter « Cardiologie / Urgences » autrement que par une
     * chaîne dans laquelle il fallait ensuite chercher.
     *
     * Désormais : le `rang` porte la priorité, `sexe_requis` porte la restriction, et les deux sont
     * des **données publiées** relues par deux agents (§10). Le repli pédiatrique reste une règle,
     * mais son code et son seuil sont **fournis**, jamais enfouis.
     *
     * ═══ L'ORIENTATION N'EST PAS UNE DÉDUCTION ═══
     *
     * Le triage ne « trouve » pas la spécialité : chaque symptôme porte, en base, celles vers
     * lesquelles il oriente. Ce service ne fait que fusionner et ordonner. C'est le bon niveau —
     * CDC_05 §1 : *« le triage n'est jamais un diagnostic »*. D'où le renommage : on n'en **déduit**
     * plus rien, on **agrège**.
     *
     * @return array<int, string> les codes retenus, du plus pertinent au moins pertinent
     */
    private function orienter(Collection $symptomes, ?int $age, ?string $sexe): array
    {
        return ReglesOrientation::agreger(
            orientations: $this->symptomes->orientationsDe($symptomes),
            sexe: $sexe,
            age: $age,
            codePediatrie: $this->symptomes->codePediatrie(),
            agePediatrie: ServiceSymptomesTriage::AGE_PEDIATRIE,
        );
    }

    /**
     * Le texte affiché au patient — VENU DU PROTOCOLE, plus d'un `match` PHP.
     *
     * ═══ CE QUI A CHANGÉ, ET POURQUOI CE N'ÉTAIT PAS COSMÉTIQUE ═══
     *
     * Ce texte disait « orientez-vous vers une pharmacie », « consultez sans tarder », « rendez-vous
     * immédiatement aux urgences ». **C'est une consigne clinique**, et elle était écrite dans une
     * méthode privée : la corriger demandait un déploiement, et personne ne l'avait relue au titre
     * du §7. Elle vient maintenant de l'action `MESSAGE` du protocole.
     *
     * Le NUMÉRO, lui, reste au référentiel national (P6.8e, CDC_02 §37) : le protocole écrit
     * `{urgence:samu}` et {@see MessagesProtocole} le résout. Les deux se corrigent chacun par leur
     * porte — changer un numéro d'urgence n'a pas à repasser par quatre validations cliniques.
     *
     * ═══ LE REPLI EST UNE REFORMULATION, JAMAIS UN CONSEIL ═══
     *
     * Si le protocole ne dit rien, on énonce le niveau et on s'arrête. Inventer ici une consigne
     * de repli — « consultez un médecin » — reviendrait à remettre dans le code exactement ce qu'on
     * vient d'en sortir, et à le faire à l'endroit le moins visible. Le contrôle qualité exige de
     * toute façon un message par règle de niveau.
     *
     * @param  array<int, array<string, mixed>>  $actions
     */
    private function construireRecommandation(string $niveau, ?string $specialite, array $actions): string
    {
        $messages = [];

        foreach ($actions as $action) {
            if ($action['type'] === RegistreActionsProtocole::MESSAGE && $action['valeur'] !== null) {
                $messages[] = $this->messages->resoudre((string) $action['valeur']);
            }
        }

        $texte = $messages === []
            ? 'Niveau : '.NiveauTriage::libelle($niveau).'.'
            : implode(' ', $messages);

        if ($specialite) {
            $texte .= ' Spécialité recommandée : '.$specialite.'.';
        }

        return $texte;
    }
}
