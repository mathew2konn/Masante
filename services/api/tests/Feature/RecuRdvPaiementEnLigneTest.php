<?php

namespace Tests\Feature;

use App\Models\FacturePatient;
use App\Models\MembreFamille;
use App\Models\Paiement;
use App\Models\RendezVous;
use App\Models\ServiceEtablissement;
use App\Models\StructureSanitaire;
use App\Models\User;
use App\Services\RecuRdvService;
use App\Support\MomentPaiement;
use App\Support\StatutFacturePatient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * B4-b (ADR-056 §9, `plan.md` PLAN 3 §9) — le rendez-vous gagne un chemin de règlement RÉEL, à
 * CÔTÉ du chemin simulé de {@see RecuRdvPaiementTest} (aucun des deux n'est retiré, S7).
 *
 * Trois temps, jamais confondus : ouvrir (`ouvrirPaiementEnLigne`, patient) → payer (hors
 * plateforme, chez GeniusPay) → confirmer (`confirmerReglementEnLigne`, notification SEULE — S6,
 * jamais un retour d'application). Ce fichier prouve les deux premiers points HTTP-ement, le
 * troisième au niveau service (le contrôleur qui l'appelle réellement est couvert par
 * `CanalInternePaiementTest`).
 */
class RecuRdvPaiementEnLigneTest extends TestCase
{
    use RefreshDatabase;

    private const CHEMIN_MARCHAND = '*/api/v1/interne/geniuspay/marchands/*';

    private const CHEMIN_CHECKOUT = '*/api/v1/interne/geniuspay/paiements';

    private const CHEMIN_INVOICES = '*/api/v1/invoices';

    private const FACTURE_JAVA_ID = '22222222-2222-2222-2222-222222222222';

    private function structureConfiguree(string $identifiant = 'ETS500001', string $pays = 'CI'): StructureSanitaire
    {
        $s = StructureSanitaire::create([
            'nom' => 'CHU Test', 'type' => 'chu', 'adresse' => 'Abidjan', 'commune' => 'Cocody',
            'latitude' => 5.35, 'longitude' => -3.99, 'tarif_min_cfa' => 8000, 'actif' => true,
        ]);
        $s->forceFill(['identifiant_national' => $identifiant, 'pays_code' => $pays])->save();

        return $s;
    }

    private function service(StructureSanitaire $s, int $tarif = 15000): ServiceEtablissement
    {
        return ServiceEtablissement::create([
            'structure_id' => $s->id, 'nom_service' => 'Cardiologie', 'specialite' => 'cardiologie',
            'actif' => true, 'tarif_consultation_cfa' => $tarif,
        ]);
    }

    private function membre(User $user): MembreFamille
    {
        $membre = new MembreFamille([
            'nom' => 'Yao', 'prenom' => 'Awa', 'date_naissance' => '2000-01-01', 'sexe' => 'F',
        ]);
        $membre->user_id = $user->id;
        $membre->matricule_ivs = 'IVS-2026-RC-'.uniqid();
        $membre->save();

        return $membre;
    }

    private function rdv(MembreFamille $m, StructureSanitaire $s, ServiceEtablissement $sv): RendezVous
    {
        return RendezVous::create([
            'membre_id' => $m->id, 'structure_id' => $s->id, 'service_id' => $sv->id,
            'mode_attribution' => 'etablissement_attribue',
            'motif' => 'Suivi', 'date_souhaitee' => Carbon::tomorrow()->toDateString(), 'statut' => 'en_attente',
        ]);
    }

    private function fakeMarchandConfigure(bool $configure = true): void
    {
        Http::fake([
            self::CHEMIN_MARCHAND => Http::response(['configure' => $configure], 200),
            self::CHEMIN_INVOICES => Http::response(['id' => self::FACTURE_JAVA_ID], 201),
            self::CHEMIN_CHECKOUT => Http::response([
                'referenceInterne' => 'MS-ETS500001-01K',
                'checkoutUrl' => 'https://sandbox.geniuspay.example/checkout/abc',
            ], 200),
        ]);
    }

    // ── disponibiliteEnLigne (S7) ───────────────────────────────────────────────────────────

    public function test_disponibilite_en_ligne_faux_sans_identifiant_national_sans_appel_reseau(): void
    {
        Http::fake(); // aucune route enregistrée : le moindre appel ferait échouer le test.

        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $s = StructureSanitaire::create([
            'nom' => 'Sans identifiant', 'type' => 'chu', 'adresse' => 'Abidjan', 'commune' => 'Cocody',
            'latitude' => 5.35, 'longitude' => -3.99, 'tarif_min_cfa' => 8000, 'actif' => true,
        ]);
        $sv = $this->service($s);
        $rdv = $this->rdv($this->membre($user), $s, $sv);

        $this->getJson("/api/v1/rendez-vous/{$rdv->id}/paiement-en-ligne")
            ->assertOk()
            ->assertJsonPath('disponible', false);

        Http::assertNothingSent();
    }

    public function test_disponibilite_en_ligne_vrai_si_marchand_configure(): void
    {
        $this->fakeMarchandConfigure(true);

        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $s = $this->structureConfiguree();
        $sv = $this->service($s);
        $rdv = $this->rdv($this->membre($user), $s, $sv);

        $this->getJson("/api/v1/rendez-vous/{$rdv->id}/paiement-en-ligne")
            ->assertOk()
            ->assertJsonPath('disponible', true);
    }

    public function test_disponibilite_en_ligne_anti_idor(): void
    {
        $proprietaire = User::factory()->create();
        $s = $this->structureConfiguree();
        $sv = $this->service($s);
        $rdv = $this->rdv($this->membre($proprietaire), $s, $sv);

        Sanctum::actingAs(User::factory()->create());

        $this->getJson("/api/v1/rendez-vous/{$rdv->id}/paiement-en-ligne")->assertForbidden();
    }

    // ── ouvrirPaiementEnLigne (S5/S12) ──────────────────────────────────────────────────────

    public function test_ouvrir_paiement_en_ligne_credite_une_facture_a_regler_et_ouvre_un_checkout(): void
    {
        $this->fakeMarchandConfigure(true);

        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $s = $this->structureConfiguree();
        $sv = $this->service($s, 15000);
        $rdv = $this->rdv($this->membre($user), $s, $sv);

        $this->postJson("/api/v1/rendez-vous/{$rdv->id}/paiement-en-ligne")
            ->assertOk()
            ->assertJsonPath('checkout_url', 'https://sandbox.geniuspay.example/checkout/abc');

        $this->assertDatabaseHas('factures_patient', [
            'rendez_vous_id' => $rdv->id,
            'montant_brut' => 15000,
            'statut' => StatutFacturePatient::A_REGLER->value,
            'facture_geniuspay_id' => self::FACTURE_JAVA_ID,
        ]);
        // Aucun reçu, aucun paiement : S5, seule la notification confirmera (S6).
        $this->assertSame(0, RendezVous::find($rdv->id)->recu()->count());
        $this->assertSame(0, Paiement::where('rendez_vous_id', $rdv->id)->count());

        // Une VRAIE Facture Java a été créée AVANT le checkout (écart trouvé en lisant le code,
        // pas au G1) — sans elle, le webhook de succès échouerait dans sa propre transaction.
        Http::assertSent(fn ($r) => $r->url() === 'http://localhost:8080/api/v1/invoices'
            && $r['etablissementRef'] === 'CI-ETS500001'
            && $r['lignes'][0]['prixUnitaire'] === 15000);

        Http::assertSent(fn ($r) => $r->url() === 'http://localhost:8080/api/v1/interne/geniuspay/paiements'
            && $r['objet'] === 'RENDEZ_VOUS'
            && $r['etablissementRef'] === 'CI-ETS500001'
            && $r['montant'] === 15000
            && $r['factureId'] === self::FACTURE_JAVA_ID);
    }

    /** Vecteur #11 (§9.5) — retaper « Payer en ligne » réutilise la MÊME facture et le MÊME
     *  `factureId` côté Java, jamais une seconde ligne. */
    public function test_retaper_payer_en_ligne_reutilise_la_meme_facture_et_le_meme_factureid(): void
    {
        $this->fakeMarchandConfigure(true);

        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $s = $this->structureConfiguree();
        $sv = $this->service($s, 15000);
        $rdv = $this->rdv($this->membre($user), $s, $sv);

        $this->postJson("/api/v1/rendez-vous/{$rdv->id}/paiement-en-ligne")->assertOk();
        $this->postJson("/api/v1/rendez-vous/{$rdv->id}/paiement-en-ligne")->assertOk();

        $this->assertSame(
            1,
            FacturePatient::where('rendez_vous_id', $rdv->id)->count(),
            'Une seule FacturePatient, jamais une seconde créée au second appel.'
        );

        // Une seule Facture Java créée — retaper « Payer en ligne » ne doit JAMAIS en fabriquer
        // une seconde (le G0 avait trouvé que l'oublier casserait le règlement au webhook).
        $appelsInvoices = collect(Http::recorded())
            ->map(fn ($paire) => $paire[0])
            ->filter(fn ($r) => $r->url() === 'http://localhost:8080/api/v1/invoices');
        $this->assertCount(1, $appelsInvoices, 'Une seule Facture Java doit être créée, jamais une seconde.');

        $factureIdsEnvoyes = collect(Http::recorded())
            ->map(fn ($paire) => $paire[0])
            ->filter(fn ($r) => $r->url() === 'http://localhost:8080/api/v1/interne/geniuspay/paiements')
            ->map(fn ($r) => $r['factureId'])
            ->unique();

        $this->assertCount(1, $factureIdsEnvoyes, 'Le factureId envoyé à Java doit être identique aux deux appels.');
        $this->assertSame(self::FACTURE_JAVA_ID, $factureIdsEnvoyes->first());
    }

    /**
     * La garde « déjà réglé » est ISOLÉE : le marchand est faussement configuré (au lieu de
     * laisser un appel réseau réel échouer et retomber, par accident, sur le MÊME 422 via la garde
     * `estConfigure()` — famille « le vecteur prouve autre chose », déjà rencontrée par ce projet).
     * Sans cette précaution, retirer la garde visée laisserait le test vert pour une autre raison.
     */
    public function test_ouvrir_paiement_en_ligne_refuse_si_deja_regle(): void
    {
        $this->fakeMarchandConfigure(true);

        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $s = $this->structureConfiguree();
        $sv = $this->service($s);
        $rdv = $this->rdv($this->membre($user), $s, $sv);
        app(RecuRdvService::class)->payer($rdv, 'especes');

        $this->postJson("/api/v1/rendez-vous/{$rdv->id}/paiement-en-ligne")
            ->assertStatus(422)
            ->assertJsonFragment(['paiement' => ['Ce rendez-vous a déjà un reçu.']]);
    }

    public function test_ouvrir_paiement_en_ligne_refuse_sans_tarif(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $s = $this->structureConfiguree();
        $s->update(['tarif_min_cfa' => null]);
        $sv = ServiceEtablissement::create([
            'structure_id' => $s->id, 'nom_service' => 'Cardiologie', 'specialite' => 'cardiologie', 'actif' => true,
        ]);
        $rdv = $this->rdv($this->membre($user), $s, $sv);

        $this->postJson("/api/v1/rendez-vous/{$rdv->id}/paiement-en-ligne")->assertStatus(422);
    }

    public function test_ouvrir_paiement_en_ligne_refuse_si_etablissement_sans_identifiant_national(): void
    {
        Http::fake();

        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $s = StructureSanitaire::create([
            'nom' => 'Sans identifiant', 'type' => 'chu', 'adresse' => 'Abidjan', 'commune' => 'Cocody',
            'latitude' => 5.35, 'longitude' => -3.99, 'tarif_min_cfa' => 8000, 'actif' => true,
        ]);
        $sv = $this->service($s);
        $rdv = $this->rdv($this->membre($user), $s, $sv);

        $this->postJson("/api/v1/rendez-vous/{$rdv->id}/paiement-en-ligne")
            ->assertStatus(422)
            ->assertJsonFragment(['paiement' => ['Cet établissement n\'a pas d\'identifiant national : le paiement en ligne n\'est pas disponible.']]);

        Http::assertNothingSent();
    }

    public function test_ouvrir_paiement_en_ligne_refuse_si_marchand_non_configure(): void
    {
        $this->fakeMarchandConfigure(false);

        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $s = $this->structureConfiguree();
        $sv = $this->service($s);
        $rdv = $this->rdv($this->membre($user), $s, $sv);

        $this->postJson("/api/v1/rendez-vous/{$rdv->id}/paiement-en-ligne")->assertStatus(422);

        Http::assertNotSent(fn ($r) => $r->url() === 'http://localhost:8080/api/v1/invoices');
        Http::assertNotSent(fn ($r) => $r->url() === 'http://localhost:8080/api/v1/interne/geniuspay/paiements');
    }

    public function test_ouvrir_paiement_en_ligne_relaie_le_refus_du_microservice_tel_quel(): void
    {
        Http::fake([
            self::CHEMIN_MARCHAND => Http::response(['configure' => true], 200),
            self::CHEMIN_INVOICES => Http::response(['id' => self::FACTURE_JAVA_ID], 201),
            self::CHEMIN_CHECKOUT => Http::response(
                ['detail' => "Le paiement en ligne n'est pas disponible sous 5000 FCFA."],
                422,
            ),
        ]);

        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $s = $this->structureConfiguree();
        $sv = $this->service($s, 15000);
        $rdv = $this->rdv($this->membre($user), $s, $sv);

        $this->postJson("/api/v1/rendez-vous/{$rdv->id}/paiement-en-ligne")->assertStatus(422);
    }

    public function test_ouvrir_paiement_en_ligne_anti_idor(): void
    {
        $this->fakeMarchandConfigure(true);
        $proprietaire = User::factory()->create();
        $s = $this->structureConfiguree();
        $sv = $this->service($s);
        $rdv = $this->rdv($this->membre($proprietaire), $s, $sv);

        Sanctum::actingAs(User::factory()->create());

        $this->postJson("/api/v1/rendez-vous/{$rdv->id}/paiement-en-ligne")->assertForbidden();
    }

    // ── confirmerReglementEnLigne (S6) — au niveau service, le dispatch HTTP réel étant couvert
    //    par CanalInternePaiementTest ─────────────────────────────────────────────────────────

    private function factureARegler(RendezVous $rdv, StructureSanitaire $s, int $montant = 15000): FacturePatient
    {
        return FacturePatient::create([
            'structure_sanitaire_id' => $s->id,
            'patient_id' => $rdv->membre->user_id,
            'rendez_vous_id' => $rdv->id,
            'reference' => 'FPA-'.uniqid(),
            'moment_paiement' => MomentPaiement::AVANT_ACTE,
            'montant_brut' => $montant,
            'montant_pris_en_charge_cmu' => 0,
            'montant_reste_a_charge' => $montant,
            'statut' => StatutFacturePatient::A_REGLER,
            'paiement_en_ligne_autorise' => true,
            'date_emission' => now(),
        ]);
    }

    public function test_confirmer_reglement_cree_paiement_facture_payee_et_recu(): void
    {
        $s = $this->structureConfiguree();
        $sv = $this->service($s);
        $rdv = $this->rdv($this->membre(User::factory()->create()), $s, $sv);
        $facture = $this->factureARegler($rdv, $s, 15000);

        app(RecuRdvService::class)->confirmerReglementEnLigne($facture->id, 'MS-PAY-001', now()->toIso8601String());

        $this->assertSame(StatutFacturePatient::PAYEE, $facture->fresh()->statut);
        $this->assertDatabaseHas('paiements', [
            'rendez_vous_id' => $rdv->id, 'montant' => 15000, 'mode' => 'geniuspay',
            'statut' => 'paye', 'transaction_ref' => 'MS-PAY-001',
        ]);
        $this->assertDatabaseHas('recus_rdv', ['rendez_vous_id' => $rdv->id, 'statut' => 'paye']);
        $this->assertTrue(app(RecuRdvService::class)->estRegle($rdv->fresh()));
    }

    /** Vecteur #13 — webhook rejoué : ni un second Paiement, ni un second RecuRdv. */
    public function test_confirmer_reglement_rejoue_ne_double_pas(): void
    {
        $s = $this->structureConfiguree();
        $sv = $this->service($s);
        $rdv = $this->rdv($this->membre(User::factory()->create()), $s, $sv);
        $facture = $this->factureARegler($rdv, $s, 15000);

        $recus = app(RecuRdvService::class);
        $recus->confirmerReglementEnLigne($facture->id, 'MS-PAY-002', now()->toIso8601String());
        $recus->confirmerReglementEnLigne($facture->id, 'MS-PAY-002', now()->toIso8601String());

        $this->assertSame(1, Paiement::where('rendez_vous_id', $rdv->id)->count());
        $this->assertSame(1, $rdv->recu()->count());
    }

    /**
     * ISOLE la garde de statut (`$facture->statut === PAYEE`), indépendamment de la garde de reçu
     * (`$rdv->recu !== null`) : les deux protègent la MÊME idempotence, mais par des chemins
     * différents — retirer l'UNE seule des deux dans `test_confirmer_reglement_rejoue_ne_double_pas`
     * ne suffit pas à faire échouer ce test, l'autre garde masque toujours le défaut (famille « le
     * vecteur prouve autre chose », trouvée ICI par la mutation elle-même). On force la facture à
     * `PAYEE` SANS jamais poser de reçu, pour que seule la garde de statut puisse expliquer le refus.
     */
    public function test_confirmer_reglement_refuse_car_facture_deja_payee_meme_sans_recu(): void
    {
        $s = $this->structureConfiguree();
        $sv = $this->service($s);
        $rdv = $this->rdv($this->membre(User::factory()->create()), $s, $sv);
        $facture = $this->factureARegler($rdv, $s, 15000);
        $facture->update(['statut' => StatutFacturePatient::PAYEE, 'date_reglement' => now()]);

        app(RecuRdvService::class)->confirmerReglementEnLigne($facture->id, 'MS-PAY-004', now()->toIso8601String());

        $this->assertSame(0, Paiement::where('rendez_vous_id', $rdv->id)->count());
        $this->assertSame(0, $rdv->recu()->count());
    }

    public function test_confirmer_reglement_sur_facture_introuvable_ne_leve_rien(): void
    {
        $this->expectNotToPerformAssertions();

        app(RecuRdvService::class)->confirmerReglementEnLigne(999999, 'MS-PAY-003', now()->toIso8601String());
    }

    // ── facturePatientIdDepuisCorrelation ───────────────────────────────────────────────────

    public function test_facture_patient_id_depuis_correlation_parse_le_bon_prefixe(): void
    {
        $recus = app(RecuRdvService::class);

        $this->assertSame(42, $recus->facturePatientIdDepuisCorrelation('facture-patient:42'));
        $this->assertNull($recus->facturePatientIdDepuisCorrelation(null));
        $this->assertNull($recus->facturePatientIdDepuisCorrelation('CORR-'.Str::uuid()));
        $this->assertNull($recus->facturePatientIdDepuisCorrelation('facture-patient:pas-un-nombre'));

        // Vecteur qui exerce RÉELLEMENT la vérification de préfixe : une chaîne qui ne commence
        // PAS par le bon préfixe, mais dont les 16 premiers caractères une fois retirés laissent
        // une suite de chiffres — `ctype_digit()` seul ne l'aurait pas rejetée. Sans le contrôle
        // `str_starts_with()`, ce vecteur serait accepté à tort (motif « le vecteur prouve autre
        // chose », déjà rencontré ailleurs dans ce projet).
        $this->assertNull($recus->facturePatientIdDepuisCorrelation(str_repeat('x', 16).'42'));
    }
}
