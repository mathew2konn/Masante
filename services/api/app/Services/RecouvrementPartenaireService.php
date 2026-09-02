<?php

namespace App\Services;

use App\Models\AbonnementStructure;
use App\Models\FacturePartenaire;
use App\Models\ReglementFacturePartenaire;
use App\Support\MotifSuspension;
use App\Support\MoyenReglement;
use App\Support\StatutAbonnement;
use App\Support\StatutFacturePartenaire;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use RuntimeException;

/**
 * Impute les règlements des partenaires sur leurs factures et déclenche la bascule au Palier 0
 * en cas d'impayé. Cahier des charges : docs/REGLES_RECOUVREMENT_PARTENAIRE.md.
 *
 * ═══ LA SEULE PIÈCE DU PROGRAMME QUI ÉCRIT SUR `abonnements_structure.statut` ═══
 * Aucun autre service, aucun contrôleur, ne doit poser SUSPENDU ou ACTIF sur un abonnement.
 *
 * ═══ LE PARTENAIRE NE DÉSIGNE JAMAIS LA FACTURE QU'IL RÈGLE ═══
 * L'imputation se fait sur la plus ancienne facture impayée (`date_echeance` croissante), jamais
 * sur celle qu'un formulaire aurait laissé choisir. C'est ce qui rend l'imputation vérifiable après
 * coup : la règle qui l'a produite est unique et rejouable.
 *
 * ═══ L'EXCÉDENT NE S'ÉCRIT NULLE PART ═══
 * Si un règlement dépasse le total dû, l'imputation s'arrête au dernier centime dû sur la dernière
 * facture touchée — jamais de `montant_regle` au-delà de `montant_total` d'une facture (la colonne
 * est de toute façon `unsignedBigInteger`, elle ne pourrait pas porter une valeur qui la dépasse
 * sans dépasser aussi le total). L'excédent est journalisé, jamais stocké : la gestion d'un avoir
 * est hors périmètre de ce lot (docs/REGLES_RECOUVREMENT_PARTENAIRE.md §1), elle ne s'improvise pas.
 */
class RecouvrementPartenaireService
{
    /** Délai après échéance avant bascule au Palier 0. Donnée du service, jamais dupliquée ailleurs. */
    private const JOURS_AVANT_IMPAYE = 30;

    /** Statuts d'une facture partenaire encore ouverte à l'imputation. */
    private const STATUTS_OUVERTS = [
        StatutFacturePartenaire::EMISE->value,
        StatutFacturePartenaire::PARTIELLEMENT_REGLEE->value,
        StatutFacturePartenaire::IMPAYEE->value,
    ];

    public function __construct(private readonly ServiceNotification $notifications)
    {
    }

