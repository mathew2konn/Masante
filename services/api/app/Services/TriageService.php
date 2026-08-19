<?php

namespace App\Services;

use App\Models\Symptome;
use App\Services\Triage\ServiceSymptomesTriage;
use App\Services\Urgence\ServiceNumerosUrgence;
use App\Support\ReglesOrientation;
use Illuminate\Support\Collection;

/**
 * TriageService — algorithme de triage médical (Module 1, F1.3 / §5.1.2 / §5.1.3).
 *
 * Arbre de décision pondéré produisant un score 0-100 puis un niveau de soin :
 *   - LÉGER   : 0 à 30
 *   - MODÉRÉ  : 31 à 65
 *   - URGENT  : 66 à 100
 *
 * Le score = poids des symptômes + impact des réponses au questionnaire + impact des
 * antécédents (plafonné à 20). RÈGLE DRAPEAU ROUGE : tout symptôme ou réponse critique
 * force immédiatement le niveau URGENT, quel que soit le score.
 *
 * Les règles (poids, questions, drapeaux) vivent en base (table symptomes) et sont donc
 * modifiables sans redéployer (F1.3).
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
    public function __construct(
        private readonly ServiceNumerosUrgence $numeros,
        private readonly ServiceSymptomesTriage $symptomes,
    ) {}

    /** Plafond de l'impact des antécédents sur le score (prompt §7). */
    private const PLAFOND_ANTECEDENTS = 20;

    /**
     * Analyse un triage et renvoie le résultat complet (sans persistance).
     *
     * @param  array  $symptomesIds  IDs des symptômes sélectionnés.
     * @param  array  $reponses      [{symptome_id, cle, valeur}, ...]
     * @param  int|null    $age       Âge du patient (déduction pédiatrie).
     * @param  string|null $sexe      'M' ou 'F' (déduction gynécologie).
     * @param  array  $antecedents   [{libelle, impact_triage}, ...] (carnet Module 2, vide pour l'instant).
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

        // 1) Poids de base des symptômes + détection d'un drapeau rouge symptôme.
        $scoreSymptomes = (int) $symptomes->sum('poids_severite');
        $drapeauRouge = $symptomes->contains(fn (Symptome $s) => $s->drapeau_rouge === true);

        // 2) Impact du questionnaire (et drapeau rouge éventuel via une réponse critique).
        [$scoreReponses, $reponsesEvaluees, $drapeauReponse] = $this->evaluerReponses($symptomes, $reponses);
        $drapeauRouge = $drapeauRouge || $drapeauReponse;

        // 3) Impact des antécédents, plafonné à 20.
        $scoreAntecedents = min(
            (int) collect($antecedents)->sum('impact_triage'),
            self::PLAFOND_ANTECEDENTS
        );

        // 4) Score total borné à [0, 100].
        $score = max(0, min(100, $scoreSymptomes + $scoreReponses + $scoreAntecedents));

        // 5) Drapeau rouge => URGENT immédiat (et score relevé pour rester cohérent).
        if ($drapeauRouge) {
            $score = max($score, 90);
        }

        $niveau = $this->niveauDepuisScore($score);

        // 6) Orientation (§5.1.3) puis texte de recommandation.
        $codes = $this->orienter($symptomes, $age, $sexe);

        // Les codes, accompagnés de leur libellé — c'est ce que le mobile affiche et ce dont il se
        // sert pour chercher les établissements (D3 : le serveur renvoie les deux).
        $specialites = array_map(fn (string $code): array => [
            'code'    => $code,
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
        $recommandation = $this->construireRecommandation($niveau, $specialite);

        return [
            'score_severite'       => $score,
            'niveau'               => $niveau,
            'specialite_requise'   => $specialite,
            'specialites'          => $specialites,
            'referentiel_version'  => $this->symptomes->version(),
            'recommandation_texte' => $recommandation,
            'drapeau_rouge'        => $drapeauRouge,
            'symptomes'            => $symptomes->map(fn (Symptome $s) => [
                'id'    => $s->id,
                'nom'   => $s->nom_fr,
                'poids' => $s->poids_severite,
            ])->values()->all(),
            'reponses'             => $reponsesEvaluees,
            'details_score'        => [
                'symptomes'    => $scoreSymptomes,
                'reponses'     => $scoreReponses,
                'antecedents'  => $scoreAntecedents,
            ],
        ];
    }

    /**
     * Évalue les réponses au questionnaire selon les règles d'impact définies en base.
     * Retourne [scoreReponses, reponsesAvecImpact, drapeauRouge].
     */
    private function evaluerReponses(Collection $symptomes, array $reponses): array
    {
        $total = 0;
        $drapeau = false;
        $evaluees = [];

        // Index des définitions de questions par symptôme + clé.
        $defsParSymptome = [];
        foreach ($symptomes as $s) {
            foreach (($s->questions_complementaires_json ?? []) as $q) {
                if (isset($q['cle'])) {
                    $defsParSymptome[$s->id][$q['cle']] = $q;
                }
            }
        }

        foreach ($reponses as $rep) {
            $sid = $rep['symptome_id'] ?? null;
            $cle = $rep['cle'] ?? null;
            $valeur = $rep['valeur'] ?? null;
            $def = $defsParSymptome[$sid][$cle] ?? null;
            $impactConfig = $def['impact'] ?? [];

            $points = 0;
            $type = $def['type'] ?? null;

            if ($type === 'echelle') {
                $coef = (float) ($impactConfig['coef'] ?? 1.0);
                $points = (int) round(((float) $valeur) * $coef);
            } elseif ($type === 'nombre') {
                $seuil = $impactConfig['seuil'] ?? null;
                if ($seuil !== null && (float) $valeur > (float) $seuil) {
                    $points = (int) ($impactConfig['points_si_superieur'] ?? 0);
                }
            } elseif ($type === 'booleen') {
                $vrai = filter_var($valeur, FILTER_VALIDATE_BOOLEAN);
                if ($vrai) {
                    $points = (int) ($impactConfig['points_si_vrai'] ?? 0);
                    if (! empty($impactConfig['drapeau_rouge_si_vrai'])) {
                        $drapeau = true;
                    }
                }
            } elseif ($type === 'choix') {
                $pointsParOption = $impactConfig['points_par_option'] ?? [];
                $points = (int) ($pointsParOption[$valeur] ?? 0);
            }

            $total += $points;
            $evaluees[] = [
                'symptome_id'   => $sid,
                'cle'           => $cle,
                'valeur'        => $valeur,
                'valeur_impact' => $points,
            ];
        }

        return [$total, $evaluees, $drapeau];
    }

    /** Convertit un score en niveau de soin (§5.1.2). */
    private function niveauDepuisScore(int $score): string
    {
        return match (true) {
            $score <= 30 => 'leger',
            $score <= 65 => 'modere',
            default      => 'urgent',
        };
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

    /** Construit le texte de recommandation affiché au patient. */
    private function construireRecommandation(string $niveau, ?string $specialite): string
    {
        $texte = match ($niveau) {
            'leger' => 'Niveau LÉGER : vos symptômes semblent bénins. Orientez-vous vers une '
                . 'pharmacie ou un médecin généraliste en consultation libre. Surveillez l\'évolution ; '
                . 'en cas d\'aggravation, refaites un triage.',
            'modere' => 'Niveau MODÉRÉ : consultez sans tarder un médecin généraliste, un Centre de '
                . 'Santé Urbain (CSU) ou une clinique.',
            // Le numéro vient du référentiel national (P6.8e). Le repli, s'il joue, est journalisé
            // côté service — jamais affiché au patient : un avertissement sur la provenance d'un
            // numéro, lu par quelqu'un qui doit appeler des secours, est du bruit au pire moment.
            'urgent' => 'Niveau URGENT : rendez-vous immédiatement au service des urgences d\'un CHU/CHR, '
                . 'ou appelez le SAMU au ' . $this->numeros->numero('samu') . ' (numéro vert, Côte d\'Ivoire).',
        };

        if ($specialite) {
            $texte .= ' Spécialité recommandée : ' . $specialite . '.';
        }

        return $texte;
    }
}
