<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * P1 (Identité) — le compte citoyen reçoit le rôle `patient` et l'API expose les rôles.
 * Les rôles font autorité côté backend (RBAC, CDC_10 §3.6) ; le front les affiche.
 */
class AuthRoleTest extends TestCase
{
    use RefreshDatabase;

    public function test_inscription_attribue_le_role_patient(): void
    {
        $this->seed(RoleSeeder::class);

        $this->postJson('/api/v1/auth/register', [
            'telephone' => '+2250700000010',
            'nom' => 'Kouassi',
            'prenom' => 'Jean',
            'password' => 'Patient@2026!',
            'password_confirmation' => 'Patient@2026!',
        ])->assertCreated();

        $user = User::where('telephone', '+2250700000010')->firstOrFail();
        $this->assertTrue($user->hasRole('patient'));
    }

    public function test_me_expose_les_roles(): void
    {
        $this->seed(RoleSeeder::class);
        $user = User::factory()->create(['telephone' => '+2250700000011']);
        $user->assignRole('patient');

        Sanctum::actingAs($user);

        $this->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('user.roles.0', 'patient');
    }
}
