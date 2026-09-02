<?php

namespace App\Services\Triage;

use App\Models\JeuDonneesEntrainement;
use App\Models\PredictionIa;
use App\Models\VersionModeleIa;
use App\Support\RegistreRetourTriage;
use Illuminate\Support\Collection;

/**
 * P10c-3-ii lot B (F29) — Confronter ce que le modèle a prédit à ce que le soignant a jugé.
 *
 * ═══ POURQUOI CET ÉCRAN EST LE SEUL ENDROIT OÙ LA PRÉDICTION SE VOIT ═══
 *
 * Le modèle tourne en observation : il prédit à chaque triage, personne ne le voit dans le parcours
 * de soin. Ce n'est pas de la timidité — c'est la seule façon de mesurer sans fausser. Montrer la
 * prédiction au soignant **avant** son verdict fermerait la boucle : son jugement devient
 * l'étiquette d'entraînement du modèle suivant (`ServiceRetourTriage` l'écrit dans la même
 * transaction), et le modèle finirait par s'auto-confirmer. **Le défaut serait invisible dans les
 * métriques — elles s'amélioreraient.**
 *
 * D'où la comparaison APRÈS COUP, et sur la surface administrateur seulement : contrôleurs
 * plateforme indépendants, jamais l'établissement dont les triages sont examinés (ADR-017 §7).
 *
 * ═══ CE QUE §8 DEMANDE, ET CE QU'ON RÉPOND ═══
 *
 * « Suivi des performances en production » et « matrice de confusion ». On rend les deux — plus le
 * **rappel sur `sous_triage` mesuré en production**, à côté de celui du jeu de test. C'est la seule
 * métrique qui dit si le modèle rate le cas dangereux, et la seule dont l'écart entre test et
 * production se paie en vies plutôt qu'en points de pourcentage.
 *
 * ═══ ON NE DÉPARTAGE JAMAIS DEUX VERDICTS D'UN MÊME TRIAGE ═══
 *
 * Un triage jugé deux fois produit DEUX couples, tous deux comptés. Cohérence stricte avec F13 :
 * « écarter l'une reviendrait à choisir à la place du médecin qui l'a validée ». Un médecin qui se
 * ravise dit quelque chose ; le taire fausserait la mesure dans le sens le plus flatteur.
 *
 * ═══ LA CHRONOLOGIE SUFFIT, ET C'EST F30 QUI LE GARANTIT ═══
 *
 * Aucun filtre n'exclut les triages du jeu d'entraînement du modèle : il n'y en a pas besoin.
 * L'export ayant retiré `triage_id` (F20), on ne POURRAIT pas le faire ; mais une prédiction n'a
 * lieu qu'au moment du triage (F30), donc un modèle activé après son export ne peut prédire que des
 * triages postérieurs. **Ajouter un re-scoring rétroactif casserait cette garantie en silence** —
 * et c'est écrit ici parce que c'est ici que ça se verrait.
 */
final class ServiceComparaisonModeleIa
{
    /** Les trois classes, dans l'ordre où l'écran les affiche. */
    public const CLASSES = [
        RegistreRetourTriage::ADAPTEE,
        RegistreRetourTriage::SUR_TRIAGE,
        RegistreRetourTriage::SOUS_TRIAGE,
    ];

    /**
     * La confrontation, pour une version donnée.
     *
     * @return array<string, mixed>
     */
    public function pour(VersionModeleIa $version): array
    {
        $couples = $this->couples($version);

        return [
            'version' => $version,
            'nb_predictions' => PredictionIa::query()
                ->where('modele_version', $version->mlflow_run_id)
                ->where('mode', 'observation')
                ->count(),
            'nb_couples' => $couples->count(),
            'matrice' => $this->matrice($couples),
            'concordance' => $this->concordance($couples),
            'rappel_sous_triage_production' => $this->rappelSousTriage($couples),
            // Celui du jeu de TEST, mesuré à l'entraînement — c'est l'écart entre les deux qui
            // informe, pas l'un des deux pris seul.
            'rappel_sous_triage_test' => $this->rappelDuJeuDeTest($version),
            'latence_moyenne_ms' => $this->latenceMoyenne($version),
        ];
    }

