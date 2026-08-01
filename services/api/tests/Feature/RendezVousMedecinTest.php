<?php

namespace Tests\Feature;

use App\Models\Medecin;
use App\Models\MembreFamille;
use App\Models\ServiceEtablissement;
use App\Models\StructureSanitaire;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Module 3 / F3.5 — Choix du médecin au rendez-vous (Analyse_Delta_RDV N5).
 * Vérifie l'exposition publique des praticiens dans la fiche, la dérivation du mode d'attribution
 * (patient_choisit / etablissement_attribue) et l'isolation médecin ↔ service ↔ structure.
 */
class RendezVousMedecinTest extends TestCase
{
    use RefreshDatabase;

    private function structure(): StructureSanitaire
    {
        return StructureSanitaire::create([
            'nom' => 'CHU Test', 'type' => 'chu', 'adresse' => 'Abidjan', 'commune' => 'Cocody',
            'latitude' => 5.35, 'longitude' => -3.99,
        ]);
    }

    private function service(StructureSanitaire $s, string $specialite = 'cardiologie'): ServiceEtablissement
    {
        return ServiceEtablissement::create([
            'structure_id' => $s->id, 'nom_service' => 'Cardiologie', 'specialite' => $specialite, 'actif' => true,
        ]);
    }

    private function medecin(StructureSanitaire $s, ServiceEtablissement $service, array $attrs = []): Medecin
    {
        return Medecin::create(array_merge([
            'structure_id' => $s->id, 'service_id' => $service->id,
            'titre' => 'Dr', 'nom' => 'Koffi', 'prenom' => 'Serge', 'specialite' => 'Cardiologie',
            'tarif_consultation' => 15000, 'actif' => true,
        ], $attrs));
    }

    private function membre(User $user, string $matricule): MembreFamille
    {
        $membre = new MembreFamille([
            'nom' => 'Koffi', 'prenom' => 'Awa', 'date_naissance' => '2000-01-01', 'sexe' => 'F',
        ]);
        $membre->user_id = $user->id;
        $membre->matricule_ivs = $matricule;
        $membre->save();

        return $membre;
    }

    public function test_fiche_expose_les_medecins_actifs_sous_les_services_en_public(): void
    {
        $structure = $this->structure();
        $service = $this->service($structure);
        $this->medecin($structure, $service, ['nom' => 'Yao', 'prenom' => 'Marc']);
        $this->medecin($structure, $service, ['actif' => false]); // inactif → masqué

        // Lecture publique (sans token).
        $this->getJson("/api/v1/structures/{$structure->id}")
            ->assertOk()
            ->assertJsonCount(1, 'structure.services.0.medecins')
            ->assertJsonPath('structure.services.0.medecins.0.nom', 'Yao')
            ->assertJsonPath('structure.services.0.medecins.0.tarif_consultation', 15000);
    }

    public function test_rdv_avec_medecin_choisi_derive_le_mode_patient_choisit(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $structure = $this->structure();
        $service = $this->service($structure);
        $medecin = $this->medecin($structure, $service);
        $membre = $this->membre($user, 'IVS-2026-MA-00001');

        $this->postJson('/api/v1/rendez-vous', [
            'membre_id' => $membre->id, 'structure_id' => $structure->id, 'service_id' => $service->id,
            'medecin_id' => $medecin->id, 'motif' => 'Suivi', 'date_souhaitee' => Carbon::tomorrow()->toDateString(),
        ])
            ->assertCreated()
            ->assertJsonPath('rendez_vous.medecin_id', $medecin->id)
            ->assertJsonPath('rendez_vous.mode_attribution', 'patient_choisit');
    }

    public function test_rdv_sans_medecin_derive_le_mode_etablissement_attribue(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $structure = $this->structure();
        $service = $this->service($structure);
        $membre = $this->membre($user, 'IVS-2026-MB-00002');

        $this->postJson('/api/v1/rendez-vous', [
            'membre_id' => $membre->id, 'structure_id' => $structure->id, 'service_id' => $service->id,
            'motif' => 'Consultation', 'date_souhaitee' => Carbon::tomorrow()->toDateString(),
        ])
            ->assertCreated()
            ->assertJsonPath('rendez_vous.medecin_id', null)
            ->assertJsonPath('rendez_vous.mode_attribution', 'etablissement_attribue');
    }

    public function test_rdv_medecin_d_un_autre_service_rejete(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $structure = $this->structure();
        $serviceCardio = $this->service($structure);
        $serviceOrl = $this->service($structure, 'orl');
        $medecinOrl = $this->medecin($structure, $serviceOrl); // médecin d'un AUTRE service
        $membre = $this->membre($user, 'IVS-2026-MC-00003');

        $this->postJson('/api/v1/rendez-vous', [
            'membre_id' => $membre->id, 'structure_id' => $structure->id, 'service_id' => $serviceCardio->id,
            'medecin_id' => $medecinOrl->id, 'motif' => 'Test', 'date_souhaitee' => Carbon::tomorrow()->toDateString(),
        ])->assertStatus(422);
    }

    public function test_rdv_medecin_d_une_autre_structure_rejete(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $structureA = $this->structure();
        $structureB = $this->structure();
        $serviceA = $this->service($structureA);
        $serviceB = $this->service($structureB);
        $medecinB = $this->medecin($structureB, $serviceB); // médecin d'une AUTRE structure
        $membre = $this->membre($user, 'IVS-2026-MD-00004');

        $this->postJson('/api/v1/rendez-vous', [
            'membre_id' => $membre->id, 'structure_id' => $structureA->id, 'service_id' => $serviceA->id,
            'medecin_id' => $medecinB->id, 'motif' => 'Test', 'date_souhaitee' => Carbon::tomorrow()->toDateString(),
        ])->assertStatus(422);
    }
}
