<?php

namespace App\Services;

use App\Models\Medicament;
use App\Models\PrixPharmacie;
use App\Models\StructureSanitaire;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Module 5 / 5.8 — Comparateur de prix (FN7) et ruptures (FN8).
 *
 * LE PROBLÈME CENTRAL N'EST PAS TECHNIQUE, IL EST ÉPISTÉMIQUE : un prix rapporté par un inconnu
 * n'a aucune garantie. Trois défenses, dans cet ordre :
 *
 *  1. HIÉRARCHIE DES SOURCES. Le pharmacien qui saisit ses prix au portail (`pharmacie_portail`)
 *     fait autorité sur sa propre officine ; à défaut, on agrège les relevés des patients. La
 *     référence CENAME reste le point de comparaison (« prix officiel »), jamais le prix pratiqué.
 *  2. MÉDIANE, PAS DERNIER RELEVÉ. Un plaisantin isolé ne doit pas déplacer l'affichage : on prend
 *     la médiane des relevés récents. Il faut convaincre la majorité, pas être le dernier à parler.
 *  3. PLAUSIBILITÉ AVANT ÉCRITURE. Un prix hors de proportion avec la référence CENAME est une faute
 *     de frappe ou une blague : refusé avant d'entrer en base — exactement comme la glycémie à
 *     500 g/L (5.6). On ne modère pas ce qu'on peut empêcher.
 *
 * Et une quatrième, qui n'est pas un calcul : la FRAÎCHEUR est affichée. Un prix sans date ne vaut
 * rien ; le patient doit savoir qu'on parle d'un relevé d'il y a trois jours, pas d'une vérité.
 */
class PrixMedicamentService
{
    /** Nombre de relevés de patients au-delà duquel on ose parler de « prix constaté ». */
    private const RELEVES_MIN_CROWDSOURCE = 1;

    /**
     * Compare les prix d'un médicament, pharmacie par pharmacie. Trié du moins cher au plus cher —
     * c'est la question que le patient se pose (« où est-ce le moins cher, près de moi ? »).
     *
     * @param  array{commune?: string|null}  $filtres
     * @return Collection<int, array<string, mixed>>
     */
    public function comparer(Medicament $medicament, array $filtres = []): Collection
    {
        $releves = PrixPharmacie::query()
            ->where('medicament_id', $medicament->id)
            ->recent()
            ->when(
                $filtres['commune'] ?? null,
                fn ($q, $commune) => $q->whereHas('structure', fn ($s) => $s->where('commune', $commune)),
            )
            ->with('structure:id,nom,commune,adresse,telephone,latitude,longitude')
            ->get();

        return $releves
            ->groupBy('structure_id')
            ->map(fn (Collection $parPharmacie) => $this->offreDe($parPharmacie))
            ->filter()
            ->sortBy(fn (array $offre) => $offre['prix_cfa'] ?? PHP_INT_MAX)
            ->values();
    }

    /**
     * Ce qu'on retient pour UNE pharmacie : le prix qui fait autorité, sa provenance, sa fraîcheur,
     * et l'état du stock.
     *
     * @param  Collection<int, PrixPharmacie>  $releves
     * @return array<string, mixed>|null
     */
    private function offreDe(Collection $releves): ?array
    {
        $dernier = $this->plusRecent($releves);

        if ($dernier === null) {
            return null;
        }

        // Le pharmacien fait autorité sur SA pharmacie : son relevé prime sur ceux des patients.
        $duPharmacien = $this->plusRecent($releves->where('source', 'pharmacie_portail'));

        $prixPatients = $releves
            ->where('source', 'crowdsource_patient')
            ->whereNotNull('prix_cfa')
            ->pluck('prix_cfa');

        $prix = $duPharmacien?->prix_cfa;
        $source = $duPharmacien !== null ? 'pharmacie_portail' : null;

        if ($prix === null && $prixPatients->count() >= self::RELEVES_MIN_CROWDSOURCE) {
            $prix = $this->mediane($prixPatients);
            $source = 'crowdsource_patient';
        }

        // La disponibilité, elle, suit le relevé le PLUS RÉCENT quelle qu'en soit la source : une
        // rupture signalée ce matin par un patient prime sur le stock déclaré la semaine dernière.
        return [
            'structure'        => $dernier->structure,
            'prix_cfa'         => $prix,
            'source'           => $source,
            'disponible'       => (bool) $dernier->disponible,
            'releves'          => $prixPatients->count(),
            'date_mise_a_jour' => ($duPharmacien ?? $dernier)->date_mise_a_jour?->toIso8601String(),
        ];
    }

