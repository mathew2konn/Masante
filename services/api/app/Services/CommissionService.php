<?php

namespace App\Services;

use App\Models\AbonnementStructure;
use App\Models\BaremeCommission;
use App\Models\CommissionTransaction;
use App\Models\StructureSanitaire;
use App\Support\StatutAbonnement;
use App\Support\StatutCommission;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use RuntimeException;

/**
 * Calcule la commission MaSanté sur une transaction de paiement patient et l'enregistre.
 *
 * Pur calcul et écriture en base : aucun appel réseau, aucun contact avec GeniusPay ni le
 * microservice Java. `frais_passerelle` et `frais_prestataire` sont des PARAMÈTRES D'ENTRÉE,
 * jamais recalculés ni estimés (R4) — ils viennent du microservice de paiement.
 *
 * ═══ IDEMPOTENCE EN PREMIER GESTE ═══
 * `reference_interne_paiement` est la clé transmise par le microservice Java. Un même appel rejoué
 * (notification renvoyée, relance réseau) doit retourner la ligne déjà écrite SANS la toucher —
 * c'est cette règle, appliquée avant tout calcul, qui garantit qu'une commission n'est jamais
 * recalculée après enregistrement, plus encore que le garde-fou du modèle (qui ne bloque, lui, qu'à
 * partir de `FACTUREE` — voir Phase 0 de ce lot).
 */
class CommissionService
{
    public function calculerEtEnregistrer(array $donnees): CommissionTransaction
    {
        $referenceInternePaiement = $this->chaineObligatoire($donnees, 'referenceInternePaiement');

        // Idempotence AVANT tout calcul : aucune lecture de barème, aucune somme de volume, rien.
        $existante = CommissionTransaction::where('reference_interne_paiement', $referenceInternePaiement)->first();
        if ($existante !== null) {
            return $existante;
        }

        $structureSanitaireId = $this->entierObligatoire($donnees, 'structureSanitaireId');
        $montantBrut = $this->entierObligatoire($donnees, 'montantBrut');
        $fraisPasserelle = $this->entierObligatoire($donnees, 'fraisPasserelle');
        $fraisPrestataire = $this->entierObligatoire($donnees, 'fraisPrestataire');
        $facturePatientId = $donnees['facturePatientId'] ?? null;
        $referenceGeniuspay = $donnees['referenceGeniuspay'] ?? null;
        $dateTransaction = $this->dateObligatoire($donnees, 'dateTransaction');

        if ($montantBrut <= 0) {
            throw new InvalidArgumentException('montantBrut doit être strictement positif.');
        }

        $structure = StructureSanitaire::findOrFail($structureSanitaireId);

        // §R5 : « réglé en ligne » n'est PAS une donnée fiable de `factures_patient` (Phase 0 —
        // `paiement_en_ligne_autorise` dit seulement qu'un règlement en ligne est AUTORISÉ, jamais
        // qu'il a eu lieu). On ne le déduit donc jamais : paramètre explicite, et à défaut on pose
        // `false` en le journalisant plutôt qu'en devinant.
        $regleEnLigne = $donnees['regleEnLigne'] ?? null;
        if ($regleEnLigne === null) {
            $regleEnLigne = false;
            Log::warning('CommissionService : regleEnLigne non fourni, considéré false par défaut.', [
                'structure_sanitaire_id' => $structureSanitaireId,
                'reference_interne_paiement' => $referenceInternePaiement,
            ]);
        }

        $estPharmacieHorsLigne = $structure->type === 'pharmacie' && $regleEnLigne === false;
        $planCommissionIncluse = $this->planActuel($structureSanitaireId)?->commission_incluse ?? false;
        $exoneree = $estPharmacieHorsLigne || $planCommissionIncluse;

        // Le volume cumulé se calcule TOUJOURS, exonération ou non : il documente le tableau de
        // bord dans les deux cas (§A1e), et `volume_cumule_au_calcul` n'est de toute façon jamais
        // nul en base.
        $volumeCumule = $this->volumeCumuleAvantCetteTransaction($structureSanitaireId, $dateTransaction);

        if ($exoneree) {
            $tauxBpsApplique = 0;
            $montantCommission = 0;
        } else {
            $bareme = $this->baremeActif($volumeCumule, $dateTransaction);
            $tauxBpsApplique = $bareme->taux_bps;
            $montantCommission = $this->arrondiCommission($montantBrut, $tauxBpsApplique);
        }

        $montantNetStructure = $montantBrut - $fraisPasserelle - $fraisPrestataire - $montantCommission;

        if ($montantBrut !== $fraisPasserelle + $fraisPrestataire + $montantCommission + $montantNetStructure) {
            // Ne devrait jamais se produire (l'équation est une identité algébrique du calcul
            // ci-dessus) — gardé explicite plutôt qu'implicite : le reçu transparent (docblock du
            // modèle) n'a de valeur que si cette égalité est PROUVÉE avant l'écriture, pas supposée.
            throw new RuntimeException(
                'Montants déséquilibrés : montantBrut doit égaler frais_passerelle + frais_prestataire '
                .'+ montant_commission + montant_net_structure. Aucune ligne écrite.'
            );
        }

        return CommissionTransaction::create([
            'structure_sanitaire_id' => $structureSanitaireId,
            'facture_patient_id' => $facturePatientId,
            'reference_geniuspay' => $referenceGeniuspay,
            'reference_interne_paiement' => $referenceInternePaiement,
            'montant_brut' => $montantBrut,
            'frais_passerelle' => $fraisPasserelle,
            'frais_prestataire' => $fraisPrestataire,
            'taux_bps_applique' => $tauxBpsApplique,
            'volume_cumule_au_calcul' => $volumeCumule,
            'montant_commission' => $montantCommission,
            'montant_net_structure' => $montantNetStructure,
            'statut' => StatutCommission::CALCULEE,
            'date_transaction' => $dateTransaction,
        ]);
    }

