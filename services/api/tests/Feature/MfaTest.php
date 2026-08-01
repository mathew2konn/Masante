<?php

namespace Tests\Feature;

use App\Models\MfaFacteur;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

/**
 * P1 (Identité) — MFA « prêt à activer » (CDC_10 §3.5).
 *
 * Deux invariants clés :
 *  1. Gate OFF (défaut MVP) ⇒ la connexion est INCHANGÉE (aucun patient impacté).
 *  2. La décision « MFA requis » est backend : le front ne fait que présenter le défi.
 */
class MfaTest extends TestCase
{
    use RefreshDatabase;

    private function google2fa(): Google2FA
    {
        return app(Google2FA::class);
    }

    private function utilisateurVerifie(string $telephone): User
    {
        return User::factory()->create([
            'telephone' => $telephone,
            'telephone_verified_at' => now(),
        ]);
    }

    // --- Enrôlement / confirmation -------------------------------------------------------------

    public function test_enroll_puis_confirm_active_le_facteur(): void
    {
        $user = $this->utilisateurVerifie('+2250700000020');
        Sanctum::actingAs($user);

        $reponse = $this->postJson('/api/v1/auth/mfa/enroll')
            ->assertCreated()
            ->assertJsonStructure(['type', 'secret', 'otpauth_uri']);

        $secret = $reponse->json('secret');
        $this->assertDatabaseHas('mfa_facteurs', ['user_id' => $user->id, 'confirmed_at' => null]);

        $this->getJson('/api/v1/auth/mfa/status')->assertOk()->assertJsonPath('facteur_confirme', false);

        $code = $this->google2fa()->getCurrentOtp($secret);
        $this->postJson('/api/v1/auth/mfa/confirm', ['code' => $code])->assertOk();

        $this->getJson('/api/v1/auth/mfa/status')->assertOk()->assertJsonPath('facteur_confirme', true);
    }

    public function test_confirm_refuse_un_code_invalide(): void
    {
        $user = $this->utilisateurVerifie('+2250700000021');
        Sanctum::actingAs($user);
        $this->postJson('/api/v1/auth/mfa/enroll')->assertCreated();

        $this->postJson('/api/v1/auth/mfa/confirm', ['code' => '000000'])->assertStatus(422);
        $this->assertDatabaseHas('mfa_facteurs', ['user_id' => $user->id, 'confirmed_at' => null]);
    }

    // --- Le secret ne fuite jamais après l'enrôlement ------------------------------------------

    public function test_le_secret_n_est_jamais_re_expose(): void
    {
        $user = $this->utilisateurVerifie('+2250700000022');
        Sanctum::actingAs($user);
        $this->postJson('/api/v1/auth/mfa/enroll')->assertCreated();

        $this->getJson('/api/v1/auth/mfa/status')
            ->assertOk()
            ->assertJsonMissingPath('secret');
    }

    // --- Gate OFF : connexion inchangée --------------------------------------------------------

    public function test_gate_off_la_connexion_reste_inchangee_meme_pour_un_pro(): void
    {
        config(['mfa.enforce' => false]);
        $this->seed(RoleSeeder::class);

        $user = $this->utilisateurVerifie('+2250700000023');
        $user->assignRole('medecin');
        $this->facteurConfirme($user);

        $this->postJson('/api/v1/auth/login', [
            'telephone' => '+2250700000023',
            'password' => 'password',
        ])->assertOk()->assertJsonPath('token_type', 'Bearer')->assertJsonMissingPath('mfa_required');
    }

    public function test_patient_non_soumis_meme_gate_on(): void
    {
        config(['mfa.enforce' => true]);
        $this->seed(RoleSeeder::class);

        $user = $this->utilisateurVerifie('+2250700000024');
        $user->assignRole('patient');

        $this->postJson('/api/v1/auth/login', [
            'telephone' => '+2250700000024',
            'password' => 'password',
        ])->assertOk()->assertJsonPath('token_type', 'Bearer');
    }

