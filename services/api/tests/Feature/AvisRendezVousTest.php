<?php

namespace Tests\Feature;

use App\Models\MembreFamille;
use App\Models\RendezVous;
use App\Models\ServiceEtablissement;
use App\Models\StructureSanitaire;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Étape 3A.2 — Avis (F3.9), signalements (F3.10) et demande de RDV (F3.6).
 * On vérifie les accès (public vs auth), la modération, la dénormalisation de la note,
 * l'anonymat des signalements et l'isolation anti-IDOR des rendez-vous.
 */
class AvisRendezVousTest extends TestCase
{
    use RefreshDatabase;

    private function structure(): StructureSanitaire
    {
        return StructureSanitaire::create([
            'nom' => 'CHU Test', 'type' => 'chu', 'adresse' => 'Abidjan', 'commune' => 'Cocody',
            'latitude' => 5.35, 'longitude' => -3.99,
        ]);
    }

    private function service(StructureSanitaire $s): ServiceEtablissement
    {
        return ServiceEtablissement::create([
            'structure_id' => $s->id, 'nom_service' => 'Cardiologie', 'specialite' => 'cardiologie', 'actif' => true,
        ]);
    }

    /** Crée un membre (user_id et matricule_ivs ne sont pas mass-assignables, comme en prod). */
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

    // --- Avis ---

    public function test_avis_depot_recalcule_la_note_et_liste_publique(): void
    {
        $structure = $this->structure();
        Sanctum::actingAs(User::factory()->create());

        $this->postJson("/api/v1/structures/{$structure->id}/avis", ['note' => 4, 'commentaire' => 'Bon accueil'])
            ->assertCreated()
            ->assertJsonPath('avis.note', 4)
            ->assertJsonPath('avis.consultation_verifiee', false)
            ->assertJsonMissingPath('avis.user_id');

        $this->assertEquals(4.0, $structure->fresh()->note_moyenne);
        $this->assertEquals(1, $structure->fresh()->nb_avis);

        // Lecture publique (sans token).
        $this->getJson("/api/v1/structures/{$structure->id}/avis")
            ->assertOk()
            ->assertJsonCount(1, 'avis');
    }

    public function test_avis_un_seul_par_utilisateur_mise_a_jour(): void
    {
        $structure = $this->structure();
        Sanctum::actingAs(User::factory()->create());

        $this->postJson("/api/v1/structures/{$structure->id}/avis", ['note' => 2]);
        $this->postJson("/api/v1/structures/{$structure->id}/avis", ['note' => 5]);

        $this->assertEquals(1, $structure->avis()->count());
        $this->assertEquals(5, $structure->avis()->first()->note);
    }

    public function test_avis_avec_mot_interdit_est_modere_et_invisible(): void
    {
        $structure = $this->structure();
        Sanctum::actingAs(User::factory()->create());

        $this->postJson("/api/v1/structures/{$structure->id}/avis", ['note' => 1, 'commentaire' => 'quelle arnaque'])
            ->assertCreated()
            ->assertJsonPath('avis.visible', false)
            ->assertJsonPath('avis.signale', true);

        // Masqué de la liste publique + n'entre pas dans la note moyenne.
        $this->getJson("/api/v1/structures/{$structure->id}/avis")->assertJsonCount(0, 'avis');
        $this->assertEquals(0, $structure->fresh()->nb_avis);
    }

    public function test_avis_depot_exige_un_compte(): void
    {
        $structure = $this->structure();
        $this->postJson("/api/v1/structures/{$structure->id}/avis", ['note' => 4])->assertUnauthorized();
    }

    // --- Signalements ---

    public function test_signalement_anonyme_accepte_sans_compte(): void
    {
        $structure = $this->structure();

        $this->postJson("/api/v1/structures/{$structure->id}/signalements", [
            'type' => 'structure_fermee', 'description' => 'Portail fermé à 10h',
        ])->assertCreated()->assertJsonPath('signalement.statut', 'en_attente');

        $this->assertDatabaseHas('signalements', ['structure_id' => $structure->id, 'user_id' => null]);
    }

    public function test_signalement_index_ne_montre_que_les_valides_publics(): void
    {
        $structure = $this->structure();
        $structure->signalements()->create(['type' => 'autre', 'description' => 'A', 'statut' => 'valide', 'visible_publiquement' => true]);
        $structure->signalements()->create(['type' => 'autre', 'description' => 'B', 'statut' => 'en_attente', 'visible_publiquement' => false]);

        $this->getJson("/api/v1/structures/{$structure->id}/signalements")
            ->assertOk()
            ->assertJsonCount(1, 'signalements');
    }

    // --- Rendez-vous ---

    public function test_rdv_creation_et_liste_du_compte(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $structure = $this->structure();
        $service = $this->service($structure);
        $membre = $this->membre($user, 'IVS-2026-AA-00001');

        $this->postJson('/api/v1/rendez-vous', [
            'membre_id' => $membre->id, 'structure_id' => $structure->id, 'service_id' => $service->id,
            'motif' => 'Douleur thoracique', 'date_souhaitee' => Carbon::tomorrow()->toDateString(),
        ])->assertCreated()->assertJsonPath('rendez_vous.statut', 'en_attente');

        $this->getJson('/api/v1/rendez-vous')->assertOk()->assertJsonCount(1, 'rendez_vous');
    }

    public function test_rdv_anti_idor_membre_d_un_autre_compte_refuse(): void
    {
        $autre = User::factory()->create();
        $membreAutre = $this->membre($autre, 'IVS-2026-BB-00002');

        $structure = $this->structure();
        $service = $this->service($structure);

        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/v1/rendez-vous', [
            'membre_id' => $membreAutre->id, 'structure_id' => $structure->id, 'service_id' => $service->id,
            'motif' => 'Test', 'date_souhaitee' => Carbon::tomorrow()->toDateString(),
        ])->assertForbidden();
    }

    public function test_rdv_service_hors_structure_rejete(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $structureA = $this->structure();
        $structureB = $this->structure();
        $serviceB = $this->service($structureB); // service d'une AUTRE structure
        $membre = $this->membre($user, 'IVS-2026-CC-00003');

        $this->postJson('/api/v1/rendez-vous', [
            'membre_id' => $membre->id, 'structure_id' => $structureA->id, 'service_id' => $serviceB->id,
            'motif' => 'Test', 'date_souhaitee' => Carbon::tomorrow()->toDateString(),
        ])->assertStatus(422);
    }

    public function test_rdv_annulation_par_le_patient(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $structure = $this->structure();
        $service = $this->service($structure);
        $membre = $this->membre($user, 'IVS-2026-DD-00004');
        $rdv = RendezVous::create([
            'membre_id' => $membre->id, 'structure_id' => $structure->id, 'service_id' => $service->id,
            'motif' => 'Test', 'date_souhaitee' => Carbon::tomorrow()->toDateString(), 'statut' => 'en_attente',
        ]);

        $this->patchJson("/api/v1/rendez-vous/{$rdv->id}/annuler")
            ->assertOk()
            ->assertJsonPath('rendez_vous.statut', 'annule');
    }
}