    /**
     * Tableau de bord facturation d'une structure (lot 8) — lecture seule, aucun calcul de
     * commission : volume cumulé du mois en cours, palier actif, et volume restant avant le
     * palier suivant (`null` si la structure est déjà au dernier palier, par `palier_ordre`).
     *
     * @return array{volume_cumule_mois: int, bareme_actif: ?BaremeCommission, volume_avant_palier_suivant: ?int}
     */
    public function tableauDeBord(int $structureSanitaireId): array
    {
        $maintenant = CarbonImmutable::now();
        // Le nom du helper parle d'« avant cette transaction » (contexte : au calcul d'UNE
        // commission) — ici il n'y a pas de transaction à exclure, donc c'est déjà le volume total
        // du mois en cours.
        $volumeCumule = $this->volumeCumuleAvantCetteTransaction($structureSanitaireId, $maintenant);

        $bareme = BaremeCommission::query()
            ->where('volume_mensuel_min', '<=', $volumeCumule)
            ->where(function ($q) use ($volumeCumule) {
                $q->whereNull('volume_mensuel_max')->orWhere('volume_mensuel_max', '>=', $volumeCumule);
            })
            ->whereDate('date_effet', '<=', $maintenant)
            ->where(function ($q) use ($maintenant) {
                $q->whereNull('date_fin')->orWhereDate('date_fin', '>=', $maintenant);
            })
            ->orderByDesc('volume_mensuel_min')
            ->first();

        $volumeAvantPalierSuivant = null;
        if ($bareme !== null) {
            $palierSuivant = BaremeCommission::query()
                ->where('palier_ordre', $bareme->palier_ordre + 1)
                ->whereDate('date_effet', '<=', $maintenant)
                ->where(function ($q) use ($maintenant) {
                    $q->whereNull('date_fin')->orWhereDate('date_fin', '>=', $maintenant);
                })
                ->first();

            if ($palierSuivant !== null) {
                $volumeAvantPalierSuivant = max(0, $palierSuivant->volume_mensuel_min - $volumeCumule);
            }
        }

        return [
            'volume_cumule_mois' => $volumeCumule,
            'bareme_actif' => $bareme,
            'volume_avant_palier_suivant' => $volumeAvantPalierSuivant,
        ];
    }

