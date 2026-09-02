<?php

namespace Tests\Feature;

use App\Models\AbonnementStructure;
use App\Models\CommissionTransaction;
use App\Models\PlanTarifaire;
use App\Models\StructureSanitaire;
use App\Services\CommissionService;
use App\Support\StatutAbonnement;
use App\Support\StatutCommission;
use Carbon\CarbonImmutable;
use Database\Seeders\BaremesCommissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Lot 2 — `CommissionService`. Cahier des charges : Prompt Lot 2 §R3/R4/R5/A1e. Les 8 vecteurs
 * nommés par le prompt. Barème réel du projet (`BaremesCommissionSeeder`) : 250/200/150/100 bps
 * pour les paliers 1 à 4 — repris ici plutôt que réinventé, pour ne jamais diverger du seeder.
 */
class CommissionServiceTest extends TestCase
{
    use RefreshDatabase;

    private function service(): CommissionService
    {
        return app(CommissionService::class);
    }

    private function structure(array $overrides = []): StructureSanitaire
    {
        return StructureSanitaire::create(array_merge([
            'nom' => 'Structure de test '.uniqid(),
            'type' => 'chu',
            'adresse' => 'Abidjan',
            'commune' => 'Cocody',
            'latitude' => 5.35,
            'longitude' => -3.98,
            'actif' => true,
        ], $overrides));
    }

    private function pharmacie(): StructureSanitaire
    {
        return $this->structure(['type' => 'pharmacie']);
    }

    /** Structure sous forfait 0 % (A1e) : plan `commission_incluse=true`, abonnement ACTIF. */
    private function structureForfaitZero(): StructureSanitaire
    {
        $s = $this->structure();

        $plan = PlanTarifaire::create([
            'code' => 'TEST_FORFAIT_0',
            'libelle' => 'Forfait 0 % de test',
            'montant_mensuel' => 50000,
            'devise' => 'XOF',
            'commission_incluse' => true,
            'actif' => true,
            'date_effet' => now()->subMonth()->toDateString(),
        ]);

        AbonnementStructure::create([
            'structure_sanitaire_id' => $s->id,
            'plan_tarifaire_id' => $plan->id,
            'rang_signature' => 1,
            'date_debut' => now()->subMonth()->toDateString(),
            'date_fin_essai' => now()->subMonth()->toDateString(),
            'statut' => StatutAbonnement::ACTIF,
        ]);

        return $s;
    }

    private function transactionAnterieure(StructureSanitaire $s, int $montantBrut, CarbonImmutable $date): CommissionTransaction
    {
        return CommissionTransaction::create([
            'structure_sanitaire_id' => $s->id,
            'reference_interne_paiement' => 'MS-PREALABLE-'.uniqid(),
            'montant_brut' => $montantBrut,
            'taux_bps_applique' => 0,
            'volume_cumule_au_calcul' => 0,
            'montant_commission' => 0,
            'montant_net_structure' => $montantBrut,
            'statut' => StatutCommission::CALCULEE,
            'date_transaction' => $date,
        ]);
    }

    // ── 1. Barème sélectionné selon le volume cumulé, aux 4 paliers ───────────────────────

    public function test_bareme_selectionne_selon_volume_cumule(): void
    {
        $this->seed(BaremesCommissionSeeder::class);
        // `now()` : le seeder pose `date_effet = today` — toute ancre antérieure à AUJOURD'HUI
        // (y compris « le 20 du mois en cours » si le test tourne après le 20) tomberait avant
        // l'entrée en vigueur du barème et ferait échouer `baremeActif()` à tort.
        $ancre = CarbonImmutable::now();

        $cas = [
            [0, 250],
            [250001, 200],
            [1000001, 150],
            [3000001, 100],
        ];

        foreach ($cas as [$volumeAnterieur, $tauxAttendu]) {
            $structure = $this->structure();

            if ($volumeAnterieur > 0) {
                $this->transactionAnterieure($structure, $volumeAnterieur, $ancre->subHour());
            }

            $commission = $this->service()->calculerEtEnregistrer([
                'structureSanitaireId' => $structure->id,
                'montantBrut' => 1000,
                'fraisPasserelle' => 0,
                'fraisPrestataire' => 0,
                'referenceInternePaiement' => 'MS-TEST-'.uniqid(),
                'dateTransaction' => $ancre,
                'regleEnLigne' => true,
            ]);

            $this->assertSame($volumeAnterieur, $commission->volume_cumule_au_calcul, "volume attendu {$volumeAnterieur}");
            $this->assertSame($tauxAttendu, $commission->taux_bps_applique, "taux attendu au volume {$volumeAnterieur}");
        }
    }