    /**
     * Les couples (prédiction, verdict) : un par VERDICT, jamais un par triage.
     *
     * La jointure passe par `triage_id`, qui est un **identifiant** des deux côtés (ADR-042 D1) :
     * ni `predictions_ia` ni `jeux_donnees_entrainement` ne portent de clé étrangère vers `triages`,
     * précisément pour qu'une purge ne détruise pas la trace. On joint donc en requête.
     *
     * @return Collection<int, object{predit: string, reel: string}>
     */
    private function couples(VersionModeleIa $version): Collection
    {
        $predictions = PredictionIa::query()
            ->where('modele_version', $version->mlflow_run_id)
            ->where('mode', 'observation')
            ->whereNotNull('explication_json')
            ->get(['triage_id', 'explication_json'])
            ->mapWithKeys(fn (PredictionIa $p): array => [
                (int) $p->triage_id => $p->explication_json['classe_predite'] ?? null,
            ])
            ->filter();

        if ($predictions->isEmpty()) {
            return collect();
        }

        return JeuDonneesEntrainement::query()
            ->whereIn('triage_id', $predictions->keys())
            ->whereNotNull('label')
            ->get(['triage_id', 'label'])
            ->map(fn (JeuDonneesEntrainement $l): object => (object) [
                'predit' => $predictions[(int) $l->triage_id],
                'reel' => $l->label,
            ])
            ->values();
    }

    /**
     * La matrice de confusion du §8 : prédit (lignes) × réel (colonnes).
     *
     * @param  Collection<int, object>  $couples
     * @return array<string, array<string, int>>
     */
    private function matrice(Collection $couples): array
    {
        $matrice = [];

        foreach (self::CLASSES as $predit) {
            foreach (self::CLASSES as $reel) {
                $matrice[$predit][$reel] = 0;
            }
        }

        foreach ($couples as $couple) {
            if (isset($matrice[$couple->predit][$couple->reel])) {
                $matrice[$couple->predit][$couple->reel]++;
            }
        }

        return $matrice;
    }

    /** @param  Collection<int, object>  $couples */
    private function concordance(Collection $couples): ?float
    {
        if ($couples->isEmpty()) {
            return null;
        }

        return round($couples->filter(fn (object $c): bool => $c->predit === $c->reel)->count()
            / $couples->count(), 4);
    }

    /**
     * Le rappel sur `sous_triage` EN PRODUCTION : parmi les cas qu'un soignant a jugés sous-triés,
     * combien le modèle avait-il vus ?
     *
     * `null` quand aucun cas de cette classe n'est encore survenu — et c'est important : afficher
     * « 0 % » alors qu'il n'y a rien à rappeler serait une accusation gratuite, exactement le
     * travers que `zero_division=0` évite déjà à l'entraînement.
     *
     * @param  Collection<int, object>  $couples
     */
    private function rappelSousTriage(Collection $couples): ?float
    {
        $reels = $couples->filter(
            fn (object $c): bool => $c->reel === RegistreRetourTriage::SOUS_TRIAGE);

        if ($reels->isEmpty()) {
            return null;
        }

        return round($reels->filter(
            fn (object $c): bool => $c->predit === RegistreRetourTriage::SOUS_TRIAGE)->count()
            / $reels->count(), 4);
    }

    private function rappelDuJeuDeTest(VersionModeleIa $version): ?float
    {
        $metrique = $version->metriques->firstWhere('cle', 'rappel_sous_triage');

        return $metrique === null ? null : (float) $metrique->valeur;
    }

    private function latenceMoyenne(VersionModeleIa $version): ?int
    {
        $moyenne = PredictionIa::query()
            ->where('modele_version', $version->mlflow_run_id)
            ->where('mode', 'observation')
            ->avg('latence_ms');

        return $moyenne === null ? null : (int) round((float) $moyenne);
    }
}
