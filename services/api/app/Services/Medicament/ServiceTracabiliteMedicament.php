<?php

namespace App\Services\Medicament;

use App\Models\Delivrance;
use App\Models\Medicament;
use App\Models\TraceDispensation;

/**
 * B3-c — Le registre national de traçabilité (CDC_11 §7.6) : inscription et statistiques.
 *
 * PAS `RegistreTracabiliteMedicament` : dans ce dépôt, `Registre*` désigne une LISTE BLANCHE FERMÉE
 * (`RegistreSectionsCarnet`, `RegistreActionsProtocole`) — le mot est pris pour une autre idée.
 *
 * ═══ `inscrire()` NE DÉCIDE RIEN, IL ENREGISTRE CE QUE LA DÉLIVRANCE A ÉTABLI ═══
 *
 * Appelé DANS LA TRANSACTION de `ServiceDelivrance::delivrer()`, au même endroit que
 * `ServiceStockOfficine::sortirPourDelivrance()` : les deux sont des conséquences d'un acte déjà
 * décidé, jamais un second jugement.
 *
 * ═══ UNE LIGNE NON RATTACHÉE AU RÉFÉRENTIEL ENTRE QUAND MÊME, ET SE COMPTE (E8) ═══
 *
 * Le lien ordonnance → référentiel est FACULTATIF (B3-a). Ne rien écrire pour une ligne non
 * rattachée rendrait la consommation nationale FAUSSE EN SILENCE — la panne muette que ce projet
 * refuse partout. `medicament_code IS NULL` EST le marqueur : aucune colonne supplémentaire.
 */
final class ServiceTracabiliteMedicament
{
    /**
     * Inscrit une trace par ligne servie de la délivrance.
     *
     * @return int le nombre de traces inscrites — toujours égal au nombre de lignes servies,
     *             rattachées ou non (E8).
     */
    public function inscrire(Delivrance $delivrance): int
    {
        $delivrance->loadMissing(['lignes.ligne', 'structure']);

        $inscrites = 0;

        foreach ($delivrance->lignes as $servie) {
            $ligne = $servie->ligne;

            if ($ligne === null) {
                // Ne devrait pas arriver : la garde du moteur de B3-a impose qu'une ligne servie
                // désigne une ligne de CETTE ordonnance. Prudence, pas un chemin attendu.
                continue;
            }

            $trace = new TraceDispensation;
            $trace->pays_code = $delivrance->structure?->pays_code
                ?? config('referentiels.pays_defaut', 'CI');

            // LE QUOI — identité de produit FIGÉE, reprise de la ligne d'ordonnance (déjà figée
            // par `ServiceLienMedicament`, P6.6b) : elle doit survivre au retrait de la fiche.
            $trace->medicament_id = $ligne->medicament_id;
            $trace->medicament_code = $ligne->code_national; // NULL = non rattaché (E8)
            $trace->medicament_nom = $ligne->nom;
            $trace->medicament_dci = $ligne->dci;
            $trace->medicament_dosage = $ligne->dosage;

            // LE COMBIEN
            $trace->quantite = $servie->quantite;

            // LE OÙ — identifiant sans clé étrangère + identifiant national figé.
            $trace->structure_id = $delivrance->structure_id;
            $trace->structure_identifiant_national = $delivrance->structure?->identifiant_national;

            // LE QUAND
            $trace->dispensee_le = $delivrance->delivree_le;

            // Réconciliation et idempotence (E3).
            $trace->delivrance_ligne_id = $servie->id;

            $trace->save();

            $inscrites++;
        }

        return $inscrites;
    }

    /**
     * La consommation nationale, agrégée par produit, sur une fenêtre optionnelle.
     *
     * DÉRIVÉE, JAMAIS STOCKÉE (P5.3a) : ce n'est pas une table de plus, c'est une lecture du
     * registre. `non_rattachees` est le compteur d'honnêteté d'E8 : une dispensation sans code
     * national existe, et l'écran le dit plutôt que de la faire disparaître.
     *
     * @return array{par_produit: array<int, array{code: string, nom: string, quantite: int, dispensations: int}>, non_rattachees: int}
     */
    public function consommation(?string $du = null, ?string $au = null): array
    {
        $base = TraceDispensation::query()
            ->when($du !== null, fn ($q) => $q->whereDate('dispensee_le', '>=', $du))
            ->when($au !== null, fn ($q) => $q->whereDate('dispensee_le', '<=', $au));

        $parProduit = (clone $base)
            ->whereNotNull('medicament_code')
            ->selectRaw('medicament_code, medicament_nom, SUM(quantite) as quantite_totale, COUNT(*) as nb_dispensations')
            ->groupBy('medicament_code', 'medicament_nom')
            ->orderByDesc('quantite_totale')
            ->get()
            ->map(fn ($ligne): array => [
                'code' => $ligne->medicament_code,
                'nom' => $ligne->medicament_nom,
                'quantite' => (int) $ligne->quantite_totale,
                'dispensations' => (int) $ligne->nb_dispensations,
            ])
            ->all();

        return [
            'par_produit' => $parProduit,
            'non_rattachees' => (clone $base)->whereNull('medicament_code')->count(),
        ];
    }

    /**
     * La couverture du référentiel en codes-barres — N sur M, compteur d'honnêteté (E4).
     *
     * La colonne naît vide (§4 du plan) : ce compteur DIT l'absence plutôt que de la cacher.
     *
     * @return array{avec_code_barres: int, total: int}
     */
    public function couvertureCodeBarres(): array
    {
        return [
            'avec_code_barres' => Medicament::whereNotNull('code_barres')->count(),
            'total' => Medicament::count(),
        ];
    }
}
