<?php

namespace Tests\Feature;

use App\Models\Medecin;
use App\Models\MembreFamille;
use App\Models\RendezVous;
use App\Models\ServiceEtablissement;
use App\Models\StructureSanitaire;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Module 3 / N1-N2-N3 — Paiement (simulé) + reçu de RDV + QR de check-in.
 * Vérifie la dérivation du montant (médecin/structure), l'anti-IDOR, l'unicité du reçu, et surtout
 * que le code de check-in est signé, cloisonné et NE PORTE AUCUNE donnée médicale.
 */
class RecuRdvPaiementTest extends TestCase
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

    public function test_paiement_simule_emet_recu_avec_montant_du_medecin(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $s = $this->structure();
        $sv = $this->service($s);
        $med = $this->medecin($s, $sv, 15000);
        $rdv = $this->rdv($this->membre($user), $s, $sv, $med);

        $this->postJson("/api/v1/rendez-vous/{$rdv->id}/paiement", ['mode' => 'mobile_money'])
            ->assertCreated()
            ->assertJsonPath('recu.montant', 15000)
            ->assertJsonPath('recu.mode', 'mobile_money')
            ->assertJsonPath('recu.statut', 'paye');

        $this->assertDatabaseHas('paiements', ['rendez_vous_id' => $rdv->id, 'montant' => 15000, 'statut' => 'paye']);
        $this->assertDatabaseHas('recus_rdv', ['rendez_vous_id' => $rdv->id, 'statut' => 'paye']);
    }

    public function test_montant_retombe_sur_le_tarif_structure_sans_medecin(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $s = $this->structure(8000);
        $sv = $this->service($s);
        $rdv = $this->rdv($this->membre($user), $s, $sv, null);

        $this->postJson("/api/v1/rendez-vous/{$rdv->id}/paiement", ['mode' => 'especes'])
            ->assertCreated()
            ->assertJsonPath('recu.montant', 8000);
    }

    public function test_paiement_sans_tarif_est_rejete(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $s = $this->structure(0);
        $s->update(['tarif_min_cfa' => null]); // aucun tarif exploitable, sans médecin
        $sv = $this->service($s);
        $rdv = $this->rdv($this->membre($user), $s, $sv, null);

        $this->postJson("/api/v1/rendez-vous/{$rdv->id}/paiement", ['mode' => 'carte'])
            ->assertStatus(422);
    }

    public function test_paiement_anti_idor_refuse_le_rdv_d_un_autre_compte(): void
    {
        $proprietaire = User::factory()->create();
        $s = $this->structure();
        $sv = $this->service($s);
        $rdv = $this->rdv($this->membre($proprietaire), $s, $sv);

        Sanctum::actingAs(User::factory()->create()); // un autre compte

        $this->postJson("/api/v1/rendez-vous/{$rdv->id}/paiement", ['mode' => 'especes'])
            ->assertForbidden();
    }

    public function test_double_paiement_rejete(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $s = $this->structure();
        $sv = $this->service($s);
        $rdv = $this->rdv($this->membre($user), $s, $sv);

        $this->postJson("/api/v1/rendez-vous/{$rdv->id}/paiement", ['mode' => 'especes'])->assertCreated();
        $this->postJson("/api/v1/rendez-vous/{$rdv->id}/paiement", ['mode' => 'especes'])->assertStatus(422);
    }

    public function test_rdv_annule_ne_peut_pas_etre_regle(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $s = $this->structure();
        $sv = $this->service($s);
        $rdv = $this->rdv($this->membre($user), $s, $sv);
        $rdv->update(['statut' => 'annule']);

        $this->postJson("/api/v1/rendez-vous/{$rdv->id}/paiement", ['mode' => 'especes'])->assertStatus(422);
    }

    public function test_code_checkin_est_signe_et_sans_donnee_medicale(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $s = $this->structure();
        $sv = $this->service($s);
        $med = $this->medecin($s, $sv);
        $rdv = $this->rdv($this->membre($user), $s, $sv, $med);

        $code = $this->postJson("/api/v1/rendez-vous/{$rdv->id}/paiement", ['mode' => 'mobile_money'])
            ->assertCreated()
            ->json('recu.code');

        // Format base64url(json).signature
        [$corps, $signature] = explode('.', $code);
        $secret = hash_hmac('sha256', 'recu-rdv', (string) config('app.key'));
        $this->assertSame(hash_hmac('sha256', $corps, $secret), $signature, 'Signature HMAC invalide.');

        $payload = json_decode(base64_decode(strtr($corps, '-_', '+/')), true);
        $this->assertSame('rdv', $payload['typ']);
        $this->assertSame($rdv->id, $payload['rdv']);
        // Aucune donnée médicale/nominative dans le token.
        $this->assertEqualsCanonicalizing(['v', 'typ', 'ref', 'rdv', 'exp'], array_keys($payload));
        $this->assertStringNotContainsStringIgnoringCase('Yao', $code);   // nom membre
        $this->assertStringNotContainsStringIgnoringCase('Koffi', $code); // nom médecin
    }

    public function test_show_recu_404_sans_paiement(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $s = $this->structure();
        $sv = $this->service($s);
        $rdv = $this->rdv($this->membre($user), $s, $sv);

        $this->getJson("/api/v1/rendez-vous/{$rdv->id}/recu")->assertNotFound();
    }
}
