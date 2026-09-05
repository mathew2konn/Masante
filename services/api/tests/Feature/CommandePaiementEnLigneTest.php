<?php

namespace Tests\Feature;

use App\Models\BaremeCommission;
use App\Models\Commande;
use App\Models\CommissionTransaction;
use App\Models\Medicament;
use App\Models\MembreFamille;
use App\Models\StructureSanitaire;
use App\Models\User;
use App\Services\CommissionService;
use App\Services\Medicament\ServiceCommande;
use App\Services\Medicament\ServiceTraitementCommande;
use App\Services\PrixMedicamentService;
use App\Services\SigneurPrincipalSortant;
use Database\Seeders\BaremesCommissionSeeder;
use Database\Seeders\PortailRolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * B3-d (F6, réécrit après B4 — VALIDÉ G5) — le règlement en ligne d'une commande emprunte le
 * canal RÉEL de B4-b, transposé terme à terme : checkout GeniusPay réel, vraie Facture Java créée
 * puis réutilisée, aucun drapeau (S7 : la disponibilité est une propriété de l'officine).
 *
 * `commandes` porte SON PROPRE règlement, JAMAIS `factures_patient` — voir `ServiceCommande` et
 * `plan.md` PLAN 2 §12 pour la correction de l'anticipation écrite pendant B4-b.
 */
class CommandePaiementEnLigneTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PortailRolesSeeder::class);
    }

    private const CHEMIN_MARCHAND = '*/api/v1/interne/geniuspay/marchands/*';

    private const CHEMIN_CHECKOUT = '*/api/v1/interne/geniuspay/paiements';

    private const CHEMIN_INVOICES = '*/api/v1/invoices';

    private const FACTURE_JAVA_ID = '33333333-3333-3333-3333-333333333333';

    private function officineConfiguree(string $identifiant = 'ETS700001', string $pays = 'CI'): StructureSanitaire
    {
        $s = StructureSanitaire::create([
            'nom' => 'Pharmacie Test', 'type' => 'pharmacie', 'adresse' => 'Abidjan',
            'commune' => 'Plateau', 'latitude' => 5.32, 'longitude' => -4.02, 'actif' => true,
        ]);
        $s->forceFill(['identifiant_national' => $identifiant, 'pays_code' => $pays])->save();

        return $s;
    }

    private function membre(User $user): MembreFamille
    {
        $membre = new MembreFamille([
            'nom' => 'Yao', 'prenom' => 'Awa', 'date_naissance' => '2000-01-01', 'sexe' => 'F',
        ]);
        $membre->user_id = $user->id;
        $membre->matricule_ivs = 'IVS-2026-CMD-'.uniqid();
        $membre->save();

        return $membre;
    }

    private function fakeMarchandConfigure(bool $configure = true): void
    {
        Http::fake([
            self::CHEMIN_MARCHAND => Http::response(['configure' => $configure], 200),
            self::CHEMIN_INVOICES => Http::response(['id' => self::FACTURE_JAVA_ID], 201),
            self::CHEMIN_CHECKOUT => Http::response([
                'referenceInterne' => 'MS-ETS700001-01K',
                'checkoutUrl' => 'https://sandbox.geniuspay.example/checkout/cmd',
            ], 200),
        ]);
    }

    /** Une commande ACCEPTÉE (préalable à tout règlement en ligne, F6), montant connu. */
    private function commandeAcceptee(StructureSanitaire $officine, User $patient, int $prixCfa = 500): Commande
    {
        $membre = $this->membre($patient);
        $medicament = Medicament::create([
            'nom_generique' => 'Paracétamol', 'nom_commercial' => 'Doliprane', 'dosage' => '500 mg',
            'categorie' => 'antalgique', 'ordonnance_requise' => false, 'disponible_generique' => true,
        ]);
        app(PrixMedicamentService::class)->releverPrix($medicament, $officine, $prixCfa, 'pharmacie_portail');

        $commande = app(ServiceCommande::class)->passer(
            $patient, $membre, $officine,
            [['medicament_id' => $medicament->id, 'quantite' => 1]],
            'retrait', null, null, null,
        );

        $pharmacien = User::factory()->create(['structure_id' => $officine->id]);
        $pharmacien->givePermissionTo(ServiceTraitementCommande::PERMISSION);
        app(ServiceTraitementCommande::class)->accepter($pharmacien, $commande);

        return $commande->fresh();
    }

    // ── disponibiliteEnLigne (S7) ───────────────────────────────────────────────────────────

    public function test_disponibilite_en_ligne_faux_sans_identifiant_national_sans_appel_reseau(): void
    {
        Http::fake(); // aucune route enregistrée : le moindre appel ferait échouer le test.

        $patient = User::factory()->create();
        Sanctum::actingAs($patient);
        $officine = StructureSanitaire::create([
            'nom' => 'Sans identifiant', 'type' => 'pharmacie', 'adresse' => 'Abidjan',
            'commune' => 'Plateau', 'latitude' => 5.32, 'longitude' => -4.02, 'actif' => true,
        ]);
        $commande = $this->commandeAcceptee($officine, $patient);

        $this->getJson("/api/v1/commandes/{$commande->id}/paiement-en-ligne")
            ->assertOk()
            ->assertJsonPath('disponible', false);

        Http::assertNothingSent();
    }

    public function test_disponibilite_en_ligne_vrai_si_marchand_configure(): void
    {
        $this->fakeMarchandConfigure(true);
        $patient = User::factory()->create();
        Sanctum::actingAs($patient);
        $officine = $this->officineConfiguree();
        $commande = $this->commandeAcceptee($officine, $patient);

        $this->getJson("/api/v1/commandes/{$commande->id}/paiement-en-ligne")
            ->assertOk()
            ->assertJsonPath('disponible', true);
    }

    public function test_disponibilite_en_ligne_anti_idor(): void
    {
        $proprietaire = User::factory()->create();
        $officine = $this->officineConfiguree();
        $commande = $this->commandeAcceptee($officine, $proprietaire);

        Sanctum::actingAs(User::factory()->create());

        $this->getJson("/api/v1/commandes/{$commande->id}/paiement-en-ligne")->assertForbidden();
    }

    // ── ouvrirPaiementEnLigne (F6) ──────────────────────────────────────────────────────────

    public function test_ouvrir_paiement_en_ligne_cree_une_vraie_facture_java_et_ouvre_un_checkout(): void
    {
        $this->fakeMarchandConfigure(true);
        $patient = User::factory()->create();
        Sanctum::actingAs($patient);
        $officine = $this->officineConfiguree();
        $commande = $this->commandeAcceptee($officine, $patient, 500);

        $this->postJson("/api/v1/commandes/{$commande->id}/paiement-en-ligne")
            ->assertOk()
            ->assertJsonPath('checkout_url', 'https://sandbox.geniuspay.example/checkout/cmd');

        $this->assertDatabaseHas('commandes', [
            'id' => $commande->id,
            'commande_geniuspay_id' => self::FACTURE_JAVA_ID,
            'mode_reglement' => 'en_ligne',
        ]);
        // Aucun règlement encore : seule la notification confirmera (S6, transposé).
        $this->assertNull($commande->fresh()->regle_le);

        Http::assertSentCount(3); // marchand + invoices + checkout
    }

    public function test_ouvrir_paiement_en_ligne_refuse_avant_acceptation(): void
    {
        $this->fakeMarchandConfigure(true);
        $patient = User::factory()->create();
        Sanctum::actingAs($patient);
        $officine = $this->officineConfiguree();
        $membre = $this->membre($patient);
        $medicament = Medicament::create([
            'nom_generique' => 'Paracétamol', 'nom_commercial' => 'Doliprane', 'dosage' => '500 mg',
            'categorie' => 'antalgique', 'ordonnance_requise' => false, 'disponible_generique' => true,
        ]);
        app(PrixMedicamentService::class)->releverPrix($medicament, $officine, 500, 'pharmacie_portail');

        // EN_ATTENTE, jamais acceptée.
        $commande = app(ServiceCommande::class)->passer(
            $patient, $membre, $officine,
            [['medicament_id' => $medicament->id, 'quantite' => 1]],
            'retrait', null, null, null,
        );

        $this->postJson("/api/v1/commandes/{$commande->id}/paiement-en-ligne")
            ->assertUnprocessable();

        Http::assertNothingSent();
    }

    public function test_deuxieme_checkout_reutilise_la_meme_facture_java(): void
    {
        $this->fakeMarchandConfigure(true);
        $patient = User::factory()->create();
        Sanctum::actingAs($patient);
        $officine = $this->officineConfiguree();
        $commande = $this->commandeAcceptee($officine, $patient);

        $this->postJson("/api/v1/commandes/{$commande->id}/paiement-en-ligne")->assertOk();
        $this->postJson("/api/v1/commandes/{$commande->id}/paiement-en-ligne")->assertOk();

        // marchand+invoices+checkout au premier appel, checkout SEUL au second — `estConfigure()`
        // sert son cache de 5 minutes (S7) et aucune seconde Facture Java n'est créée (patron B4-b).
        Http::assertSentCount(4);
        $this->assertSame(
            1,
            Http::recorded(fn ($req) => str_contains($req->url(), '/api/v1/invoices'))->count(),
        );
    }

    // ── confirmerReglementEnLigne (S6, transposé) — le règlement, la commission automatique,
    //    l'idempotence, et le défaut de couplage corrigé au G0 ─────────────────────────────

    public function test_confirmer_reglement_en_ligne_regle_la_commande_et_declenche_la_commission(): void
    {
        $this->fakeMarchandConfigure(true);
        $patient = User::factory()->create();
        Sanctum::actingAs($patient);
        $officine = $this->officineConfiguree();
        $commande = $this->commandeAcceptee($officine, $patient, 500);
        $this->postJson("/api/v1/commandes/{$commande->id}/paiement-en-ligne")->assertOk();

        BaremesCommissionSeeder::class;
        $this->seedBaremes();

        app(ServiceCommande::class)->confirmerReglementEnLigne($commande->id, 'geniuspay-paiement-test-1', now()->toIso8601String());

        $this->assertNotNull($commande->fresh()->regle_le);
        $this->assertSame('geniuspay-paiement-test-1', $commande->fresh()->reference_reglement);

        // La commission n'est PAS déclenchée par confirmerReglementEnLigne() elle-même — elle
        // suit le mécanisme GÉNÉRIQUE de B4-a (PaiementNotificationController), exercé ici
        // directement pour prouver qu'il ne sait même pas qu'une commande existe.
        app(CommissionService::class)->calculerEtEnregistrer([
            'referenceInternePaiement' => 'geniuspay-paiement-test-1',
            'structureSanitaireId' => $officine->id,
            'montantBrut' => 500,
            'fraisPasserelle' => 0,
            'fraisPrestataire' => 0,
            'fraisConnus' => true,
            'dateTransaction' => now(),
            'regleEnLigne' => true,
        ]);

        $this->assertDatabaseHas('commissions_transaction', [
            'structure_sanitaire_id' => $officine->id,
            'montant_brut' => 500,
            'reference_interne_paiement' => 'geniuspay-paiement-test-1',
        ]);
    }

    public function test_rejeu_de_la_notification_ne_double_pas_le_reglement(): void
    {
        $this->fakeMarchandConfigure(true);
        $patient = User::factory()->create();
        Sanctum::actingAs($patient);
        $officine = $this->officineConfiguree();
        $commande = $this->commandeAcceptee($officine, $patient, 500);
        $this->postJson("/api/v1/commandes/{$commande->id}/paiement-en-ligne")->assertOk();

        $service = app(ServiceCommande::class);
        $service->confirmerReglementEnLigne($commande->id, 'geniuspay-paiement-test-2', now()->toIso8601String());
        $premiereDate = $commande->fresh()->regle_le;

        // Rejeu — même paiementId, potentiellement une date différente (relance réseau) :
        // le règlement déjà acté ne bouge pas.
        $service->confirmerReglementEnLigne($commande->id, 'geniuspay-paiement-test-2', now()->addMinute()->toIso8601String());

        $this->assertTrue($premiereDate->equalTo($commande->fresh()->regle_le));
    }

    /**
     * B3-d (G0 de la reprise) — LE DÉFAUT DE COUPLAGE CORRIGÉ : un barème de commission manquant
     * ne bloque PLUS le règlement d'une commande dans le même appel de notification.
     */
    public function test_bareme_manquant_n_empeche_pas_le_reglement_de_la_commande(): void
    {
        $this->fakeMarchandConfigure(true);
        $patient = User::factory()->create();
        Sanctum::actingAs($patient);
        $officine = $this->officineConfiguree();
        $commande = $this->commandeAcceptee($officine, $patient, 500);
        $this->postJson("/api/v1/commandes/{$commande->id}/paiement-en-ligne")->assertOk();

        $this->assertSame(0, CommissionTransaction::count()); // aucun barème seedé

        $principal = $this->principalSigneCommande();

        $reponse = $this->withHeaders($principal['entetes'])->postJson(
            '/api/interne/v1/paiements/notification',
            [
                'paiementId' => 'geniuspay-paiement-test-3',
                'statut' => 'SUCCESS',
                'canal' => 'geniuspay',
                'etablissementRef' => 'CI-ETS700001',
                'montant' => 500,
                'fraisPasserelle' => 0,
                'fraisPrestataire' => 0,
                'dateTransaction' => now()->toIso8601String(),
                'correlationId' => 'commande:'.$commande->id,
            ],
        );

        $reponse->assertOk();
        // Le règlement a eu lieu MALGRÉ l'absence de barème — c'est la garantie du try/catch.
        $this->assertNotNull($commande->fresh()->regle_le);
        $this->assertSame(0, CommissionTransaction::count());
    }

    private function seedBaremes(): void
    {
        BaremeCommission::create([
            'palier_ordre' => 1, 'volume_mensuel_min' => 0, 'volume_mensuel_max' => null,
            'taux_bps' => 250, 'date_effet' => now()->subDay(),
        ]);
    }

    /** Signe un principal SYSTEME pour l'endpoint interne, comme le ferait Java. */
    private function principalSigneCommande(): array
    {
        $signeur = app(SigneurPrincipalSortant::class);
        $entetes = $signeur->signer('POST', '/api/interne/v1/paiements/notification', 'test-b3d', ['SYSTEME']);

        return ['entetes' => $entetes];
    }
}
