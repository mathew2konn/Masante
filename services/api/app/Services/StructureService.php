<?php

namespace App\Services;

use App\Models\PharmacieGarde;
use App\Models\StructureSanitaire;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Module 3 / étape 3A.1 — Recherche géolocalisée des structures sanitaires (F3.1→F3.5, F3.8).
 *
 * Centralise : filtres (type, commune, spécialité, disponibilité), recherche texte, calcul de
 * proximité (Haversine en SQL) et agrégation du « statut du jour » d'une structure à partir des
 * disponibilités de ses services. Aucune donnée sensible : annuaire public.
 */
class StructureService
{
    /** Rayon terrestre moyen en km (formule de Haversine). */
    private const RAYON_TERRE_KM = 6371;

    /** Priorité d'agrégation : on retient le meilleur statut parmi les services du jour. */
    private const PRIORITE_STATUT = [
        'disponible' => 4,
        'disponible_apres_14h' => 3,
        'complet' => 2,
        'ferme' => 1,
    ];

    /**
     * Recherche filtrée. Retourne des structures enrichies de `statut_jour` (pastille carte)
     * et, si une position est fournie, de `distance_km` (triées par proximité).
     *
     * Le calcul de distance est fait en PHP (jeu de données restreint : annuaire) plutôt qu'en
     * SQL, pour rester portable (les fonctions trigonométriques varient selon le SGBD).
     *
     * @param  array<string, mixed>  $filtres  type, commune, specialite, statut, q, lat, lng, rayon_km
     * @return Collection<int, StructureSanitaire>
     */
    public function rechercher(array $filtres): Collection
    {
        $query = StructureSanitaire::query();
        $this->appliquerFiltres($query, $filtres);
        $this->triParDefaut($query, $filtres);

        $structures = $query
            ->with(['services.disponibilites' => fn ($d) => $d->whereDate('date', Carbon::today())])
            ->get();

        // Statut du jour calculé, puis on n'expose pas les services en liste (payload léger).
        $structures->each(function (StructureSanitaire $s) {
            $s->setAttribute('statut_jour', $this->statutDuJour($s));
            $s->unsetRelation('services');
        });

        // Filtre par disponibilité appliqué après calcul du statut.
        if (! empty($filtres['statut'])) {
            $structures = $structures->where('statut_jour', $filtres['statut'])->values();
        }

        return $this->appliquerProximite($structures, $filtres);
    }

    /** Fiche détaillée : services actifs + disponibilité du jour + médecins réservables + statut global. */
    public function fiche(StructureSanitaire $structure): StructureSanitaire
    {
        $structure->load([
            'services' => fn ($s) => $s->where('actif', true),
            'services.disponibilites' => fn ($d) => $d->whereDate('date', Carbon::today()),
            // Médecins réservables du service (F3.5) — annuaire public, non sensible.
            'services.medecins' => fn ($m) => $m->where('actif', true)->orderBy('nom'),
        ]);

        $structure->setAttribute('statut_jour', $this->statutDuJour($structure));

        return $structure;
    }

    /**
     * Pharmacies de garde du jour (F3.8), triées par proximité si une position est fournie.
     *
     * @param  array<string, mixed>  $filtres  lat, lng, rayon_km
     * @return Collection<int, StructureSanitaire>
     */
    public function pharmaciesDeGarde(array $filtres): Collection
    {
        $idsDeGarde = PharmacieGarde::whereDate('date', Carbon::today())->pluck('structure_id');

        $pharmacies = StructureSanitaire::query()
            ->where('type', 'pharmacie')
            ->whereIn('id', $idsDeGarde)
            ->orderBy('nom')
            ->get();

        return $this->appliquerProximite($pharmacies, $filtres);
    }

    /** Filtres communs (type, commune, spécialité, recherche texte). */
    private function appliquerFiltres(Builder $query, array $filtres): void
    {
        if (! empty($filtres['type'])) {
            $query->where('type', $filtres['type']);
        }
        if (! empty($filtres['commune'])) {
            $query->where('commune', $filtres['commune']);
        }
        // P6.4b — filtre par ville, désigné par son CODE (`ABJ`, `YAM`, `BKE`) et non par son id :
        // le mobile reçoit des codes de `/villes/localiser` et n'a pas à connaître les clés
        // primaires. Additif : `commune` reste servi tel quel, le contrat de P3 est intact.
        if (! empty($filtres['ville'])) {
            $query->whereHas('ville', fn (Builder $v) => $v->where('code', $filtres['ville']));
        }
        if (! empty($filtres['q'])) {
            $query->where('nom', 'like', '%'.$filtres['q'].'%');
        }
        if (! empty($filtres['specialite'])) {
            $query->whereHas('services', fn (Builder $s) => $s
                ->where('actif', true)
                ->where('specialite', $filtres['specialite']));
        }
        // Budget max (F3.2) : structures dont la consultation DÉBUTE dans le budget. Les structures
        // sans tarif renseigné (officines/labos) sont exclues quand ce filtre est actif.
        if (isset($filtres['tarif_max']) && $filtres['tarif_max'] !== null && $filtres['tarif_max'] !== '') {
            $query->whereNotNull('tarif_min_cfa')
                ->where('tarif_min_cfa', '<=', (int) $filtres['tarif_max']);
        }
    }

    /** Ordre par défaut (hors proximité) : partenaires d'abord, puis ordre alphabétique. */
    private function triParDefaut(Builder $query, array $filtres): void
    {
        if (($filtres['lat'] ?? null) === null || ($filtres['lng'] ?? null) === null) {
            $query->orderByDesc('partenaire_ivoirsante')->orderBy('nom');
        }
    }

    /**
     * Si une position est fournie : calcule `distance_km` (Haversine, en PHP) sur chaque
     * structure, filtre par rayon le cas échéant, et trie par proximité croissante.
     *
     * @param  Collection<int, StructureSanitaire>  $structures
     * @return Collection<int, StructureSanitaire>
     */
    private function appliquerProximite(Collection $structures, array $filtres): Collection
    {
        $lat = $filtres['lat'] ?? null;
        $lng = $filtres['lng'] ?? null;

        if ($lat === null || $lng === null) {
            return $structures;
        }

        $structures->each(fn (StructureSanitaire $s) => $s->setAttribute(
            'distance_km',
            round($this->distanceKm((float) $lat, (float) $lng, $s->latitude, $s->longitude), 2),
        ));

        if (! empty($filtres['rayon_km'])) {
            $structures = $structures->filter(
                fn (StructureSanitaire $s) => $s->getAttribute('distance_km') <= (float) $filtres['rayon_km'],
            );
        }

        return $structures->sortBy('distance_km')->values();
    }

    /** Distance de Haversine entre deux points GPS, en kilomètres. */
    private function distanceKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);

        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return self::RAYON_TERRE_KM * 2 * asin(min(1.0, sqrt($a)));
    }

    /**
     * Statut « du jour » d'une structure : meilleur statut parmi les disponibilités du jour de
     * ses services actifs. 'inconnu' si aucune disponibilité renseignée.
     */
    private function statutDuJour(StructureSanitaire $structure): string
    {
        $meilleur = null;
        $meilleurScore = 0;

        foreach ($structure->services as $service) {
            foreach ($service->disponibilites as $dispo) {
                $score = self::PRIORITE_STATUT[$dispo->statut] ?? 0;
                if ($score > $meilleurScore) {
                    $meilleurScore = $score;
                    $meilleur = $dispo->statut;
                }
            }
        }

        return $meilleur ?? 'inconnu';
    }
}
