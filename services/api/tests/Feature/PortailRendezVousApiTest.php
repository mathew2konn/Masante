<?php

namespace Tests\Feature;

use App\Models\MembreFamille;
use App\Models\RendezVous;
use App\Models\ServiceEtablissement;
use App\Models\StructureSanitaire;
use App\Models\User;
use Database\Seeders\PortailRolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Module 4 / 4.4 — API staff (portail Next.js) de validation des RDV. Mêmes règles que le Blade
 * (service partagé) mais sous auth Sanctum : on vérifie aussi que la permission `rdv.validate`
 * est bien contrôlée côté token (le middleware spatie viserait le guard web).
 */
class PortailRendezVousApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PortailRolesSeeder::class);
    }

    private function service(string $nom = 'Urgences'): ServiceEtablissement
    {
        $s = StructureSanitaire::create([
            'nom' => 'CHU Test', 'type' => 'chu', 'adresse' => 'Abidjan', 'commune' => 'Cocody',
            'latitude' => 5.35, 'longitude' => -3.98, 'actif' => true,
        ]);

        return ServiceEtablissement::create([
            'structure_id' => $s->id, 'nom_service' => $nom, 'specialite' => 'urgences', 'actif' => true,
        ]);
    }

    private function agent(ServiceEtablissement $service): User
    {
        $u = User::factory()->create(['structure_id' => $service->structure_id, 'service_id' => $service->id]);
        $u->assignRole('agent_garde');

        return $u;
    }

    private function rdv(ServiceEtablissement $service, string $statut = 'en_attente'): RendezVous
    {
        return RendezVous::create([
            'membre_id' => MembreFamille::factory()->create()->id,
            'structure_id' => $service->structure_id, 'service_id' => $service->id,
            'motif' => 'Douleur thoracique', 'date_souhaitee' => now()->addDays(2)->toDateString(),
            'mode_attribution' => 'etablissement_attribue', 'statut' => $statut,
        ]);
    }

    public function test_agent_liste_les_rdv_en_attente_de_son_service(): void
    {
        $service = $this->service();
        $rdv = $this->rdv($service);
        Sanctum::actingAs($this->agent($service));

        $this->getJson('/api/v1/portail/rendez-vous')
            ->assertOk()
            ->assertJsonPath('data.0.id', $rdv->id);
    }

    public function test_agent_confirme_un_rdv(): void
    {
        $service = $this->service();
        $rdv = $this->rdv($service);
        Sanctum::actingAs($this->agent($service));

        $this->patchJson("/api/v1/portail/rendez-vous/{$rdv->id}/confirmer", [
            'date_confirmee' => now()->addDays(3)->toDateString(),
        ])->assertOk()->assertJsonPath('rendez_vous.statut', 'confirme');

        $this->assertDatabaseHas('rendez_vous', ['id' => $rdv->id, 'statut' => 'confirme']);
    }

    public function test_refuser_exige_un_motif(): void
    {
        $service = $this->service();
        $rdv = $this->rdv($service);
        Sanctum::actingAs($this->agent($service));

        $this->patchJson("/api/v1/portail/rendez-vous/{$rdv->id}/refuser", [])->assertStatus(422);

        $this->patchJson("/api/v1/portail/rendez-vous/{$rdv->id}/refuser", ['message_agent' => 'Service complet'])
            ->assertOk()
            ->assertJsonPath('rendez_vous.statut', 'refuse');
    }

    public function test_rdv_hors_perimetre_est_404(): void
    {
        $autre = $this->service('Cardiologie');
        $rdv = $this->rdv($autre);
        Sanctum::actingAs($this->agent($this->service('Pédiatrie')));

        $this->getJson("/api/v1/portail/rendez-vous/{$rdv->id}")->assertNotFound();
    }

    public function test_rdv_deja_traite_renvoie_409(): void
    {
        $service = $this->service();
        $rdv = $this->rdv($service, 'confirme');
        Sanctum::actingAs($this->agent($service));

        $this->patchJson("/api/v1/portail/rendez-vous/{$rdv->id}/confirmer", [
            'date_confirmee' => now()->addDays(3)->toDateString(),
        ])->assertStatus(409);
    }

    public function test_compte_sans_permission_est_403(): void
    {
        $service = $this->service();
        $this->rdv($service);
        // Utilisateur sans le rôle staff (donc sans `rdv.validate`).
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/v1/portail/rendez-vous')->assertStatus(403);
    }
}