    /**
     * L'abonnement en cours d'une structure. Aucune colonne ne marque un abonnement « courant » —
     * on retient le plus récent qui n'est pas RESILIE, seule lecture disponible tant qu'un autre
     * lot n'a pas posé la notion de contrat actif (signalé en Phase 0, non bloquant pour celui-ci).
     */
    private function planActuel(int $structureSanitaireId): ?\App\Models\PlanTarifaire
    {
        $abonnement = AbonnementStructure::where('structure_sanitaire_id', $structureSanitaireId)
            ->where('statut', '!=', StatutAbonnement::RESILIE->value)
            ->orderByDesc('date_debut')
            ->with('planTarifaire')
            ->first();

        return $abonnement?->planTarifaire;
    }

    /**
     * Somme de `montant_brut` des commissions CALCULEE ou FACTUREE de la structure, du premier au
     * dernier jour du mois de `$dateTransaction`, AVANT d'ajouter la transaction courante — c'est
     * cette valeur, jamais recalculée après coup, qui justifie le taux figé sur la ligne (§R3).
     */
    private function volumeCumuleAvantCetteTransaction(int $structureSanitaireId, CarbonImmutable $dateTransaction): int
    {
        return (int) CommissionTransaction::query()
            ->where('structure_sanitaire_id', $structureSanitaireId)
            ->whereIn('statut', [StatutCommission::CALCULEE->value, StatutCommission::FACTUREE->value])
            ->whereBetween('date_transaction', [$dateTransaction->startOfMonth(), $dateTransaction->endOfMonth()])
            ->sum('montant_brut');
    }

    /**
     * Le palier actif à la date de la transaction pour un volume cumulé donné.
     *
     * `whereDate()`, pas `where()`, sur `date_effet`/`date_fin` : ce sont des colonnes castées
     * `date` qui stockent un DATETIME complet ("AAAA-MM-JJ 00:00:00") — le même piège de comparaison
     * lexicographique au jour pile déjà corrigé dans `RecouvrementPartenaireService` (lot 1).
     */
    private function baremeActif(int $volumeCumule, CarbonImmutable $dateTransaction): BaremeCommission
    {
        $bareme = BaremeCommission::query()
            ->where('volume_mensuel_min', '<=', $volumeCumule)
            ->where(function ($q) use ($volumeCumule) {
                $q->whereNull('volume_mensuel_max')->orWhere('volume_mensuel_max', '>=', $volumeCumule);
            })
            ->whereDate('date_effet', '<=', $dateTransaction)
            ->where(function ($q) use ($dateTransaction) {
                $q->whereNull('date_fin')->orWhereDate('date_fin', '>=', $dateTransaction);
            })
            ->orderByDesc('volume_mensuel_min')
            ->first();

        if ($bareme === null) {
            throw new RuntimeException(
                "Aucun palier de commission actif ne couvre le volume {$volumeCumule} au ".
                $dateTransaction->toDateString().'. Le barème doit couvrir 0..∞ sans trou.'
            );
        }

        return $bareme;
    }

    /** Arrondi commercial (0,5 arrondit au-dessus) — `round()` de PHP l'applique déjà par défaut. */
    private function arrondiCommission(int $montantBrut, int $tauxBpsApplique): int
    {
        return (int) round($montantBrut * $tauxBpsApplique / 10000);
    }

    private function entierObligatoire(array $donnees, string $cle): int
    {
        if (! isset($donnees[$cle]) || ! is_int($donnees[$cle])) {
            throw new InvalidArgumentException("Le paramètre '{$cle}' est obligatoire et doit être un entier.");
        }

        return $donnees[$cle];
    }

    private function chaineObligatoire(array $donnees, string $cle): string
    {
        if (empty($donnees[$cle]) || ! is_string($donnees[$cle])) {
            throw new InvalidArgumentException("Le paramètre '{$cle}' est obligatoire et doit être une chaîne non vide.");
        }

        return $donnees[$cle];
    }

    private function dateObligatoire(array $donnees, string $cle): CarbonImmutable
    {
        if (! isset($donnees[$cle]) || ! $donnees[$cle] instanceof DateTimeInterface) {
            throw new InvalidArgumentException("Le paramètre '{$cle}' est obligatoire et doit implémenter DateTimeInterface.");
        }

        return CarbonImmutable::instance($donnees[$cle]);
    }
}