    /**
     * FN7 — « Suggère génériques moins chers ». Deux boîtes portant la même DCI contiennent la même
     * molécule : on propose donc les autres présentations de la même DCI dont la référence est moins
     * chère. La suggestion se fait sur le PRIX DE RÉFÉRENCE (fait officiel), jamais sur un prix
     * crowdsourcé — on n'oriente pas un achat sur la foi d'un passant.
     *
     * @return Collection<int, Medicament>
     */
    public function generiquesMoinsChers(Medicament $medicament): Collection
    {
        if ($medicament->prix_reference_cfa === null) {
            return collect();
        }

        return Medicament::query()
            ->where('nom_generique', $medicament->nom_generique)
            ->where('id', '!=', $medicament->id)
            ->whereNotNull('prix_reference_cfa')
            ->where('prix_reference_cfa', '<', $medicament->prix_reference_cfa)
            ->orderBy('prix_reference_cfa')
            ->get();
    }

    /**
     * FN8 — Vue agrégée des ruptures du moment, par commune (« éviter les déplacements inutiles »).
     * On ne liste pas des relevés bruts : on dit, pour chaque médicament, DANS COMBIEN de pharmacies
     * il manque, et où. C'est l'information qui évite le déplacement.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function ruptures(?string $commune = null): Collection
    {
        return PrixPharmacie::query()
            ->recent()
            ->with(['medicament:id,nom_generique,nom_commercial,categorie', 'structure:id,nom,commune'])
            ->when(
                $commune,
                fn ($q) => $q->whereHas('structure', fn ($s) => $s->where('commune', $commune)),
            )
            ->get()
            // Une rupture n'est vraie que si elle est le DERNIER mot sur ce couple (médicament,
            // pharmacie) : un réapprovisionnement signalé depuis annule le signalement précédent.
            ->groupBy(fn (PrixPharmacie $p) => $p->medicament_id.'-'.$p->structure_id)
            ->map(fn (Collection $releves) => $this->plusRecent($releves))
            ->filter(fn (PrixPharmacie $p) => ! $p->disponible)
            ->groupBy('medicament_id')
            ->map(fn (Collection $enRupture) => [
                'medicament'  => $enRupture->first()->medicament,
                'pharmacies'  => $enRupture->map(fn (PrixPharmacie $p) => $p->structure)->values(),
                'nb_pharmacies' => $enRupture->count(),
                'depuis'      => $enRupture->min('date_mise_a_jour')?->toIso8601String(),
            ])
            ->sortByDesc('nb_pharmacies')
            ->values();
    }

    /**
     * Enregistre un relevé de PRIX. `$user` est nul pour une saisie du pharmacien au portail.
     *
     * @throws ValidationException si le prix est invraisemblable
     */
    public function releverPrix(
        Medicament $medicament,
        StructureSanitaire $pharmacie,
        int $prix,
        string $source,
        ?User $user = null,
    ): PrixPharmacie {
        $this->exigerPharmacie($pharmacie);
        $this->exigerPrixPlausible($medicament, $prix);

        return $this->ecrire($medicament, $pharmacie, [
            'prix_cfa'   => $prix,
            'disponible' => true,     // on ne peut pas payer un médicament absent : le relevé le prouve
            'source'     => $source,
        ], $user);
    }

