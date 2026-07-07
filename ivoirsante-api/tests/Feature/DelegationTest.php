<?php

namespace Tests\Feature;

use App\Models\Delegation;
use App\Models\MembreFamille;
use App\Models\TokenQr;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Phase B / B3 — Délégation d'accès (voie 3). Couvre l'invitation (règles + anti-IDOR),
 * l'acceptation, la révocation, et l'ouverture ciblée de la génération de QR au délégué actif.
 */
class DelegationTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: User, 1: MembreFamille, 2: User} [titulaire, membre, delegue] */
    private function trio(): array
    {
        $titulaire = User::factory()->create();
        $membre = MembreFamille::factory()->for($titulaire)->create();
        $delegue = User::factory()->create(); // téléphone vérifié par défaut (factory).
        return [$titulaire, $membre, $delegue];
    }

    private function inviter(User $titulaire, MembreFamille $membre, string $telephone): \Illuminate\Testing\TestResponse
    {
        Sanctum::actingAs($titulaire);
        return $this->postJson("/api/v1/membres/{$membre->id}/delegations", ['telephone' => $telephone]);
    }

    public function test_titulaire_invite_un_delegue(): void
    {
        [$titulaire, $membre, $delegue] = $this->trio();

        $this->inviter($titulaire, $membre, $delegue->telephone)
            ->assertCreated()
            ->assertJsonPath('delegation.delegue.id', $delegue->id);

        $this->assertDatabaseHas('delegations', [
            'titulaire_user_id' => $titulaire->id,
            'delegue_user_id'   => $delegue->id,
            'membre_id'         => $membre->id,
            'acceptee_at'       => null,
            'revoquee_at'       => null,
        ]);
    }

    public function test_invitation_numero_inconnu_est_refusee(): void
    {
        [$titulaire, $membre] = $this->trio();
        $this->inviter($titulaire, $membre, '+2250700000000')->assertStatus(422);
    }

    public function test_invitation_delegue_non_verifie_est_refusee(): void
    {
        [$titulaire, $membre] = $this->trio();
        $delegue = User::factory()->nonVerifie()->create();
        $this->inviter($titulaire, $membre, $delegue->telephone)->assertStatus(422);
    }

    public function test_on_ne_peut_pas_se_deleguer_soi_meme(): void
    {
        [$titulaire, $membre] = $this->trio();
        $this->inviter($titulaire, $membre, $titulaire->telephone)->assertStatus(422);
    }

    public function test_invitation_sur_le_membre_d_autrui_est_interdite(): void
    {
        [$titulaire, , $delegue] = $this->trio();
        $autreMembre = MembreFamille::factory()->for(User::factory()->create())->create();

        // Le titulaire tente d'inviter sur un membre qui n'est pas le sien → 403 (anti-IDOR).
        $this->inviter($titulaire, $autreMembre, $delegue->telephone)->assertStatus(403);
    }

    public function test_double_invitation_en_attente_est_refusee(): void
    {
        [$titulaire, $membre, $delegue] = $this->trio();
        $this->inviter($titulaire, $membre, $delegue->telephone)->assertCreated();
        $this->inviter($titulaire, $membre, $delegue->telephone)->assertStatus(422);
    }

    public function test_gate_titulaire_verifie_bloque_si_flag_actif(): void
    {
        config(['masante.delegation.exiger_titulaire_verifie' => true]);
        [$titulaire, $membre, $delegue] = $this->trio(); // titulaire non vérifié par défaut.

        $this->inviter($titulaire, $membre, $delegue->telephone)->assertStatus(403);
    }

    public function test_seul_le_delegue_peut_accepter(): void
    {
        [$titulaire, $membre, $delegue] = $this->trio();
        $delegation = Delegation::create([
            'titulaire_user_id' => $titulaire->id,
            'delegue_user_id'   => $delegue->id,
            'membre_id'         => $membre->id,
            'invitee_at'        => now(),
        ]);

        // Un autre utilisateur ne peut pas accepter.
        Sanctum::actingAs($titulaire);
        $this->postJson("/api/v1/delegations/{$delegation->id}/accepter")->assertStatus(403);

        // Le délégué accepte.
        Sanctum::actingAs($delegue);
        $this->postJson("/api/v1/delegations/{$delegation->id}/accepter")->assertOk();
        $this->assertNotNull($delegation->fresh()->acceptee_at);
    }

    public function test_le_titulaire_revoque(): void
    {
        [$titulaire, $membre, $delegue] = $this->trio();
        $delegation = Delegation::create([
            'titulaire_user_id' => $titulaire->id,
            'delegue_user_id'   => $delegue->id,
            'membre_id'         => $membre->id,
            'invitee_at'        => now(),
            'acceptee_at'       => now(),
        ]);

        Sanctum::actingAs($titulaire);
        $this->deleteJson("/api/v1/delegations/{$delegation->id}")->assertOk();
        $this->assertNotNull($delegation->fresh()->revoquee_at);
        $this->assertFalse(Delegation::actifPour($delegue->id, $membre->id));
    }

    public function test_delegue_actif_peut_generer_le_qr(): void
    {
        [$titulaire, $membre, $delegue] = $this->trio();
        Delegation::create([
            'titulaire_user_id' => $titulaire->id,
            'delegue_user_id'   => $delegue->id,
            'membre_id'         => $membre->id,
            'invitee_at'        => now(),
            'acceptee_at'       => now(),
        ]);

        Sanctum::actingAs($delegue);
        $this->postJson("/api/v1/membres/{$membre->id}/qr")->assertCreated();

        // La génération est tracée comme provenant du délégué.
        $this->assertDatabaseHas('tokens_qr', [
            'membre_id'             => $membre->id,
            'genere_par_delegue_id' => $delegue->id,
        ]);
    }

    public function test_delegue_non_accepte_ne_peut_pas_generer_le_qr(): void
    {
        [$titulaire, $membre, $delegue] = $this->trio();
        Delegation::create([
            'titulaire_user_id' => $titulaire->id,
            'delegue_user_id'   => $delegue->id,
            'membre_id'         => $membre->id,
            'invitee_at'        => now(), // pas encore acceptée
        ]);

        Sanctum::actingAs($delegue);
        $this->postJson("/api/v1/membres/{$membre->id}/qr")->assertStatus(403);
    }

    public function test_delegue_revoque_ne_peut_plus_generer_le_qr(): void
    {
        [$titulaire, $membre, $delegue] = $this->trio();
        Delegation::create([
            'titulaire_user_id' => $titulaire->id,
            'delegue_user_id'   => $delegue->id,
            'membre_id'         => $membre->id,
            'invitee_at'        => now(),
            'acceptee_at'       => now(),
            'revoquee_at'       => now(),
        ]);

        Sanctum::actingAs($delegue);
        $this->postJson("/api/v1/membres/{$membre->id}/qr")->assertStatus(403);
    }

    public function test_un_etranger_ne_peut_pas_generer_le_qr(): void
    {
        [, $membre] = $this->trio();
        Sanctum::actingAs(User::factory()->create());
        $this->postJson("/api/v1/membres/{$membre->id}/qr")->assertStatus(403);
    }

    public function test_index_separe_accordees_et_recues(): void
    {
        [$titulaire, $membre, $delegue] = $this->trio();
        Delegation::create([
            'titulaire_user_id' => $titulaire->id,
            'delegue_user_id'   => $delegue->id,
            'membre_id'         => $membre->id,
            'invitee_at'        => now(),
            'acceptee_at'       => now(),
        ]);

        Sanctum::actingAs($titulaire);
        $this->getJson('/api/v1/delegations')
            ->assertOk()
            ->assertJsonCount(1, 'accordees')
            ->assertJsonCount(0, 'recues');

        Sanctum::actingAs($delegue);
        $this->getJson('/api/v1/delegations')
            ->assertOk()
            ->assertJsonCount(0, 'accordees')
            ->assertJsonCount(1, 'recues');
    }
}
