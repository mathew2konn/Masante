<?php

namespace App\Services;

use App\Models\Symptome;
use App\Services\Urgence\ServiceNumerosUrgence;
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
    public function __construct(private readonly ServiceNumerosUrgence $numeros) {}

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
        $symptomes = Symptome::actif()->whereIn('id', $symptomesIds)->get();

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

        // 6) Déduction de la spécialité (§5.1.3) puis texte de recommandation.
        $specialite = $this->deduireSpecialite($symptomes, $age, $sexe, $niveau);
        $recommandation = $this->construireRecommandation($niveau, $specialite);

        return [
            'score_severite'       => $score,
            'niveau'               => $niveau,
            'specialite_requise'   => $specialite,
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
     * Déduit la spécialité médicale (§5.1.3) à partir des indices des symptômes,
     * de l'âge et du sexe.
     */
    private function deduireSpecialite(Collection $symptomes, ?int $age, ?string $sexe, string $niveau): ?string
    {
        $hints = $symptomes
            ->pluck('specialite_hint')
            ->filter()
            ->unique()
            // La gynécologie n'est pertinente que pour une patiente.
            ->reject(fn ($h) => str_contains(mb_strtolower($h), 'gyn') && $sexe !== 'F')
            ->values();

        if ($hints->isNotEmpty()) {
            // En cas d'urgence, on privilégie un indice « urgences/cardiologie ».
            $prioritaire = $hints->first(
                fn ($h) => str_contains(mb_strtolower($h), 'urgenc') || str_contains(mb_strtolower($h), 'cardio')
            );

            return $prioritaire ?? $hints->first();
        }

        // Enfant de moins de 15 ans sans indice spécifique => pédiatrie (§5.1.3).
        if ($age !== null && $age < 15) {
            return 'Pédiatrie';
        }

        return null; // Médecine générale par défaut.
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