    /** FN8 — Signale une RUPTURE (patient ou pharmacien). Aucun prix : le rayon est vide. */
    public function signalerRupture(
        Medicament $medicament,
        StructureSanitaire $pharmacie,
        string $source,
        ?User $user = null,
    ): PrixPharmacie {
        $this->exigerPharmacie($pharmacie);

        return $this->ecrire($medicament, $pharmacie, [
            'prix_cfa'   => null,
            'disponible' => false,
            'source'     => $source,
        ], $user);
    }

    /**
     * Bornes de plausibilité, adossées à la référence CENAME quand elle existe. Un prix hors
     * proportion est une faute de frappe ou une blague : on refuse d'écrire plutôt que de modérer
     * après coup. Les bornes sont larges à dessein — une officine privée peut vendre plus cher
     * qu'une pharmacie publique, ce n'est pas à nous d'en juger ; on n'écarte que l'absurde.
     */
    private function exigerPrixPlausible(Medicament $medicament, int $prix): void
    {
        $reference = $medicament->prix_reference_cfa;
        $plancher = (int) config('masante.prix.plancher_cfa');
        $plafond = (int) config('masante.prix.plafond_cfa');

        if ($reference !== null) {
            $plancher = max($plancher, (int) round($reference * (float) config('masante.prix.facteur_min')));
            $plafond = min($plafond, (int) round($reference * (float) config('masante.prix.facteur_max')));
        }

        if ($prix < $plancher || $prix > $plafond) {
            throw ValidationException::withMessages([
                'prix_cfa' => "Ce prix semble erroné : on attend un montant compris entre {$plancher} "
                    ."et {$plafond} FCFA pour ce médicament. Vérifiez votre saisie.",
            ]);
        }
    }

    /** Un prix ne se relève que dans une PHARMACIE (annuaire du Module 3) — pas dans un CHU. */
    private function exigerPharmacie(StructureSanitaire $structure): void
    {
        if (! $structure->estPharmacie()) {
            throw ValidationException::withMessages([
                'structure_id' => 'Les prix et les ruptures ne se signalent que dans une pharmacie.',
            ]);
        }
    }

    /** Écrit un relevé (jamais une mise à jour : un relevé est un fait daté). */
    private function ecrire(
        Medicament $medicament,
        StructureSanitaire $pharmacie,
        array $donnees,
        ?User $user,
    ): PrixPharmacie {
        $releve = new PrixPharmacie([...$donnees, 'date_mise_a_jour' => now()]);
        $releve->medicament_id = $medicament->id;
        $releve->structure_id = $pharmacie->id;
        $releve->signale_par_user_id = $user?->id;
        $releve->save();

        return $releve;
    }

    /**
     * Le relevé qui a le DERNIER MOT sur un couple (médicament, pharmacie).
     *
     * Départagé par l'identifiant, et pas seulement par la date : deux relevés faits dans la même
     * seconde — un signalement de rupture puis, aussitôt, un relevé de prix — portent le même
     * horodatage, et « le plus récent » deviendrait indéterminé. L'identifiant, lui, croît toujours.
     * Sans ce départage, une rupture pouvait survivre au réapprovisionnement qui l'annulait.
     *
     * @param  Collection<int, PrixPharmacie>  $releves
     */
    private function plusRecent(Collection $releves): ?PrixPharmacie
    {
        return $releves
            ->sortByDesc(fn (PrixPharmacie $p) => [$p->date_mise_a_jour->getTimestamp(), $p->id])
            ->first();
    }

    /** Médiane d'une collection d'entiers (valeur basse pour un nombre pair de relevés). */
    private function mediane(Collection $valeurs): int
    {
        $triees = $valeurs->sort()->values();
        $milieu = (int) floor(($triees->count() - 1) / 2);

        return (int) $triees[$milieu];
    }
}
