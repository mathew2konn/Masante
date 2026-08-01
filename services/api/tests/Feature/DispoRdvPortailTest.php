<?php

namespace Tests\Feature;

use App\Models\Medecin;
use App\Models\MembreFamille;
use App\Models\RendezVous;
use App\Models\ServiceEtablissement;
use App\Models\StructureSanitaire;
use App\Models\User;
use Database\Seeders\PortailRolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Module 4 / 4.4 — Disponibilité + validation RDV, cloisonnées au périmètre (agent = son service,
 * gestionnaire = tous ses services).
 */
class DispoRdvPortailTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PortailRolesSeeder::class);
    }

    private function structure(string $nom = 'CHU Test'): StructureSanitaire
    {
        return StructureSanitaire::create([
            'nom' => $nom, 'type' => 'chu', 'adresse' => 'Abidjan', 'commune' => 'Cocody',
            'latitude' => 5.35, 'longitude' => -3.98, 'actif' => true,
        ]);
    }

    private function service(StructureSanitaire $s, string $nom = 'Urgences', string $spec = 'urgences'): ServiceEtablissement
    {
        return ServiceEtablissement::create(['structure_id' => $s->id, 'nom_service' => $nom, 'specialite' => $spec, 'actif' => true]);
    }

    private function agent(ServiceEtablissement $service): User
    {
        $u = User::factory()->create([
            'password' => Hash::make('Agent@2026!'), 'structure_id' => $service->structure_id, 'service_id' => $service->id,
        ]);
        $u->assignRole('agent_garde');

        return $u;
    }

    private function gestionnaire(StructureSanitaire $s): User
    {
        $u = User::factory()->create(['password' => Hash::make('Gestion@2026!'), 'structure_id' => $s->id]);
        $u->assignRole('gestionnaire_etablissement');

        return $u;
    }

    private function rdv(ServiceEtablissement $service, string $statut = 'en_attente'): RendezVous
    {
        $membre = MembreFamille::factory()->create();

        return RendezVous::create([
            'membre_id' => $membre->id, 'structure_id' => $service->structure_id, 'service_id' => $service->id,
            'motif' => 'Douleur thoracique', 'date_souhaitee' => now()->addDays(2)->toDateString(),
            'mode_attribution' => 'etablissement_attribue', 'statut' => $statut,
        ]);
    }

    // ---- Disponibilité ------------------------------------------------------

    public function test_agent_met_a_jour_la_dispo_de_son_service(): void
    {
        $service = $this->service($this->structure());

        $this->actingAs($this->agent($service))
            ->put(route('portail.disponibilites.update', $service), [
                'date' => now()->toDateString(), 'statut' => 'complet', 'nb_places_restantes' => 0,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('disponibilites_jour', [
            'service_id' => $service->id, 'statut' => 'complet', 'nb_places_restantes' => 0,
        ]);
    }

    public function test_agent_ne_touche_pas_la_dispo_d_un_autre_service(): void
    {
        $mien = $this->service($this->structure('A'));
        $autre = $this->service($this->structure('B'), 'ORL', 'orl');

        $this->actingAs($this->agent($mien))
            ->get(route('portail.disponibilites.edit', $autre))
            ->assertNotFound();
    }

    public function test_gestionnaire_voit_tous_les_services_de_son_etablissement(): void
    {
        $structure = $this->structure();
        $this->service($structure, 'Urgences', 'urgences');
        $this->service($structure, 'Cardiologie', 'cardiologie');

        $this->actingAs($this->gestionnaire($structure))->get(route('portail.disponibilites.index'))
            ->assertOk()->assertSee('Urgences')->assertSee('Cardiologie');
    }

    // ---- Validation RDV -----------------------------------------------------

    public function test_agent_confirme_un_rdv_de_son_service(): void
    {
        $service = $this->service($this->structure());
        $rdv = $this->rdv($service);

        $this->actingAs($this->agent($service))
            ->patch(route('portail.rdv.confirmer', $rdv), [
                'date_confirmee' => now()->addDays(3)->format('Y-m-d H:i'),
                'message_agent' => 'Présentez-vous 15 min avant.',
            ])
            ->assertRedirect(route('portail.rdv.index'));

        $rdv->refresh();
        $this->assertEquals('confirme', $rdv->statut);
        $this->assertNotNull($rdv->date_confirmee);
    }

    public function test_refus_exige_un_motif(): void
    {
        $service = $this->service($this->structure());
        $rdv = $this->rdv($service);

        $this->actingAs($this->agent($service))
            ->patch(route('portail.rdv.refuser', $rdv), ['message_agent' => ''])
            ->assertSessionHasErrors('message_agent');

        $this->assertEquals('en_attente', $rdv->refresh()->statut);
    }

    public function test_agent_ne_voit_pas_le_rdv_d_un_autre_service(): void
    {
        $mien = $this->service($this->structure('A'));
        $autre = $this->service($this->structure('B'), 'ORL', 'orl');
        $rdvAutre = $this->rdv($autre);

        $this->actingAs($this->agent($mien))->get(route('portail.rdv.show', $rdvAutre))->assertNotFound();
    }

    public function test_un_rdv_deja_traite_ne_peut_pas_etre_reconfirme(): void
    {
        $service = $this->service($this->structure());
        $rdv = $this->rdv($service, 'confirme');

        $this->actingAs($this->agent($service))
            ->patch(route('portail.rdv.confirmer', $rdv), ['date_confirmee' => now()->addDay()->format('Y-m-d H:i')])
            ->assertStatus(409);
    }

    public function test_medecin_d_un_autre_service_est_refuse_a_la_confirmation(): void
    {
        $service = $this->service($this->structure());
        $autreService = $this->service($service->structure, 'ORL', 'orl');
        $rdv = $this->rdv($service);
        $medecinAutre = Medecin::create([
            'structure_id' => $service->structure_id, 'service_id' => $autreService->id,
            'nom' => 'Traoré', 'prenom' => 'Ali', 'specialite' => 'orl', 'actif' => true,
        ]);

        $this->actingAs($this->agent($service))
            ->patch(route('portail.rdv.confirmer', $rdv), [
                'date_confirmee' => now()->addDay()->format('Y-m-d H:i'), 'medecin_id' => $medecinAutre->id,
            ])
            ->assertSessionHasErrors('medecin_id');
    }
}
