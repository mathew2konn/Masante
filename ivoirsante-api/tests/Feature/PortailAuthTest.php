<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\PortailRolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Module 4 / 4.1 — Socle du portail : auth web (sessions) + cloisonnement par rôle (RBAC spatie).
 */
class PortailAuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PortailRolesSeeder::class); // rôles/permissions + admin@masante.ci
    }

    public function test_page_login_accessible_aux_invites(): void
    {
        $this->get('/portail/login')->assertOk()->assertSee('MaSanté');
    }

    public function test_invite_est_redirige_vers_login(): void
    {
        $this->get('/portail')->assertRedirect('/portail/login');
    }

    public function test_admin_de_bootstrap_peut_se_connecter(): void
    {
        $this->post('/portail/login', ['email' => 'admin@masante.ci', 'password' => 'Admin@2026!'])
            ->assertRedirect(route('portail.dashboard'));

        $this->assertAuthenticated();
    }

    public function test_le_dashboard_affiche_les_cartes_du_role_admin(): void
    {
        $admin = User::where('email', 'admin@masante.ci')->first();

        $this->actingAs($admin)->get('/portail')
            ->assertOk()
            ->assertSee('Établissements')
            ->assertSee('Modération');
    }

    public function test_un_compte_sans_role_portail_est_refuse(): void
    {
        $patient = User::factory()->create([
            'email' => 'patient@exemple.ci',
            'password' => Hash::make('Patient@2026!'),
        ]);

        $this->post('/portail/login', ['email' => $patient->email, 'password' => 'Patient@2026!'])
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_mauvais_mot_de_passe_refuse(): void
    {
        $this->post('/portail/login', ['email' => 'admin@masante.ci', 'password' => 'mauvais'])
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_deconnexion(): void
    {
        $admin = User::where('email', 'admin@masante.ci')->first();

        $this->actingAs($admin)->post('/portail/logout')->assertRedirect(route('portail.login'));

        $this->assertGuest();
    }
}