    /**
     * Enregistre un règlement reçu d'une structure et l'impute sur ses factures ouvertes,
     * la plus ancienne d'abord. Réactive l'abonnement dans la même transaction si le règlement
     * solde tout ce qui restait dû et que la structure était suspendue pour impayé.
     *
     * @return array{imputations: list<array{facture_partenaire_id:int,reference:string,montant_impute:int,solde_apres:int,statut:string}>, excedent_non_impute:int}
     */
    public function enregistrerReglement(
        int $structureSanitaireId,
        int $montant,
        string $moyen,
        ?string $referenceExterne,
        DateTimeInterface $dateReglement,
    ): array {
        if ($montant <= 0) {
            throw new InvalidArgumentException('Un règlement doit porter un montant strictement positif.');
        }

        // Valide le canal avant toute écriture : une valeur hors vocabulaire doit échouer ici,
        // jamais être découverte au moment du `INSERT`.
        $moyenEnum = MoyenReglement::from($moyen);

        return DB::transaction(function () use ($structureSanitaireId, $montant, $moyenEnum, $referenceExterne, $dateReglement) {
            $factures = FacturePartenaire::query()
                ->where('structure_sanitaire_id', $structureSanitaireId)
                ->whereIn('statut', self::STATUTS_OUVERTS)
                ->orderBy('date_echeance')
                ->lockForUpdate()
                ->get();

            $restant = $montant;
            $imputations = [];

            foreach ($factures as $facture) {
                if ($restant <= 0) {
                    break;
                }

                $solde = $facture->solde;
                if ($solde <= 0) {
                    // Ligne incohérente (ne devrait pas exister sous ces statuts) : on ne s'y arrête
                    // pas, on ne peut rien lui imputer.
                    continue;
                }

                $impute = min($restant, $solde);

                ReglementFacturePartenaire::create([
                    'facture_partenaire_id' => $facture->id,
                    'montant' => $impute,
                    'moyen' => $moyenEnum,
                    'reference_externe' => $referenceExterne,
                    'date_reglement' => $dateReglement,
                ]);

                $facture->montant_regle = (int) $facture->montant_regle + $impute;

                $soldeApres = (int) $facture->montant_total - (int) $facture->montant_regle;
                if ($soldeApres <= 0) {
                    $facture->statut = StatutFacturePartenaire::PAYEE;
                    $facture->date_paiement = $dateReglement;
                } else {
                    $facture->statut = StatutFacturePartenaire::PARTIELLEMENT_REGLEE;
                }

                $facture->save();

                $imputations[] = [
                    'facture_partenaire_id' => $facture->id,
                    'reference' => $facture->reference,
                    'montant_impute' => $impute,
                    'solde_apres' => max($soldeApres, 0),
                    'statut' => $facture->statut->value,
                ];

                $restant -= $impute;
            }

            if ($restant > 0) {
                // §1 de la spécification : ni stocké, ni corrigé en silence. La gestion d'un avoir
                // est un lot séparé, non écrit ici.
                Log::warning('Règlement partenaire excédant le total dû sur les factures ouvertes.', [
                    'structure_sanitaire_id' => $structureSanitaireId,
                    'montant_regle' => $montant,
                    'montant_excedent' => $restant,
                ]);
            }

            $this->reactiverSiSoldee($structureSanitaireId);

            return [
                'imputations' => $imputations,
                'excedent_non_impute' => $restant,
            ];
        });
    }

    /**
     * Tâche planifiée quotidienne : bascule au Palier 0 toute facture échue depuis 30 jours ou plus
     * et encore soldée positivement, et suspend l'abonnement des structures concernées.
     *
     * N'écrit QUE sur `factures_partenaire.statut` et sur les trois colonnes de bascule
     * d'`abonnements_structure` (`statut`, `motif_suspension`, `date_bascule_palier0`). Jamais sur
     * `structures_sanitaires.actif`, jamais sur `paiements`, jamais sur les rendez-vous — voir
     * docs/REGLES_RECOUVREMENT_PARTENAIRE.md §4.
     */
    public function verifierEcheances(): void
    {
        DB::transaction(function () {
            $dateLimite = now()->subDays(self::JOURS_AVANT_IMPAYE)->toDateString();

            $facturesEnRetard = FacturePartenaire::query()
                ->whereIn('statut', [
                    StatutFacturePartenaire::EMISE->value,
                    StatutFacturePartenaire::PARTIELLEMENT_REGLEE->value,
                ])
                ->whereNotNull('date_echeance')
                // `whereDate`, pas `where` : le cast `date` d'Eloquent stocke un DATETIME complet
                // ("2026-07-28 00:00:00"), pas juste "2026-07-28". Comparé en `<=` à une simple
                // date en TEXTE (SQLite n'a pas de type DATE natif), la forme la plus longue
                // perdrait TOUJOURS la comparaison lexicographique au jour pile — précisément le
                // cas limite « à J+30 » que ce service doit reconnaître. `whereDate()` compare sur
                // `DATE(date_echeance)`, portable MySQL/SQLite, insensible au format de stockage.
                ->whereDate('date_echeance', '<=', $dateLimite)
                // Filtre au niveau SQL sur les COLONNES réelles, jamais sur un `solde` stocké :
                // c'est la traduction en clause `WHERE` de l'accesseur dérivé du modèle.
                ->whereColumn('montant_regle', '<', 'montant_total')
                ->lockForUpdate()
                ->get();

            $structuresBasculees = [];

            foreach ($facturesEnRetard as $facture) {
                $facture->statut = StatutFacturePartenaire::IMPAYEE;
                $facture->save();

                $structuresBasculees[$facture->structure_sanitaire_id] = true;
            }

            foreach (array_keys($structuresBasculees) as $structureSanitaireId) {
                $abonnement = AbonnementStructure::query()
                    ->where('structure_sanitaire_id', $structureSanitaireId)
                    ->lockForUpdate()
                    ->first();

                if ($abonnement === null) {
                    continue;
                }

                if (! in_array($abonnement->statut, [StatutAbonnement::ACTIF, StatutAbonnement::ESSAI], true)) {
                    // Déjà SUSPENDU (autre motif, ou déjà basculé) ou RESILIE : on ne réécrit rien.
                    continue;
                }

                $abonnement->statut = StatutAbonnement::SUSPENDU;
                $abonnement->motif_suspension = MotifSuspension::IMPAYE;
                $abonnement->date_bascule_palier0 = now();
                $abonnement->save();

                // Lot 9 (post-facturation) — alerte interne, jamais envoyée au patient.
                $this->notifications->structureSuspendue(
                    $structureSanitaireId,
                    $this->soldeTotalOuvert($structureSanitaireId),
                    $abonnement->date_bascule_palier0,
                );
            }
        });
    }

