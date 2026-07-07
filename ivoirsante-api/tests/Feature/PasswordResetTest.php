<?php

namespace Tests\Feature;

use App\Models\PasswordResetGrant;
use App\Models\User;
use App\Services\OtpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Phase B / B1 — Récupération et changement de mot de passe durcis.
 * Couvre : anti-énumération, OTP + preuve (date de naissance), jeton usage unique/expiration,
 * révocation des tokens, politique MDP, dégradation sans donnée de preuve, changement connecté.
 */
class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    private const MDP_FORT = 'Nouveau#Mdp2026';

    protected function setUp(): void
    {
        parent::setUp();
        // Le contrôle HIBP (NotCompromisedPassword) ne doit jamais toucher le réseau en test :
        // réponse vide = « non compromis ». Le fail-open couvre déjà les pannes, mais on reste déterministe.
        Http::fake(['api.pwnedpasswords.com/*' => Http::response('', 200)]);
    }

    /** Fabrique un compte vérifié avec une date de naissance connue. */
    private function compte(array $extra = []): User
    {
        return User::factory()->create(array_merge([
            'telephone'     => '+2250700000001',
            'date_naissance' => '1990-05-17',
            'password'      => Hash::make('AncienMdp#2025'),
        ], $extra));
    }

    /** Génère un OTP de récupération réel et renvoie le code en clair (comme le ferait le SMS). */
    private function otpRecuperation(User $user): string
    {
        return app(OtpService::class)->generer($user->telephone, 'recuperation', $user->id);
    }

    public function test_forgot_reponse_generique_identique_que_le_numero_existe_ou_non(): void
    {
        $this->compte();

        $existant = $this->postJson('/api/v1/auth/password/forgot', ['telephone' => '+2250700000001']);
        $inconnu  = $this->postJson('/api/v1/auth/password/forgot', ['telephone' => '+2250799999999']);

        $existant->assertOk();
        $inconnu->assertOk();
        $this->assertSame($existant->json('message'), $inconnu->json('message'));
        // En dev, le code n'est exposé que si un compte existe (sinon on divulguerait l'inexistence).
        $inconnu->assertJsonMissing(['dev_code_otp' => true]);
    }

    public function test_parcours_complet_reinitialise_le_mot_de_passe_et_revoque_les_tokens(): void
    {
        $user = $this->compte();
        $user->createToken('session-volee'); // session préexistante à révoquer.
        $code = $this->otpRecuperation($user);

        $verify = $this->postJson('/api/v1/auth/password/verify-otp', [
            'telephone'     => $user->telephone,
            'code'          => $code,
            'date_naissance' => '1990-05-17',
        ])->assertOk();

        $token = $verify->json('reset_token');
        $this->assertNotEmpty($token);

        $this->postJson('/api/v1/auth/password/reset', [
            'reset_token'           => $token,
            'password'              => self::MDP_FORT,
            'password_confirmation' => self::MDP_FORT,
        ])->assertOk();

        $user->refresh();
        $this->assertTrue(Hash::check(self::MDP_FORT, $user->password));
        $this->assertSame(0, $user->tokens()->count(), 'Tous les tokens doivent être révoqués.');
    }

    public function test_preuve_date_de_naissance_incorrecte_est_refusee(): void
    {
        $user = $this->compte();
        $code = $this->otpRecuperation($user);

        $this->postJson('/api/v1/auth/password/verify-otp', [
            'telephone'     => $user->telephone,
            'code'          => $code,
            'date_naissance' => '1991-01-01',
        ])->assertStatus(422);
    }

    public function test_preuve_manquante_alors_que_la_date_existe_est_refusee(): void
    {
        $user = $this->compte();
        $code = $this->otpRecuperation($user);

        $this->postJson('/api/v1/auth/password/verify-otp', [
            'telephone' => $user->telephone,
            'code'      => $code,
        ])->assertStatus(422);
    }

    public function test_compte_sans_date_de_naissance_passe_avec_le_seul_otp(): void
    {
        $user = $this->compte(['date_naissance' => null]);
        $code = $this->otpRecuperation($user);

        $this->postJson('/api/v1/auth/password/verify-otp', [
            'telephone' => $user->telephone,
            'code'      => $code,
        ])->assertOk()->assertJsonStructure(['reset_token']);
    }

    public function test_jeton_de_reinitialisation_est_a_usage_unique(): void
    {
        $user = $this->compte();
        $token = app(\App\Services\PasswordResetService::class)->delivrerJeton($user);

        $payload = [
            'reset_token'           => $token,
            'password'              => self::MDP_FORT,
            'password_confirmation' => self::MDP_FORT,
        ];

        $this->postJson('/api/v1/auth/password/reset', $payload)->assertOk();
        $this->postJson('/api/v1/auth/password/reset', $payload)->assertStatus(422);
    }

    public function test_jeton_expire_est_refuse(): void
    {
        $user = $this->compte();
        $token = 'jeton-clair-de-test-suffisamment-long-0123456789';
        PasswordResetGrant::create([
            'user_id'    => $user->id,
            'telephone'  => $user->telephone,
            'token_hash' => hash('sha256', $token),
            'expires_at' => now()->subMinute(),
        ]);

        $this->postJson('/api/v1/auth/password/reset', [
            'reset_token'           => $token,
            'password'              => self::MDP_FORT,
            'password_confirmation' => self::MDP_FORT,
        ])->assertStatus(422);
    }

    public function test_mot_de_passe_faible_est_rejete(): void
    {
        $user = $this->compte();
        $token = app(\App\Services\PasswordResetService::class)->delivrerJeton($user);

        $this->postJson('/api/v1/auth/password/reset', [
            'reset_token'           => $token,
            'password'              => 'password',
            'password_confirmation' => 'password',
        ])->assertStatus(422)->assertJsonValidationErrors('password');
    }

    public function test_changement_connecte_verifie_ancien_mdp_et_revoque_les_autres_sessions(): void
    {
        $user = $this->compte();
        $autre = $user->createToken('autre-appareil')->accessToken;   // à révoquer.
        $courant = $user->createToken('appareil-courant')->plainTextToken;

        // Mauvais ancien mot de passe → 422.
        $this->withHeader('Authorization', 'Bearer '.$courant)
            ->postJson('/api/v1/auth/password/change', [
                'current_password'      => 'FauxMdp#2025',
                'password'              => self::MDP_FORT,
                'password_confirmation' => self::MDP_FORT,
            ])->assertStatus(422);

        // Bon ancien mot de passe → 200, autre session révoquée, session courante conservée.
        $this->withHeader('Authorization', 'Bearer '.$courant)
            ->postJson('/api/v1/auth/password/change', [
                'current_password'      => 'AncienMdp#2025',
                'password'              => self::MDP_FORT,
                'password_confirmation' => self::MDP_FORT,
            ])->assertOk();

        $user->refresh();
        $this->assertTrue(Hash::check(self::MDP_FORT, $user->password));
        $this->assertNull($user->tokens()->find($autre->id), 'L\'autre session doit être révoquée.');
        $this->assertSame(1, $user->tokens()->count(), 'Seule la session courante subsiste.');
    }
}