    // ── 2. Pharmacie hors ligne : commission nulle ─────────────────────────────────────────

    public function test_pharmacie_hors_ligne_commission_nulle(): void
    {
        $this->seed(BaremesCommissionSeeder::class);
        $pharmacie = $this->pharmacie();

        $commission = $this->service()->calculerEtEnregistrer([
            'structureSanitaireId' => $pharmacie->id,
            'montantBrut' => 10000,
            'fraisPasserelle' => 0,
            'fraisPrestataire' => 0,
            'referenceInternePaiement' => 'MS-PHARMA-HL-'.uniqid(),
            'dateTransaction' => now(),
            'regleEnLigne' => false,
        ]);

        $this->assertSame(0, $commission->montant_commission);
        $this->assertSame(0, $commission->taux_bps_applique);
        $this->assertSame(10000, $commission->montant_net_structure);
    }

    // ── 3. Pharmacie en ligne : commission normale ─────────────────────────────────────────

    public function test_pharmacie_en_ligne_commission_normale(): void
    {
        $this->seed(BaremesCommissionSeeder::class);
        $pharmacie = $this->pharmacie();

        $commission = $this->service()->calculerEtEnregistrer([
            'structureSanitaireId' => $pharmacie->id,
            'montantBrut' => 10000,
            'fraisPasserelle' => 0,
            'fraisPrestataire' => 0,
            'referenceInternePaiement' => 'MS-PHARMA-EL-'.uniqid(),
            'dateTransaction' => now(),
            'regleEnLigne' => true,
        ]);

        $this->assertSame(250, $commission->taux_bps_applique, 'Volume 0 => palier 1 (250 bps).');
        $this->assertSame(250, $commission->montant_commission, '10000 x 250/10000 = 250.');
    }

    // ── 4. Forfait 0 % : commission nulle, ligne enregistrée quand même ────────────────────

    public function test_forfait_zero_pourcent_commission_nulle_mais_ligne_enregistree(): void
    {
        $this->seed(BaremesCommissionSeeder::class);
        $structure = $this->structureForfaitZero();

        $commission = $this->service()->calculerEtEnregistrer([
            'structureSanitaireId' => $structure->id,
            'montantBrut' => 20000,
            'fraisPasserelle' => 100,
            'fraisPrestataire' => 200,
            'referenceInternePaiement' => 'MS-FORFAIT0-'.uniqid(),
            'dateTransaction' => now(),
            'regleEnLigne' => true,
        ]);

        $this->assertSame(0, $commission->montant_commission);
        $this->assertSame(0, $commission->taux_bps_applique);
        $this->assertSame(19700, $commission->montant_net_structure);
        $this->assertSame(1, CommissionTransaction::count(), 'La ligne doit exister malgré la commission nulle.');
    }

    // ── 5. Montants équilibrés, plusieurs cas dont un arrondi ──────────────────────────────

    public function test_montants_equilibres(): void
    {
        $this->seed(BaremesCommissionSeeder::class);

        $cas = [
            ['montantBrut' => 10000, 'fraisPasserelle' => 50, 'fraisPrestataire' => 100],
            ['montantBrut' => 460, 'fraisPasserelle' => 0, 'fraisPrestataire' => 0],
            ['montantBrut' => 999999, 'fraisPasserelle' => 1234, 'fraisPrestataire' => 5678],
        ];

        foreach ($cas as $c) {
            $structure = $this->structure();

            $commission = $this->service()->calculerEtEnregistrer([
                'structureSanitaireId' => $structure->id,
                'montantBrut' => $c['montantBrut'],
                'fraisPasserelle' => $c['fraisPasserelle'],
                'fraisPrestataire' => $c['fraisPrestataire'],
                'referenceInternePaiement' => 'MS-EQ-'.uniqid(),
                'dateTransaction' => now(),
                'regleEnLigne' => true,
            ]);

            $this->assertSame(
                $commission->montant_brut,
                $commission->frais_passerelle + $commission->frais_prestataire
                    + $commission->montant_commission + $commission->montant_net_structure,
                "déséquilibre pour montantBrut={$c['montantBrut']}"
            );
        }
    }

