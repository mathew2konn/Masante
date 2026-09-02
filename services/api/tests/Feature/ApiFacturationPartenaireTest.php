<?php

namespace Tests\Feature;

use App\Models\CommissionTransaction;
use App\Models\FacturePartenaire;
use App\Models\ReglementFacturePartenaire;
use App\Models\StructureSanitaire;
use App\Models\User;
use App\Support\MoyenReglement;
use App\Support\StatutCommission;
use App\Support\StatutFacturePartenaire;
use Database\Seeders\BaremesCommissionSeeder;
use Database\Seeders\PortailRolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Lot 8 — API de facturation partenaire (contrôleurs et routes uniquement, aucun calcul).
 *
 * Isolation stricte : un établissement ne voit jamais les données d'un autre (interdiction n°3) ;
 * seul le back-office déclare un règlement (interdiction n°2, risque de fraude direct sinon).
 */
class ApiFacturationPartenaireTest extends TestCase
{
    use RefreshDatabase;

    private function structure(): StructureSanitaire
    {
        return StructureSanitaire::create([
            'nom' => 'Structure '.uniqid(), 'type' => 'chu', 'adresse' => 'Abidjan',
            'commune' => 'Cocody', 'latitude' => 5.35, 'longitude' => -3.98, 'actif' => true,
        ]);
    }

    private function gestionnaire(StructureSanitaire $s): User
    {
        $this->seed(PortailRolesSeeder::class);
        $user = User::factory()->create(['structure_id' => $s->id]);
        $user->assignRole('gestionnaire_etablissement');

        return $user;
    }

    private function backoffice(): User
    {
        $this->seed(PortailRolesSeeder::class);
        $user = User::factory()->create();
        $user->assignRole('admin_ivoirsante');

        return $user;
    }

    private static int $compteurPeriode = 0;

    private function facture(StructureSanitaire $s, array $overrides = []): FacturePartenaire
    {
        // `UNIQUE(structure_sanitaire_id, periode_debut, periode_fin)` : chaque appel doit varier
        // la période, sinon deux factures de test pour la même structure se percutent.
        $mois = 1 + (self::$compteurPeriode++ % 12);
        $debut = sprintf('2026-%02d-01', $mois);
        $fin = date('Y-m-t', strtotime($debut));

        return FacturePartenaire::create(array_merge([
            'structure_sanitaire_id' => $s->id,
            'reference' => 'FP-'.uniqid(),
            'periode_debut' => $debut,
            'periode_fin' => $fin,
            'montant_abonnement' => 15000,
            'montant_commissions' => 0,
            'montant_total' => 15000,
            'montant_regle' => 0,
            'statut' => StatutFacturePartenaire::EMISE,
            'date_emission' => '2026-09-01',
            'date_echeance' => '2026-09-16',
        ], $overrides));
    }

    private function commission(StructureSanitaire $s, int $montantBrut = 6000): CommissionTransaction
    {
        return CommissionTransaction::create([
            'structure_sanitaire_id' => $s->id,
            'reference_interne_paiement' => 'MS-'.uniqid(),
            'montant_brut' => $montantBrut,
            'frais_passerelle' => 50,
            'frais_prestataire' => 160,
            'taux_bps_applique' => 250,
            'volume_cumule_au_calcul' => 0,
            'montant_commission' => 150,
            'montant_net_structure' => $montantBrut - 50 - 160 - 150,
            'statut' => StatutCommission::CALCULEE,
            'date_transaction' => now(),
        ]);
    }

    // ── 1. Isolation stricte entre établissements ───────────────────────────────────────────

    public function test_etablissement_ne_voit_que_ses_propres_donnees(): void
    {
        $s1 = $this->structure();
        $s2 = $this->structure();
        $factureAutrui = $this->facture($s2);

        Sanctum::actingAs($this->gestionnaire($s1));

        $this->getJson('/api/v1/etablissement/facturation/factures')
            ->assertOk()
            ->assertJsonMissing(['reference' => $factureAutrui->reference]);

        // Anti-énumération : 404, jamais 403 — un 403 confirmerait qu'une facture existe ailleurs.
        $this->getJson("/api/v1/etablissement/facturation/factures/{$factureAutrui->id}")
            ->assertNotFound();
    }

    // ── 2. Établissement ne peut jamais déclarer son propre règlement ──────────────────────

