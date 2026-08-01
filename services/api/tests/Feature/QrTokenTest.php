<?php

namespace Tests\Feature;

use App\Models\AccesDossier;
use App\Models\MembreFamille;
use App\Models\TokenQr;
use App\Models\User;
use App\Services\QrTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * Étape 2A.3 — QR Code dynamique (Sécurité §5) et journal d'audit immuable (§10).
 * On prouve les garanties cœur : opacité du matricule, usage unique, expiration,
 * traçabilité immuable et isolation entre comptes.
 */
class QrTokenTest extends TestCase
{
    use RefreshDatabase;

    private function service(): QrTokenService
    {
        return app(QrTokenService::class);
    }

    public function test_le_patient_genere_un_qr_pour_son_membre_sans_exposer_le_matricule(): void
    {
        $user = User::factory()->create();
        $membre = MembreFamille::factory()->for($user)->create();
        Sanctum::actingAs($user);

        $response = $this->postJson("/api/v1/membres/{$membre->id}/qr");

        $response->assertCreated()
            ->assertJsonStructure(['qr', 'expires_in'])
            ->assertJsonPath('expires_in', 600);

        // Le QR ne contient ni le matricule, ni l'id du membre en clair.
        $qr = $response->json('qr');
        $this->assertStringNotContainsString($membre->matricule_ivs, $qr);

        // En base, on ne stocke que le HASH du token (§5.2) — jamais le token public.
        $token = TokenQr::first();
        $this->assertNull($token->used_at);
        $this->assertSame(hash('sha256', $qr), $token->token_hash);
        $this->assertNotSame($qr, $token->token_hash);
    }

    public function test_un_utilisateur_ne_peut_pas_generer_de_qr_pour_le_membre_d_un_autre(): void
    {
        $membre = MembreFamille::factory()->for(User::factory())->create();
        Sanctum::actingAs(User::factory()->create());

        $this->postJson("/api/v1/membres/{$membre->id}/qr")->assertForbidden();
        $this->assertDatabaseCount('tokens_qr', 0);
    }

    public function test_un_token_est_a_usage_unique_et_journalise_l_acces(): void
    {
        $membre = MembreFamille::factory()->for(User::factory())->create();
        $qr = $this->service()->generer($membre)['qr'];

        // 1er scan : ouvre le dossier + écrit le journal d'audit.
        $ouvert = $this->service()->validerEtConsommer($qr, ['ip' => '10.0.0.1']);
        $this->assertSame($membre->id, $ouvert->id);
        $this->assertNotNull(TokenQr::first()->used_at);

        $this->assertDatabaseHas('acces_dossier', [
            'membre_id'  => $membre->id,
            'type_acces' => 'qr_scan',
            'ip_address' => '10.0.0.1',
        ]);

        // 2e scan du même token : rejeu refusé (409).
        try {
            $this->service()->validerEtConsommer($qr);
            $this->fail('Le rejeu du token aurait dû être refusé.');
        } catch (HttpException $e) {
            $this->assertSame(409, $e->getStatusCode());
        }
    }

    public function test_un_token_expire_est_refuse(): void
    {
        $membre = MembreFamille::factory()->for(User::factory())->create();
        $qr = $this->service()->generer($membre)['qr'];
        TokenQr::first()->forceFill(['expires_at' => now()->subMinute()])->save();

        try {
            $this->service()->validerEtConsommer($qr);
            $this->fail('Le token expiré aurait dû être refusé.');
        } catch (HttpException $e) {
            $this->assertSame(410, $e->getStatusCode());
        }
    }

    public function test_un_token_inconnu_est_refuse(): void
    {
        try {
            $this->service()->validerEtConsommer('jeton-bidon.signature');
            $this->fail('Un token inconnu aurait dû être refusé.');
        } catch (HttpException $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
    }

    public function test_le_journal_d_audit_est_immuable(): void
    {
        $membre = MembreFamille::factory()->for(User::factory())->create();
        $acces = AccesDossier::create([
            'membre_id'  => $membre->id,
            'type_acces' => 'qr_scan',
        ]);

        try {
            $acces->update(['type_acces' => 'admin']);
            $this->fail('La modification du journal aurait dû être refusée.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }

        try {
            $acces->delete();
            $this->fail('La suppression du journal aurait dû être refusée.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
    }

    public function test_le_patient_consulte_l_historique_d_acces_de_son_membre(): void
    {
        $user = User::factory()->create();
        $membre = MembreFamille::factory()->for($user)->create();
        $qr = $this->service()->generer($membre)['qr'];
        $this->service()->validerEtConsommer($qr);
        Sanctum::actingAs($user);

        $this->getJson("/api/v1/membres/{$membre->id}/acces")
            ->assertOk()
            ->assertJsonCount(1, 'acces')
            ->assertJsonPath('acces.0.type_acces', 'qr_scan');
    }

    public function test_un_utilisateur_ne_voit_pas_l_audit_du_membre_d_un_autre(): void
    {
        $membre = MembreFamille::factory()->for(User::factory())->create();
        Sanctum::actingAs(User::factory()->create());

        $this->getJson("/api/v1/membres/{$membre->id}/acces")->assertForbidden();
    }
}
