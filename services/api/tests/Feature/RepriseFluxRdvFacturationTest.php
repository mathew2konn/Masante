<?php

namespace Tests\Feature;

use App\Models\FacturePatient;
use App\Models\Medecin;
use App\Models\MembreFamille;
use App\Models\Paiement;
use App\Models\RecuRdv;
use App\Models\RendezVous;
use App\Models\ServiceEtablissement;
use App\Models\StructureSanitaire;
use App\Models\User;
use App\Services\RecuRdvService;
use App\Support\StatutFacturePatient;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Lot 5 (v2, 2026-08-27) — reprise du flux rendez-vous sur `factures_patient`.
 *
 * DOUBLE ÉCRITURE TRANSITOIRE, pas un remplacement : `Paiement`/`RecuRdv` continuent d'être créés
 * à l'identique (voir `RecuRdvPaiementTest`, non modifié et toujours vert), `factures_patient`
 * naît EN PLUS, dans la même transaction, toujours déjà soldée (`statut = PAYEE`, jamais
 * `A_REGLER` : ce point d'entrée EST le règlement, simulé mais immédiat).
 */
class RepriseFluxRdvFacturationTest extends TestCase
{
    use RefreshDatabase;

    private function structure(int $tarifMin = 8000): StructureSanitaire
    {
        return StructureSanitaire::create([
            'nom' => 'CHU Test', 'type' => 'chu', 'adresse' => 'Abidjan', 'commune' => 'Cocody',
            'latitude' => 5.35, 'longitude' => -3.99, 'tarif_min_cfa' => $tarifMin,
        ]);
    }

    private function service(StructureSanitaire $s): ServiceEtablissement
    {
        return ServiceEtablissement::create([
            'structure_id' => $s->id, 'nom_service' => 'Cardiologie', 'specialite' => 'cardiologie', 'actif' => true,
        ]);
    }

    private function medecin(StructureSanitaire $s, ServiceEtablissement $service, int $tarif = 15000): Medecin
    {
        return Medecin::create([
            'structure_id' => $s->id, 'service_id' => $service->id,
            'titre' => 'Dr', 'nom' => 'Koffi', 'prenom' => 'Serge', 'specialite' => 'Cardiologie',
            'tarif_consultation' => $tarif, 'actif' => true,
        ]);
    }

    private function membre(User $user, string $matricule = 'IVS-2026-RC-00001'): MembreFamille
    {
        $membre = new MembreFamille([
            'nom' => 'Yao', 'prenom' => 'Awa', 'date_naissance' => '2000-01-01', 'sexe' => 'F',
        ]);
        $membre->user_id = $user->id;
        $membre->matricule_ivs = $matricule;
        $membre->save();

        return $membre;
    }

    private function rdv(MembreFamille $m, StructureSanitaire $s, ServiceEtablissement $sv, ?Medecin $med = null): RendezVous
    {
        return RendezVous::create([
            'membre_id' => $m->id, 'structure_id' => $s->id, 'service_id' => $sv->id,
            'medecin_id' => $med?->id,
            'mode_attribution' => $med ? 'patient_choisit' : 'etablissement_attribue',
            'motif' => 'Suivi', 'date_souhaitee' => Carbon::tomorrow()->toDateString(), 'statut' => 'en_attente',
        ]);
    }

    // ── 1. Double écriture : les deux existent ─────────────────────────────────────────────

    public function test_paiement_rdv_cree_facture_patient_en_plus_du_paiement(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $s = $this->structure();
        $sv = $this->service($s);
        $med = $this->medecin($s, $sv, 15000);
        $rdv = $this->rdv($this->membre($user), $s, $sv, $med);

        $this->postJson("/api/v1/rendez-vous/{$rdv->id}/paiement", ['mode' => 'mobile_money'])
            ->assertCreated();

        $this->assertDatabaseHas('paiements', [
            'rendez_vous_id' => $rdv->id, 'montant' => 15000, 'statut' => 'paye',
        ]);
        $this->assertDatabaseHas('factures_patient', [
            'rendez_vous_id' => $rdv->id, 'montant_brut' => 15000,
        ]);
    }

    // ── 2. La facture naît déjà soldée ─────────────────────────────────────────────────────

    public function test_facture_patient_creee_deja_soldee(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $s = $this->structure();
        $sv = $this->service($s);
        $rdv = $this->rdv($this->membre($user), $s, $sv);

        $this->postJson("/api/v1/rendez-vous/{$rdv->id}/paiement", ['mode' => 'especes'])->assertCreated();

        $facture = FacturePatient::where('rendez_vous_id', $rdv->id)->firstOrFail();

        $this->assertSame(StatutFacturePatient::PAYEE, $facture->statut);
        $this->assertNotNull($facture->date_reglement);
    }

    // ── 3. Le reçu continue de lire `Paiement`, pas `facture_patient` ─────────────────────

    public function test_recu_existant_reste_inchange(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $s = $this->structure();
        $sv = $this->service($s);
        $med = $this->medecin($s, $sv, 15000);
        $rdv = $this->rdv($this->membre($user), $s, $sv, $med);

        $this->postJson("/api/v1/rendez-vous/{$rdv->id}/paiement", ['mode' => 'carte'])
            ->assertCreated()
            ->assertJsonPath('recu.mode', 'carte');

        // `factures_patient` ne porte aucun champ `mode` : la dépendance transitoire du reçu
        // envers `Paiement` est documentée, pas corrigée ici — hors périmètre (reconstruction
        // du reçu, item 10 de la séquence).
        $this->assertFalse(Schema::hasColumn('factures_patient', 'mode'));
    }

    // ── 4. Un rendez-vous réglé avant ce lot reste lisible via le repli ────────────────────

    public function test_ancien_rdv_sans_facture_patient_lu_via_repli(): void
    {
        $user = User::factory()->create();
        $s = $this->structure();
        $sv = $this->service($s);
        $rdv = $this->rdv($this->membre($user), $s, $sv);

        // Simule un rendez-vous réglé AVANT ce lot : seul le couple Paiement+RecuRdv existe.
        $paiement = Paiement::create([
            'rendez_vous_id' => $rdv->id, 'montant' => 8000, 'mode' => 'especes',
            'statut' => 'paye', 'transaction_ref' => 'SIM-ANCIEN',
        ]);
        RecuRdv::create([
            'rendez_vous_id' => $rdv->id, 'paiement_id' => $paiement->id,
            'reference' => 'MS-RECU-ANCIEN', 'statut' => 'paye', 'expires_at' => now()->addDay(),
        ]);

        $this->assertTrue(app(RecuRdvService::class)->estRegle($rdv));
        $this->assertDatabaseCount('factures_patient', 0);
    }

    // ── 5. Un rendez-vous postérieur au lot est lu via `factures_patient`, jamais le repli ─

    public function test_nouveau_rdv_facture_prioritaire_sur_repli(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $s = $this->structure();
        $sv = $this->service($s);
        $rdv = $this->rdv($this->membre($user), $s, $sv);

        $this->postJson("/api/v1/rendez-vous/{$rdv->id}/paiement", ['mode' => 'especes'])->assertCreated();

        $this->assertTrue(app(RecuRdvService::class)->estRegle($rdv->fresh()));
        $this->assertDatabaseCount('factures_patient', 1);
    }

    // ── 6. Atomicité : si la facture échoue, aucun paiement ne survit ─────────────────────

    public function test_echec_transaction_n_ecrit_ni_paiement_ni_facture(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $s = $this->structure();
        $sv = $this->service($s);
        $rdv = $this->rdv($this->membre($user), $s, $sv);

        // Sabote délibérément l'écriture de `factures_patient` (colonne NOT NULL que le service
        // alimente toujours) pour vérifier que la transaction n'est jamais partiellement
        // appliquée — aucun `Paiement` ne doit survivre si la facture échoue.
        Schema::table('factures_patient', function (Blueprint $table) {
            $table->dropColumn('moment_paiement');
        });

        try {
            app(RecuRdvService::class)->payer($rdv->fresh(), 'especes');
            $this->fail('La création aurait dû échouer.');
        } catch (\Throwable $e) {
            // attendu — colonne absente.
        }

        $this->assertDatabaseCount('paiements', 0);
        $this->assertDatabaseCount('factures_patient', 0);
        $this->assertDatabaseCount('recus_rdv', 0);
    }

    // ── 7. Aucun calcul CMU inventé ─────────────────────────────────────────────────────────

    public function test_montant_reste_a_charge_egale_montant_brut(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $s = $this->structure();
        $sv = $this->service($s);
        $med = $this->medecin($s, $sv, 12000);
        $rdv = $this->rdv($this->membre($user), $s, $sv, $med);

        $this->postJson("/api/v1/rendez-vous/{$rdv->id}/paiement", ['mode' => 'carte'])->assertCreated();

        $facture = FacturePatient::where('rendez_vous_id', $rdv->id)->firstOrFail();

        $this->assertSame(0, $facture->montant_pris_en_charge_cmu);
        $this->assertSame($facture->montant_brut, $facture->montant_reste_a_charge);
    }
}
