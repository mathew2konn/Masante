<?php

namespace Tests\Feature;

use App\Models\Medecin;
use App\Models\MembreFamille;
use App\Models\RendezVous;
use App\Models\ServiceEtablissement;
use App\Models\StructureSanitaire;
use App\Models\User;
use App\Services\ReferentService;
use Database\Seeders\PortailRolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Module 4 / 4.4 — API staff (portail Next.js) de validation des RDV, workflow à deux étapes
 * depuis B1-a (CDC_11 §9.1 : « le médecin fait la validation finale »). Mêmes règles que le
 * Blade (service partagé) mais sous auth Sanctum : on vérifie aussi que les permissions
 * `rdv.prevalider`/`rdv.validate` sont bien contrôlées côté token (le middleware spatie
 * viserait le guard web) — ET que chacune n'ouvre QUE l'étape qui est la sienne.
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
        $u->assignRole('personnel_accueil');

        return $u;
    }

    private function medecinUser(ServiceEtablissement $service): User
    {
        $u = User::factory()->create(['structure_id' => $service->structure_id, 'service_id' => $service->id]);
        $u->assignRole('medecin');

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

    public function test_accueil_previsalide_un_rdv_en_attente(): void
    {
        $service = $this->service();
        $rdv = $this->rdv($service);
        Sanctum::actingAs($this->agent($service));

        $this->patchJson("/api/v1/portail/rendez-vous/{$rdv->id}/previsalider", ['message_agent' => 'Dossier vérifié.'])
            ->assertOk()
            ->assertJsonPath('rendez_vous.statut', 'prevalide');

        $this->assertDatabaseHas('rendez_vous', ['id' => $rdv->id, 'statut' => 'prevalide']);
    }

    public function test_accueil_ne_peut_pas_confirmer_directement(): void
    {
        $service = $this->service();
        $rdv = $this->rdv($service); // en_attente
        Sanctum::actingAs($this->agent($service));

        // L'accueil n'a plus `rdv.validate` depuis B1-a : 403, pas 409.
        $this->patchJson("/api/v1/portail/rendez-vous/{$rdv->id}/confirmer", [
            'date_confirmee' => now()->addDays(3)->toDateString(),
        ])->assertStatus(403);

        $this->assertDatabaseHas('rendez_vous', ['id' => $rdv->id, 'statut' => 'en_attente']);
    }

    public function test_medecin_ne_peut_pas_previsalider(): void
    {
        $service = $this->service();
        $rdv = $this->rdv($service);
        Sanctum::actingAs($this->medecinUser($service));

        // `medecin` n'a pas `rdv.prevalider` : ce n'est pas son étape.
        $this->patchJson("/api/v1/portail/rendez-vous/{$rdv->id}/previsalider", [])->assertStatus(403);
    }

    public function test_medecin_confirme_un_rdv_previsalide(): void
    {
        $service = $this->service();
        $rdv = $this->rdv($service, 'prevalide');
        Sanctum::actingAs($this->medecinUser($service));

        $this->patchJson("/api/v1/portail/rendez-vous/{$rdv->id}/confirmer", [
            'date_confirmee' => now()->addDays(3)->toDateString(),
        ])->assertOk()->assertJsonPath('rendez_vous.statut', 'confirme');

        $this->assertDatabaseHas('rendez_vous', ['id' => $rdv->id, 'statut' => 'confirme']);
    }

    public function test_medecin_ne_peut_pas_confirmer_un_rdv_pas_encore_previsalide(): void
    {
        $service = $this->service();
        $rdv = $this->rdv($service); // en_attente, pas prevalide
        Sanctum::actingAs($this->medecinUser($service));

        $this->patchJson("/api/v1/portail/rendez-vous/{$rdv->id}/confirmer", [
            'date_confirmee' => now()->addDays(3)->toDateString(),
        ])->assertStatus(409);
    }

    public function test_un_rdv_deja_previsalide_ne_peut_pas_etre_reprevisalide(): void
    {
        $service = $this->service();
        $rdv = $this->rdv($service, 'prevalide');
        Sanctum::actingAs($this->agent($service));

        $this->patchJson("/api/v1/portail/rendez-vous/{$rdv->id}/previsalider", [])->assertStatus(409);
    }

    public function test_medecin_refuse_un_rdv_previsalide(): void
    {
        $service = $this->service();
        $rdv = $this->rdv($service, 'prevalide');
        Sanctum::actingAs($this->medecinUser($service));

        $this->patchJson("/api/v1/portail/rendez-vous/{$rdv->id}/refuser", ['message_agent' => 'Indisponible.'])
            ->assertOk()
            ->assertJsonPath('rendez_vous.statut', 'refuse');
    }

    public function test_refuser_refuse_un_compte_sans_aucune_des_deux_permissions(): void
    {
        $service = $this->service();
        $rdv = $this->rdv($service);
        Sanctum::actingAs(User::factory()->create());

        $this->patchJson("/api/v1/portail/rendez-vous/{$rdv->id}/refuser", ['message_agent' => 'x'])->assertStatus(403);
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
        // Sur un compte qui A la permission (`medecin`) : c'est bien le STATUT qui refuse (409),
        // pas la permission (403) — sans quoi ce vecteur ne prouverait rien.
        Sanctum::actingAs($this->medecinUser($service));

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

    // ─────────────────────────────────────────────────────────────────────────────
    // B1-b — fiche enrichie (D6/D7) : référent + tarif exposés sur le détail
    // ─────────────────────────────────────────────────────────────────────────────

    public function test_le_detail_expose_le_referent_actif_du_membre(): void
    {
        $service = $this->service();
        $rdv = $this->rdv($service);
        $referent = Medecin::create([
            'structure_id' => $service->structure_id, 'service_id' => $service->id,
            'titre' => 'Dr', 'nom' => 'Traoré', 'prenom' => 'Fatou', 'specialite' => 'urgences', 'actif' => true,
        ]);
        $titulaire = User::factory()->create();
        app(ReferentService::class)->designer($rdv->membre, $referent, $titulaire);
        Sanctum::actingAs($this->agent($service));

        $this->getJson("/api/v1/portail/rendez-vous/{$rdv->id}")
            ->assertOk()
            ->assertJsonPath('referent.medecin.nom', 'Traoré');
    }

    public function test_le_detail_expose_un_referent_null_si_aucun(): void
    {
        $service = $this->service();
        $rdv = $this->rdv($service);
        Sanctum::actingAs($this->agent($service));

        $this->getJson("/api/v1/portail/rendez-vous/{$rdv->id}")
            ->assertOk()
            ->assertJsonPath('referent', null);
    }

    public function test_le_detail_expose_le_tarif_et_sa_source(): void
    {
        $service = $this->service();
        $service->update(['tarif_consultation_cfa' => 15000]);
        $rdv = $this->rdv($service);
        Sanctum::actingAs($this->agent($service));

        // Même méthode que le paiement (`RecuRdvService::tarifPour()`) : un APERÇU, jamais un
        // reçu créé — vérifié en base juste après.
        $this->getJson("/api/v1/portail/rendez-vous/{$rdv->id}")
            ->assertOk()
            ->assertJsonPath('tarif', 15000)
            ->assertJsonPath('tarif_source', 'service');

        $this->assertDatabaseMissing('recus_rdv', ['rendez_vous_id' => $rdv->id]);
    }
}