    public function test_etablissement_ne_peut_pas_declarer_son_propre_reglement(): void
    {
        $s = $this->structure();
        $facture = $this->facture($s);

        Sanctum::actingAs($this->gestionnaire($s));

        $this->postJson("/api/v1/backoffice/facturation/factures/{$facture->id}/reglements", [
            'montant' => 15000, 'moyen' => 'VIREMENT',
        ])->assertForbidden();

        $this->assertDatabaseCount('reglements_facture_partenaire', 0);

        // Le chemin légitime (back-office) fonctionne bien, pour prouver que le refus ci-dessus
        // vient du rôle et non d'un défaut de la route/du service.
        Sanctum::actingAs($this->backoffice());
        $this->postJson("/api/v1/backoffice/facturation/factures/{$facture->id}/reglements", [
            'montant' => 15000, 'moyen' => 'VIREMENT',
        ])->assertCreated();
    }

    // ── 3. Pagination selon la convention du projet ─────────────────────────────────────────

    public function test_pagination_respecte_la_convention_du_projet(): void
    {
        $s = $this->structure();
        for ($i = 0; $i < 3; $i++) {
            $this->facture($s, ['reference' => 'FP-'.uniqid()]);
        }

        Sanctum::actingAs($this->gestionnaire($s));

        $reponse = $this->getJson('/api/v1/etablissement/facturation/factures')->assertOk();

        // Enveloppe de pagination Eloquent BRUTE, retournée telle quelle (précédent exact :
        // Api\V1\Portail\RendezVousController::index()) — pas une Resource collection.
        $reponse->assertJsonStructure(['data', 'current_page', 'per_page', 'total', 'last_page']);
        $this->assertSame(3, $reponse->json('total'));
    }

    // ── 4. Le tableau de bord reflète le palier courant ─────────────────────────────────────

    public function test_tableau_de_bord_reflete_le_palier_courant(): void
    {
        $this->seed(BaremesCommissionSeeder::class);
        $s = $this->structure();
        $this->commission($s, 6000);

        Sanctum::actingAs($this->gestionnaire($s));

        $reponse = $this->getJson('/api/v1/etablissement/facturation/tableau-bord')->assertOk();

        $this->assertSame(6000, $reponse->json('volume_cumule_mois'));
        $this->assertNotNull($reponse->json('bareme_actif'));
    }

    // ── 5. Le détail d'une facture inclut ses règlements ────────────────────────────────────

    public function test_detail_facture_inclut_les_reglements(): void
    {
        $s = $this->structure();
        $facture = $this->facture($s, [
            'montant_regle' => 5000, 'statut' => StatutFacturePartenaire::PARTIELLEMENT_REGLEE,
        ]);
        ReglementFacturePartenaire::create([
            'facture_partenaire_id' => $facture->id, 'montant' => 5000,
            'moyen' => MoyenReglement::VIREMENT, 'date_reglement' => now(),
        ]);

        Sanctum::actingAs($this->gestionnaire($s));

        $this->getJson("/api/v1/etablissement/facturation/factures/{$facture->id}")
            ->assertOk()
            ->assertJsonCount(1, 'reglements')
            ->assertJsonPath('reglements.0.montant', 5000);
    }

    // ── 6. Aucun champ médical dans les réponses ────────────────────────────────────────────

    public function test_aucun_champ_medical_dans_les_reponses(): void
    {
        $this->seed(BaremesCommissionSeeder::class);
        $s = $this->structure();
        $facture = $this->facture($s);
        ReglementFacturePartenaire::create([
            'facture_partenaire_id' => $facture->id, 'montant' => 1000,
            'moyen' => MoyenReglement::ESPECES, 'date_reglement' => now(),
        ]);
        $this->commission($s);

        Sanctum::actingAs($this->gestionnaire($s));

        $interdits = ['symptome', 'diagnostic', 'ordonnance', 'medicament', 'triage', 'antecedent'];

        foreach ([
            '/api/v1/etablissement/facturation/tableau-bord',
            '/api/v1/etablissement/facturation/transactions',
            '/api/v1/etablissement/facturation/factures',
            "/api/v1/etablissement/facturation/factures/{$facture->id}",
        ] as $route) {
            $corps = strtolower($this->getJson($route)->assertOk()->getContent());
            foreach ($interdits as $mot) {
                $this->assertStringNotContainsString($mot, $corps, "Champ médical détecté sur {$route} : {$mot}");
            }
        }
    }
}