    // --- Gate ON : défi puis vérification ------------------------------------------------------

    public function test_gate_on_pro_confirme_recoit_un_defi_puis_le_token(): void
    {
        config(['mfa.enforce' => true]);
        $this->seed(RoleSeeder::class);

        $user = $this->utilisateurVerifie('+2250700000025');
        $user->assignRole('medecin');
        $secret = $this->facteurConfirme($user);

        $defi = $this->postJson('/api/v1/auth/login', [
            'telephone' => '+2250700000025',
            'password' => 'password',
        ])->assertOk()
            ->assertJsonPath('mfa_required', true)
            ->assertJsonMissingPath('token');

        $mfaToken = $defi->json('mfa_token');
        $code = $this->google2fa()->getCurrentOtp($secret);

        $this->postJson('/api/v1/auth/mfa/verify', ['mfa_token' => $mfaToken, 'code' => $code])
            ->assertOk()
            ->assertJsonPath('token_type', 'Bearer')
            ->assertJsonPath('user.roles.0', 'medecin');
    }

    public function test_verify_refuse_un_mauvais_code_et_conserve_le_defi(): void
    {
        config(['mfa.enforce' => true]);
        $this->seed(RoleSeeder::class);

        $user = $this->utilisateurVerifie('+2250700000026');
        $user->assignRole('medecin');
        $secret = $this->facteurConfirme($user);

        $mfaToken = $this->postJson('/api/v1/auth/login', [
            'telephone' => '+2250700000026',
            'password' => 'password',
        ])->json('mfa_token');

        $this->postJson('/api/v1/auth/mfa/verify', ['mfa_token' => $mfaToken, 'code' => '000000'])
            ->assertStatus(422);

        // Le défi survit : un code correct passe toujours.
        $code = $this->google2fa()->getCurrentOtp($secret);
        $this->postJson('/api/v1/auth/mfa/verify', ['mfa_token' => $mfaToken, 'code' => $code])
            ->assertOk()
            ->assertJsonPath('token_type', 'Bearer');
    }

    public function test_anti_rejeu_un_code_ne_sert_qu_une_fois(): void
    {
        config(['mfa.enforce' => true]);
        $this->seed(RoleSeeder::class);

        $user = $this->utilisateurVerifie('+2250700000027');
        $user->assignRole('medecin');
        $secret = $this->facteurConfirme($user);
        $code = $this->google2fa()->getCurrentOtp($secret);

        $mfaToken = $this->postJson('/api/v1/auth/login', [
            'telephone' => '+2250700000027',
            'password' => 'password',
        ])->json('mfa_token');

        $this->postJson('/api/v1/auth/mfa/verify', ['mfa_token' => $mfaToken, 'code' => $code])->assertOk();

        // Nouveau login, MÊME code (même fenêtre) → rejeté (anti-rejeu).
        $mfaToken2 = $this->postJson('/api/v1/auth/login', [
            'telephone' => '+2250700000027',
            'password' => 'password',
        ])->json('mfa_token');

        $this->postJson('/api/v1/auth/mfa/verify', ['mfa_token' => $mfaToken2, 'code' => $code])
            ->assertStatus(422);
    }

    public function test_verify_refuse_un_jeton_de_defi_inconnu(): void
    {
        $this->postJson('/api/v1/auth/mfa/verify', ['mfa_token' => 'inexistant', 'code' => '123456'])
            ->assertStatus(422);
    }

    /**
     * Crée un facteur TOTP CONFIRMÉ directement (last_timeslice nul → pas de collision de rejeu
     * avec la confirmation), et renvoie le secret en clair pour calculer les codes de test.
     */
    private function facteurConfirme(User $user): string
    {
        $secret = $this->google2fa()->generateSecretKey();
        MfaFacteur::create([
            'user_id' => $user->id,
            'type' => 'totp',
            'secret' => $secret,
            'confirmed_at' => now(),
        ]);

        return $secret;
    }
}