    // ── 6. Arrondi commercial : ,5 arrondit au-dessus ──────────────────────────────────────

    public function test_arrondi_commercial(): void
    {
        $this->seed(BaremesCommissionSeeder::class);
        $structure = $this->structure();

        // 460 x 250 bps / 10000 = 11,5 pile — l'arrondi commercial doit donner 12, pas 11.
        $commission = $this->service()->calculerEtEnregistrer([
            'structureSanitaireId' => $structure->id,
            'montantBrut' => 460,
            'fraisPasserelle' => 0,
            'fraisPrestataire' => 0,
            'referenceInternePaiement' => 'MS-ARRONDI-'.uniqid(),
            'dateTransaction' => now(),
            'regleEnLigne' => true,
        ]);

        $this->assertSame(250, $commission->taux_bps_applique);
        $this->assertSame(12, $commission->montant_commission, '11,5 doit arrondir à 12 (arrondi commercial).');
    }

    // ── 7. Idempotence sur la même référence interne ───────────────────────────────────────

    public function test_idempotence_meme_reference_interne(): void
    {
        $this->seed(BaremesCommissionSeeder::class);
        $structure = $this->structure();
        $reference = 'MS-DOUBLON-'.uniqid();

        $premier = $this->service()->calculerEtEnregistrer([
            'structureSanitaireId' => $structure->id,
            'montantBrut' => 10000,
            'fraisPasserelle' => 50,
            'fraisPrestataire' => 100,
            'referenceInternePaiement' => $reference,
            'dateTransaction' => now(),
            'regleEnLigne' => true,
        ]);

        // Second appel : montant totalement différent. S'il était recalculé, la ligne changerait.
        $second = $this->service()->calculerEtEnregistrer([
            'structureSanitaireId' => $structure->id,
            'montantBrut' => 999999,
            'fraisPasserelle' => 0,
            'fraisPrestataire' => 0,
            'referenceInternePaiement' => $reference,
            'dateTransaction' => now(),
            'regleEnLigne' => true,
        ]);

        $this->assertSame($premier->id, $second->id);
        $this->assertSame($premier->montant_brut, $second->montant_brut, 'Aucun recalcul : le second appel ne doit rien changer.');
        $this->assertSame(1, CommissionTransaction::where('reference_interne_paiement', $reference)->count());
    }

    // ── 8. Le volume cumulé ne compte pas la transaction courante ─────────────────────────

    public function test_volume_cumule_ne_compte_pas_la_transaction_courante(): void
    {
        $this->seed(BaremesCommissionSeeder::class);
        $structure = $this->structure();
        $ancre = CarbonImmutable::now();

        // Volume déjà réalisé ce mois : 249000 (encore dans le palier 1, plafond 250000).
        $this->transactionAnterieure($structure, 249000, $ancre->subHour());

        // Cette transaction, une fois ajoutée, ferait franchir le palier 2 (>250000) — mais le
        // taux appliqué à ELLE doit rester celui d'AVANT le franchissement.
        $franchit = $this->service()->calculerEtEnregistrer([
            'structureSanitaireId' => $structure->id,
            'montantBrut' => 5000,
            'fraisPasserelle' => 0,
            'fraisPrestataire' => 0,
            'referenceInternePaiement' => 'MS-FRANCHIT-'.uniqid(),
            'dateTransaction' => $ancre,
            'regleEnLigne' => true,
        ]);

        $this->assertSame(249000, $franchit->volume_cumule_au_calcul);
        $this->assertSame(250, $franchit->taux_bps_applique, 'Le taux doit être celui AVANT franchissement.');

        // La transaction SUIVANTE, elle, voit le nouveau cumul (254000) et bascule au palier 2.
        $suivante = $this->service()->calculerEtEnregistrer([
            'structureSanitaireId' => $structure->id,
            'montantBrut' => 1000,
            'fraisPasserelle' => 0,
            'fraisPrestataire' => 0,
            'referenceInternePaiement' => 'MS-APRES-'.uniqid(),
            'dateTransaction' => $ancre,
            'regleEnLigne' => true,
        ]);

        $this->assertSame(254000, $suivante->volume_cumule_au_calcul);
        $this->assertSame(200, $suivante->taux_bps_applique);
    }
}
