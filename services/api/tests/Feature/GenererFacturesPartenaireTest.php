<?php

namespace Tests\Feature;

use App\Models\AbonnementStructure;
use App\Models\CommissionTransaction;
use App\Models\FacturePartenaire;
use App\Models\PlanTarifaire;
use App\Models\StructureSanitaire;
use App\Support\StatutAbonnement;
use App\Support\StatutCommission;
use App\Support\StatutFacturePartenaire;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Lot 3 — `GenererFacturesPartenaireCommand`. Les 7 vecteurs nommés par le prompt, adaptés à la
 * décision (b) du propriétaire (2026-08-27) : pas de `date_prochaine_facturation` stockée, la date
 * de facturation suivante est DÉRIVÉE de la dernière `facture_partenaire` de la structure.
 */
class GenererFacturesPartenaireTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function structure(): StructureSanitaire
    {
        return StructureSanitaire::create([
            'nom' => 'Structure de test '.uniqid(),
            'type' => 'chu',
            'adresse' => 'Abidjan',
            'commune' => 'Cocody',
            'latitude' => 5.35,
            'longitude' => -3.98,
            'actif' => true,
        ]);
    }

    private function plan(int $montantMensuel = 15000): PlanTarifaire
    {
        return PlanTarifaire::create([
            'code' => 'TEST_PLAN_'.uniqid(),
            'libelle' => 'Plan de test',
            'montant_mensuel' => $montantMensuel,
            'devise' => 'XOF',
            'commission_incluse' => false,
            'actif' => true,
            'date_effet' => now()->subYear()->toDateString(),
        ]);
    }

    private function abonnement(StructureSanitaire $s, array $overrides = []): AbonnementStructure
    {
        return AbonnementStructure::create(array_merge([
            'structure_sanitaire_id' => $s->id,
            'plan_tarifaire_id' => $this->plan()->id,
            'rang_signature' => 1,
            'date_debut' => now()->subMonths(3)->toDateString(),
            'date_fin_essai' => now()->subMonths(3)->addDays(30)->toDateString(),
            'statut' => StatutAbonnement::ACTIF,
        ], $overrides));
    }

    private function commission(
        StructureSanitaire $s,
        int $montantCommission,
        \DateTimeInterface $dateTransaction,
        StatutCommission $statut = StatutCommission::CALCULEE,
        ?int $facturePartenaireId = null,
    ): CommissionTransaction {
        return CommissionTransaction::create([
            'structure_sanitaire_id' => $s->id,
            'facture_partenaire_id' => $facturePartenaireId,
            'reference_interne_paiement' => 'MS-'.uniqid(),
            'montant_brut' => $montantCommission,
            'taux_bps_applique' => 100,
            'volume_cumule_au_calcul' => 0,
            'montant_commission' => $montantCommission,
            'montant_net_structure' => 0,
            'statut' => $statut,
            'date_transaction' => $dateTransaction,
        ]);
    }

    // ── 1. Facture générée avec abonnement et commissions ──────────────────────────────────

    public function test_facture_generee_avec_abonnement_et_commissions(): void
    {
        $s = $this->structure();
        $this->abonnement($s, [
            'date_debut' => now()->subMonths(2)->toDateString(),
            'date_fin_essai' => now()->subMonths(2)->toDateString(), // essai déjà terminé
        ]);
        $this->commission($s, 4500, now()->subDays(10));

        $this->artisan('factures:generer-partenaires')->assertExitCode(0);

        $facture = FacturePartenaire::sole();
        $this->assertSame(15000, $facture->montant_abonnement);
        $this->assertSame(4500, $facture->montant_commissions);
        $this->assertSame(19500, $facture->montant_total);
        $this->assertSame(StatutFacturePartenaire::EMISE, $facture->statut);
    }

    // ── 2. Essai en cours : montant d'abonnement à zéro ─────────────────────────────────────

    public function test_essai_en_cours_abonnement_zero(): void
    {
        $s = $this->structure();
        $this->abonnement($s, [
            'date_debut' => now()->subDays(20)->toDateString(),
            'date_fin_essai' => now()->addDays(10)->toDateString(), // encore en essai demain
        ]);
        // Une commission pour que le montant total ne soit pas nul : sinon aucune facture
        // n'est créée (point 6), et ce test ne pourrait rien vérifier sur `montant_abonnement`.
        $this->commission($s, 1200, now()->subDays(5));

        $this->artisan('factures:generer-partenaires')->assertExitCode(0);

        $facture = FacturePartenaire::sole();
        $this->assertSame(0, $facture->montant_abonnement, 'En essai sur toute la période : abonnement à 0.');
        $this->assertSame(1200, $facture->montant_commissions);
    }

    // ── 3. Montant nul : aucune facture générée ─────────────────────────────────────────────

    public function test_montant_nul_ne_genere_pas_de_facture(): void
    {
        $s = $this->structure();
        $this->abonnement($s, [
            'date_debut' => now()->subDays(5)->toDateString(),
            'date_fin_essai' => now()->addDays(25)->toDateString(),
        ]);
        // Aucune commission : abonnement à 0 (essai) + commissions à 0 = montant total nul.

        $this->artisan('factures:generer-partenaires')->assertExitCode(0);

        $this->assertSame(0, FacturePartenaire::count());
    }

    // ── 4. La commission facturée pointe la facture générée ────────────────────────────────

    public function test_commission_facturee_pointe_bien_la_facture_generee(): void
    {
        $s = $this->structure();
        $this->abonnement($s, [
            'date_debut' => now()->subMonths(2)->toDateString(),
            'date_fin_essai' => now()->subMonths(2)->toDateString(),
        ]);
        $commission = $this->commission($s, 2000, now()->subDays(3));

        $this->artisan('factures:generer-partenaires')->assertExitCode(0);

        $facture = FacturePartenaire::sole();
        $commission->refresh();

        $this->assertSame(StatutCommission::FACTUREE, $commission->statut);
        $this->assertSame($facture->id, $commission->facture_partenaire_id);
    }

    // ── 5. Double exécution sur la même période : pas de doublon ──────────────────────────

    public function test_double_execution_meme_periode_ne_duplique_pas(): void
    {
        $s = $this->structure();
        $this->abonnement($s, [
            'date_debut' => now()->subMonths(2)->toDateString(),
            'date_fin_essai' => now()->subMonths(2)->toDateString(),
        ]);
        $this->commission($s, 3000, now()->subDays(2));

        $this->artisan('factures:generer-partenaires')->assertExitCode(0);
        $this->assertSame(1, FacturePartenaire::count());

        $this->artisan('factures:generer-partenaires')->assertExitCode(0);
        $this->assertSame(1, FacturePartenaire::count(), 'Un second passage le même jour ne doit rien dupliquer.');
    }

    // ── 6. L'ancre dérivée avance d'un mois (remplace la colonne stockée — décision (b)) ───

    public function test_date_prochaine_facturation_avancee_d_un_mois(): void
    {
        Carbon::setTestNow(CarbonImmutable::parse('2026-09-15'));

        $s = $this->structure();
        $this->abonnement($s, [
            'date_debut' => '2026-06-01',
            'date_fin_essai' => '2026-06-01',
        ]);
        $this->commission($s, 5000, CarbonImmutable::parse('2026-09-10'));

        $this->artisan('factures:generer-partenaires')->assertExitCode(0);

        $premiere = FacturePartenaire::sole();
        $this->assertSame('2026-06-01', $premiere->periode_debut->toDateString());
        $this->assertSame('2026-09-14', $premiere->periode_fin->toDateString());

        // Un mois plus tard : l'ancre dérivée doit reprendre EXACTEMENT au lendemain de la
        // précédente periode_fin — ni recouvrement, ni trou, sans qu'aucune colonne n'ait été
        // « avancée » nulle part.
        Carbon::setTestNow(CarbonImmutable::parse('2026-10-15'));
        $this->commission($s, 3000, CarbonImmutable::parse('2026-10-10'));

        $this->artisan('factures:generer-partenaires')->assertExitCode(0);

        $this->assertSame(2, FacturePartenaire::count());
        $seconde = FacturePartenaire::where('id', '!=', $premiere->id)->sole();
        $this->assertSame('2026-09-15', $seconde->periode_debut->toDateString());
        $this->assertSame('2026-10-14', $seconde->periode_fin->toDateString());
    }

    // ── 7. Seules les commissions CALCULEE sont incluses ───────────────────────────────────

    public function test_seules_les_commissions_calculees_sont_incluses(): void
    {
        $s = $this->structure();
        $this->abonnement($s, [
            'date_debut' => now()->subMonths(2)->toDateString(),
            'date_fin_essai' => now()->subMonths(2)->toDateString(),
        ]);

        $factureAnterieure = FacturePartenaire::create([
            'structure_sanitaire_id' => $s->id,
            'reference' => 'FP-ANCIENNE-'.uniqid(),
            'periode_debut' => now()->subMonths(4)->toDateString(),
            'periode_fin' => now()->subMonths(3)->toDateString(),
            'montant_abonnement' => 15000,
            'montant_commissions' => 9999,
            'montant_total' => 24999,
            'statut' => StatutFacturePartenaire::PAYEE,
            'montant_regle' => 24999,
            'date_emission' => now()->subMonths(3)->toDateString(),
        ]);

        $this->commission($s, 4000, now()->subDays(5), StatutCommission::CALCULEE);
        $dejaFacturee = $this->commission($s, 9999, now()->subDays(5), StatutCommission::FACTUREE, $factureAnterieure->id);

        $this->artisan('factures:generer-partenaires')->assertExitCode(0);

        $nouvelle = FacturePartenaire::where('id', '!=', $factureAnterieure->id)->sole();

        $this->assertSame(4000, $nouvelle->montant_commissions, 'Seule la commission CALCULEE doit être comptée.');

        $dejaFacturee->refresh();
        $this->assertSame($factureAnterieure->id, $dejaFacturee->facture_partenaire_id, 'Une commission déjà FACTUREE ne doit jamais être réattribuée.');
    }
}
