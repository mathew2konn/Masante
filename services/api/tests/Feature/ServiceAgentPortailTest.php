<?php

namespace Tests\Feature;

use App\Models\ServiceEtablissement;
use App\Models\StructureSanitaire;
use App\Models\User;
use Database\Seeders\PortailRolesSeeder;
use Database\Seeders\SpecialiteMedicaleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Module 4 / 4.3 — Services + agents gérés par le gestionnaire, CLOISONNÉS à son établissement.
 */
class ServiceAgentPortailTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PortailRolesSeeder::class);
        // P6.8a — le vocabulaire des spécialités est désormais une PRÉCONDITION de la création d'un
        // service : le formulaire n'accepte plus un code libre, il choisit dans le référentiel
        // national. Sans ce seeder, ce test ne prouverait plus rien d'autre que l'absence de
        // vocabulaire (il échouait effectivement à la bascule, et c'est ce qui devait arriver).
        $this->seed(SpecialiteMedicaleSeeder::class);
    }

    private function structure(string $nom = 'CHU Test'): StructureSanitaire
    {
        return StructureSanitaire::create([
            'nom' => $nom, 'type' => 'chu', 'adresse' => 'Abidjan', 'commune' => 'Cocody',
            'latitude' => 5.35, 'longitude' => -3.98, 'actif' => true,
        ]);
    }

    private function gestionnaire(StructureSanitaire $structure): User
    {
        $user = User::factory()->create(['password' => Hash::make('Gestion@2026!'), 'structure_id' => $structure->id]);
        $user->assignRole('gestionnaire_etablissement');

        return $user;
    }

    public function test_gestionnaire_ne_voit_que_ses_services(): void
    {
        $mienne = $this->structure('Mon CHU');
        $autre = $this->structure('Autre CHU');
        ServiceEtablissement::create(['structure_id' => $mienne->id, 'nom_service' => 'Cardiologie A', 'specialite' => 'cardiologie', 'actif' => true]);
        ServiceEtablissement::create(['structure_id' => $autre->id, 'nom_service' => 'ORL B', 'specialite' => 'orl', 'actif' => true]);

        $this->actingAs($this->gestionnaire($mienne))->get('/portail/services')
            ->assertOk()
            ->assertSee('Cardiologie A')
            ->assertDontSee('ORL B');
    }

    public function test_gestionnaire_cree_un_service_dans_son_etablissement(): void
    {
        $structure = $this->structure();

        $this->actingAs($this->gestionnaire($structure))
            ->post('/portail/services', ['nom_service' => 'Urgences', 'specialite' => 'urgences'])
            ->assertRedirect(route('portail.services.index'));

        $this->assertDatabaseHas('services_etablissement', [
            'structure_id' => $structure->id, 'nom_service' => 'Urgences', 'specialite' => 'urgences',
        ]);
    }

    public function test_gestionnaire_ne_peut_pas_editer_le_service_d_un_autre(): void
    {
        $mienne = $this->structure('Mon CHU');
        $autre = $this->structure('Autre CHU');
        $serviceAutre = ServiceEtablissement::create(['structure_id' => $autre->id, 'nom_service' => 'ORL', 'specialite' => 'orl', 'actif' => true]);

        $this->actingAs($this->gestionnaire($mienne))
            ->get(route('portail.services.edit', $serviceAutre))
            ->assertNotFound();
    }

    public function test_admin_sans_etablissement_ne_gere_pas_les_services(): void
    {
        $admin = User::where('email', 'admin@masante.ci')->first();

        $this->actingAs($admin)->get('/portail/services')->assertForbidden();
    }

    public function test_gestionnaire_cree_un_agent_sans_mot_de_passe_avec_lien(): void
    {
        $structure = $this->structure();
        $service = ServiceEtablissement::create(['structure_id' => $structure->id, 'nom_service' => 'Urgences', 'specialite' => 'urgences', 'actif' => true]);

        $this->actingAs($this->gestionnaire($structure))
            ->post('/portail/agents', [
                'prenom' => 'Koffi', 'nom' => 'Yao', 'email' => 'koffi.yao@chu.ci', 'service_id' => $service->id,
            ])
            ->assertRedirect(route('portail.agents.index'))
            ->assertSessionHas('lien_activation');

        $agent = User::where('email', 'koffi.yao@chu.ci')->first();
        $this->assertNotNull($agent);
        $this->assertNull($agent->password);
        $this->assertEquals($structure->id, $agent->structure_id);
        $this->assertEquals($service->id, $agent->service_id);
        $this->assertTrue($agent->hasRole('agent_garde'));
        $this->assertDatabaseHas('activations_portail', ['user_id' => $agent->id, 'used_at' => null]);
    }

    public function test_agent_ne_peut_pas_etre_affecte_au_service_d_un_autre_etablissement(): void
    {
        $mienne = $this->structure('Mon CHU');
        $autre = $this->structure('Autre CHU');
        $serviceAutre = ServiceEtablissement::create(['structure_id' => $autre->id, 'nom_service' => 'ORL', 'specialite' => 'orl', 'actif' => true]);

        $this->actingAs($this->gestionnaire($mienne))
            ->post('/portail/agents', [
                'prenom' => 'X', 'nom' => 'Y', 'email' => 'x.y@chu.ci', 'service_id' => $serviceAutre->id,
            ])
            ->assertSessionHasErrors('service_id');

        $this->assertDatabaseMissing('users', ['email' => 'x.y@chu.ci']);
    }

    public function test_un_agent_ne_peut_pas_gerer_services_ni_agents(): void
    {
        $structure = $this->structure();
        $agent = User::factory()->create(['password' => Hash::make('Agent@2026!'), 'structure_id' => $structure->id]);
        $agent->assignRole('agent_garde');

        $this->actingAs($agent)->get('/portail/services')->assertForbidden();
        $this->actingAs($agent)->get('/portail/agents')->assertForbidden();
    }

    public function test_gestionnaire_desactive_un_service(): void
    {
        $structure = $this->structure();
        $service = ServiceEtablissement::create(['structure_id' => $structure->id, 'nom_service' => 'ORL', 'specialite' => 'orl', 'actif' => true]);

        $this->actingAs($this->gestionnaire($structure))
            ->patch(route('portail.services.toggle', $service))
            ->assertRedirect(route('portail.services.index'));

        $this->assertFalse($service->refresh()->actif);
    }
}
