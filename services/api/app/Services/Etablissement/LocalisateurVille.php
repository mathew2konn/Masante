<?php

namespace App\Services\Etablissement;

use App\Models\Ville;

/**
 * Détermine la ville où se trouve un utilisateur, à partir de sa position (P6.4b, décision V1).
 *
 * ═══ POURQUOI C'EST ICI ET NON DANS LE MOBILE ═══
 *
 * La règle de frontière du projet est sans ambiguïté : les calculs sont au backend, le front
 * affiche ce qu'on lui donne. Rattacher une position à une ville EST un calcul — il dépend du
 * centre et du rayon de chaque ville, qui sont des données susceptibles de changer. Si le mobile
 * le faisait, ouvrir une quatrième ville exigerait de publier une nouvelle version de
 * l'application ; et deux versions installées répondraient différemment à la même question.
 *
 * Le mobile envoie donc `lat`/`lng` et reçoit une réponse complète : la ville, si elle affiche des
 * communes, lesquelles, et — quand l'utilisateur est hors zone — les villes ordonnées par
 * proximité. Il n'a rien à déduire.
 *
 * ═══ LA MÉTHODE : CENTRE + RAYON, EN DONNÉES ═══
 *
 * Chaque ville porte son centre et son rayon de couverture. Une position appartient à la ville la
 * plus proche PARMI CELLES qui la contiennent. Ajouter Korhogo est une ligne en base, zéro code
 * (§1.2.5). Le calcul de distance réutilise la formule de Haversine déjà employée par
 * `StructureService` — même raison qu'à l'époque : en PHP plutôt qu'en SQL, parce que les
 * fonctions trigonométriques varient d'un moteur à l'autre.
 *
 * ═══ HORS ZONE : ON LE DIT ═══
 *
 * Aucune ville ne contient la position ? On ne rattache PAS à la plus proche : un utilisateur à
 * Man serait déclaré « à Bouaké », à 300 km. On renvoie `ville = null` avec les villes ordonnées
 * par distance, et l'écran annonce « hors des zones couvertes » avant de montrer les structures.
 * L'absence se dit plutôt qu'elle ne se comble — le principe des trois silences assumés de P7-D2.
 */
final class LocalisateurVille
{
    private const RAYON_TERRE_KM = 6371.0;

    /**
     * @return array{
     *     ville: ?Ville,
     *     hors_zone: bool,
     *     communes: array<int, string>,
     *     villes_par_proximite: array<int, array{code: string, nom: string, distance_km: float}>
     * }
     */
    public function localiser(float $latitude, float $longitude, ?string $paysCode = null): array
    {
        $paysCode ??= config('referentiels.pays_defaut');

        $villes = Ville::query()->active()->where('pays_code', $paysCode)->orderBy('ordre')->get();

        $parProximite = $villes
            ->map(fn (Ville $v): array => [
                'ville'       => $v,
                'distance_km' => $this->distanceKm($latitude, $longitude, $v->latitude, $v->longitude),
            ])
            ->sortBy('distance_km')
            ->values();

        // Une ville « contient » la position si la distance à son centre est sous son rayon.
        // On prend la plus proche parmi celles-là — deux zones peuvent se chevaucher, et c'est
        // alors le centre le plus près qui l'emporte, pas l'ordre d'insertion en base.
        $contenante = $parProximite->first(
            fn (array $e): bool => $e['distance_km'] <= $e['ville']->rayon_km
        );

        $ville = $contenante['ville'] ?? null;

        return [
            'ville'     => $ville,
            'hors_zone' => $ville === null,
            'communes'  => $ville?->communes() ?? [],
            'villes_par_proximite' => $parProximite
                ->map(fn (array $e): array => [
                    'code'        => $e['ville']->code,
                    'nom'         => $e['ville']->nom,
                    'distance_km' => round($e['distance_km'], 1),
                ])
                ->all(),
        ];
    }

    /** Distance orthodromique en kilomètres (Haversine) — même formule que `StructureService`. */
    private function distanceKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);

        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return self::RAYON_TERRE_KM * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}
