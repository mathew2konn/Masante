<?php

namespace App\Console\Commands;

use App\Models\AbonnementStructure;
use App\Models\CommissionTransaction;
use App\Models\FacturePartenaire;
use App\Support\StatutAbonnement;
use App\Support\StatutCommission;
use App\Support\StatutFacturePartenaire;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Lot 3 — agrège abonnement et commissions du mois écoulé en UNE facture partenaire par structure.
 *
 * ═══ DÉCISION (b), PROPRIÉTAIRE, 2026-08-27 ═══
 * Le prompt d'origine suppose une colonne `abonnements_structure.date_prochaine_facturation` —
 * elle n'existe pas (Phase 0 de ce lot : recherchée dans le schéma réel et dans tout le projet,
 * introuvable). Plutôt qu'ajouter une migration que l'interdiction n°1 de ce lot exclut par
 * ailleurs, la date de facturation suivante est DÉRIVÉE, comme le solde d'une facture ou le statut
 * d'une vaccination ailleurs dans ce projet : `periode_fin + 1 jour` de la DERNIÈRE
 * `facture_partenaire` de la structure, ou `date_debut` de l'abonnement s'il n'en existe encore
 * aucune. Le point 5 du prompt (« avance `date_prochaine_facturation` d'un mois ») disparaît donc
 * purement et simplement : la facture qui vient d'être créée EST la nouvelle ancre, rien à avancer,
 * rien qui puisse diverger de la réalité qu'elle est censée refléter.
 *
 * ═══ LA GARDE `periodeDebut > periodeFin` REMPLACE LA SÉLECTION PAR COLONNE ═══
 * L'original sélectionnait les abonnements dont `date_prochaine_facturation` est « atteinte ou
 * dépassée ». Dérivée, cette condition devient : rien à facturer si l'ancre calculée dépasse déjà
 * hier. C'est aussi ce qui rend la commande idempotente sur une même journée : un second passage le
 * même jour recalcule la même ancre (la dernière facture n'a pas changé de date), qui tombe
 * exactement sur `aujourd'hui`, après `periode_fin = veille d'aujourd'hui` — donc `periodeDebut >
 * periodeFin`, donc rien n'est créé.
 *
 *   XDEBUG_MODE=off php artisan factures:generer-partenaires
 */
class GenererFacturesPartenaireCommand extends Command
{
    protected $signature = 'factures:generer-partenaires';

    protected $description = 'Génère les factures partenaires (abonnement + commissions) du mois écoulé';

    /** Délai de règlement, en jours. Donnée unique du service, jamais dupliquée ailleurs. */
    private const DELAI_ECHEANCE_JOURS = 15;

    public function handle(): int
    {
        $aujourdhui = CarbonImmutable::today();
        $periodeFin = $aujourdhui->subDay();

        $facturesCreees = 0;
        $montantTotalGenere = 0;
        $sautees = [];

        AbonnementStructure::query()
            ->where('statut', '!=', StatutAbonnement::RESILIE->value)
            ->with('planTarifaire')
            ->chunkById(200, function ($abonnements) use ($periodeFin, $aujourdhui, &$facturesCreees, &$montantTotalGenere, &$sautees) {
                foreach ($abonnements as $abonnement) {
                    $resultat = $this->traiter($abonnement, $periodeFin, $aujourdhui);

                    if ($resultat === null) {
                        continue;
                    }

                    if ($resultat['creee']) {
                        $facturesCreees++;
                        $montantTotalGenere += $resultat['montant'];
                    } else {
                        $sautees[] = "structure {$abonnement->structure_sanitaire_id} : {$resultat['motif']}";
                    }
                }
            });

        $this->info("{$facturesCreees} facture(s) générée(s), {$montantTotalGenere} F XOF au total.");
        if ($sautees !== []) {
            $this->line('Structures sautées :');
            foreach ($sautees as $ligne) {
                $this->line('  - '.$ligne);
            }
        }

        return self::SUCCESS;
    }

    /**
     * Traite un abonnement. Retourne `null` s'il n'y a rien à faire (période déjà couverte),
     * sinon `['creee' => bool, 'montant' => int, 'motif' => string]`.
     *
     * @return array{creee:bool,montant:int,motif:string}|null
     */
    private function traiter(AbonnementStructure $abonnement, CarbonImmutable $periodeFin, CarbonImmutable $aujourdhui): ?array
    {
        $structureSanitaireId = $abonnement->structure_sanitaire_id;

        $derniereFacture = FacturePartenaire::query()
            ->where('structure_sanitaire_id', $structureSanitaireId)
            ->orderByDesc('periode_fin')
            ->first();

        $periodeDebut = $derniereFacture !== null
            ? CarbonImmutable::parse($derniereFacture->periode_fin)->addDay()
            : CarbonImmutable::parse($abonnement->date_debut);

        if ($periodeDebut->gt($periodeFin)) {
            // Rien de neuf depuis la dernière facture (ou abonnement souscrit après hier) :
            // ni une omission, ni une erreur — la période est simplement déjà à jour.
            return null;
        }

        // §3 : abonnement encore en essai SUR TOUTE la période — pas de proratisation, réponse
        // binaire sur la borne temporelle réelle (`date_fin_essai`), jamais sur le `statut` courant
        // (qui peut avoir changé depuis sans que la période facturée, elle, ait bougé).
        $dateFinEssai = CarbonImmutable::parse($abonnement->date_fin_essai);
        $essaiCouvreLaPeriode = $periodeFin->lte($dateFinEssai);

        $montantAbonnement = $essaiCouvreLaPeriode ? 0 : (int) $abonnement->planTarifaire->montant_mensuel;

        $commissionsIncluses = CommissionTransaction::query()
            ->where('structure_sanitaire_id', $structureSanitaireId)
            ->where('statut', StatutCommission::CALCULEE->value)
            ->whereBetween('date_transaction', [$periodeDebut->startOfDay(), $periodeFin->endOfDay()])
            ->get();

        $montantCommissions = (int) $commissionsIncluses->sum('montant_commission');
        $montantTotal = $montantAbonnement + $montantCommissions;

        if ($montantTotal === 0) {
            // Palier 0 pur (abonnement en essai, aucune commission) ou mois sans aucune activité :
            // une facture à zéro franc ne sert à rien et polluerait l'historique.
            return ['creee' => false, 'montant' => 0, 'motif' => 'montant total nul'];
        }

        DB::transaction(function () use (
            $abonnement, $structureSanitaireId, $periodeDebut, $periodeFin,
            $montantAbonnement, $montantCommissions, $montantTotal,
            $commissionsIncluses, $aujourdhui
        ) {
            // Sérialise deux exécutions concurrentes de cette commande pour la même structure.
            AbonnementStructure::query()->whereKey($abonnement->id)->lockForUpdate()->firstOrFail();

            $facture = FacturePartenaire::create([
                'structure_sanitaire_id' => $structureSanitaireId,
                'reference' => 'FP-'.$structureSanitaireId.'-'.$periodeDebut->format('Ym'),
                'periode_debut' => $periodeDebut->toDateString(),
                'periode_fin' => $periodeFin->toDateString(),
                'montant_abonnement' => $montantAbonnement,
                'montant_commissions' => $montantCommissions,
                'montant_total' => $montantTotal,
                'statut' => StatutFacturePartenaire::EMISE,
                'date_emission' => $aujourdhui->toDateString(),
                'date_echeance' => $aujourdhui->addDays(self::DELAI_ECHEANCE_JOURS)->toDateString(),
            ]);

            foreach ($commissionsIncluses as $commission) {
                $commission->update([
                    'facture_partenaire_id' => $facture->id,
                    'statut' => StatutCommission::FACTUREE,
                ]);
            }
        });

        return ['creee' => true, 'montant' => $montantTotal, 'motif' => ''];
    }
}