    /**
     * Réactive l'abonnement d'une structure dont le solde global est nul. Sans ressaisie : ni
     * nouveau dossier, ni nouvelle vérification, ni nouvelle pièce.
     *
     * `motif_suspension` est effacé. `date_bascule_palier0` NE L'EST JAMAIS : c'est un horodatage
     * d'audit, pas un drapeau — docs/REGLES_RECOUVREMENT_PARTENAIRE.md §5, qui prime sur toute
     * lecture antérieure de ce service. Elle ne sera écrasée que par une prochaine bascule
     * (`verifierEcheances()`), jamais par une réactivation.
     *
     * @throws RuntimeException si la structure porte encore un solde impayé.
     */
    public function reactiver(int $structureSanitaireId): void
    {
        DB::transaction(function () use ($structureSanitaireId) {
            $solde = $this->soldeTotalOuvert($structureSanitaireId);

            if ($solde > 0) {
                throw new RuntimeException(
                    "Réactivation refusée : la structure {$structureSanitaireId} porte encore ".
                    "un solde impayé de {$solde} francs."
                );
            }

            $abonnement = AbonnementStructure::query()
                ->where('structure_sanitaire_id', $structureSanitaireId)
                ->lockForUpdate()
                ->first();

            if ($abonnement === null) {
                return;
            }

            // Capturé AVANT écrasement : seule une vraie transition SUSPENDU→ACTIF mérite une
            // alerte (lot 9) — appeler `reactiver()` sur un abonnement déjà ACTIF ne doit rien
            // annoncer au back-office, ce ne serait pas une réactivation.
            $etaitSuspendu = $abonnement->statut === StatutAbonnement::SUSPENDU;

            $abonnement->statut = StatutAbonnement::ACTIF;
            $abonnement->motif_suspension = null;
            // N'écris jamais `date_bascule_palier0 = null` ici — voir le docblock de la méthode.
            $abonnement->save();

            if ($etaitSuspendu) {
                $this->notifications->structureReactivee($structureSanitaireId);
            }
        });
    }

    /**
     * Réactive silencieusement si — et seulement si — la structure était SUSPENDUE pour IMPAYE et
     * que son solde vient de retomber à zéro. Appelée en fin d'imputation, dans la même transaction.
     */
    private function reactiverSiSoldee(int $structureSanitaireId): void
    {
        if ($this->soldeTotalOuvert($structureSanitaireId) > 0) {
            return;
        }

        $abonnement = AbonnementStructure::query()
            ->where('structure_sanitaire_id', $structureSanitaireId)
            ->lockForUpdate()
            ->first();

        if ($abonnement === null
            || $abonnement->statut !== StatutAbonnement::SUSPENDU
            || $abonnement->motif_suspension !== MotifSuspension::IMPAYE
        ) {
            return;
        }

        $this->reactiver($structureSanitaireId);
    }

    /** Somme des soldes positifs des factures encore ouvertes d'une structure. */
    private function soldeTotalOuvert(int $structureSanitaireId): int
    {
        return (int) FacturePartenaire::query()
            ->where('structure_sanitaire_id', $structureSanitaireId)
            ->whereIn('statut', self::STATUTS_OUVERTS)
            ->lockForUpdate()
            ->get()
            ->sum(fn (FacturePartenaire $facture) => max($facture->solde, 0));
    }
}
