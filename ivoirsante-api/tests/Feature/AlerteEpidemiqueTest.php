<?php

namespace Tests\Feature;

use App\Models\AlerteEpidemique;
use App\Models\StructureSanitaire;
use App\Models\User;
use Database\Seeders\PortailRolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Module 5 / 5.4 — Alertes épidémiques (FN3).
 *
 * Deux facettes : le ciblage par commune côté patient (mobile), et la gestion réservée à l'admin
 * (portail). L'enjeu de ciblage : un patient ne doit voir QUE ce qui le concerne.
 */
class AlerteEpidemiqueTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PortailRolesSeeder::class);
    }

    private function alerte(array $attrs = []): AlerteEpidemique
    {
        return AlerteEpidemique::create(array_merge([
            'commune' => 'Cocody', 'titre' => 'Paludisme', 'description' => 'Consignes.',
            'maladie' => 'Paludisme', 'niveau_alerte' => 'vigilance', 'source' => 'Ministère',
            'date_debut' => now()->subDay()->toDateString(), 'actif' => true,
        ], $attrs));
    }

    private function admin(): User
    {
        $u = User::factory()->create(['structure_id' => null]);
        $u->assignRole('admin_ivoirsante');

        return $u;
    }

    // ---- Ciblage patient (API) ---------------------------------------------

    public function test_le_patient_voit_les_alertes_de_sa_commune_et_les_nationales(): void
    {
        $this->alerte(['commune' => 'Cocody', 'titre' => 'Palu Cocody']);
        $this->alerte(['commune' => AlerteEpidemique::NATIONALE, 'titre' => 'Choléra national']);
        $this->alerte(['commune' => 'Yopougon', 'titre' => 'Dengue Yopougon']);

        Sanctum::actingAs(User::factory()->create(['commune' => 'Cocody']));

        $reponse = $this->getJson('/api/v1/alertes-epidemiques')->assertOk()->assertJsonCount(2, 'alertes');
        $titres = array_column($reponse->json('alertes'), 'titre');

        $this->assertContains('Palu Cocody', $titres);
        $this->assertContains('Choléra national', $titres);
        $this->assertNotContains('Dengue Yopougon', $titres);
    }

    public function test_un_compte_sans_commune_ne_voit_que_les_alertes_nationales(): void
    {
        $this->alerte(['commune' => 'Cocody', 'titre' => 'Palu Cocody']);
        $this->alerte(['commune' => AlerteEpidemique::NATIONALE, 'titre' => 'Choléra national']);

        Sanctum::actingAs(User::factory()->create(['commune' => null]));

        $this->getJson('/api/v1/alertes-epidemiques')
            ->assertOk()
            ->assertJsonCount(1, 'alertes')
            ->assertJsonPath('alertes.0.titre', 'Choléra national');
    }

    public function test_les_alertes_inactives_ou_hors_periode_ne_sont_pas_diffusees(): void
    {
        $this->alerte(['titre' => 'Retirée', 'actif' => false]);
        $this->alerte(['titre' => 'Future', 'date_debut' => now()->addWeek()->toDateString()]);
        $this->alerte(['titre' => 'Expirée', 'date_debut' => now()->subMonth()->toDateString(), 'date_fin' => now()->subDay()->toDateString()]);
        $this->alerte(['titre' => 'En cours']);

        Sanctum::actingAs(User::factory()->create(['commune' => 'Cocody']));

        $this->getJson('/api/v1/alertes-epidemiques')
            ->assertOk()
            ->assertJsonCount(1, 'alertes')
            ->assertJsonPath('alertes.0.titre', 'En cours');
    }

    public function test_les_alertes_sont_triees_par_gravite(): void
    {
        $this->alerte(['titre' => 'Info', 'niveau_alerte' => 'information']);
        $this->alerte(['titre' => 'Grave', 'niveau_alerte' => 'alerte']);
        $this->alerte(['titre' => 'Moyen', 'niveau_alerte' => 'vigilance']);

        Sanctum::actingAs(User::factory()->create(['commune' => 'Cocody']));

        $titres = array_column($this->getJson('/api/v1/alertes-epidemiques')->json('alertes'), 'titre');
        $this->assertSame(['Grave', 'Moyen', 'Info'], $titres);
    }

    public function test_l_endpoint_exige_une_authentification(): void
    {
        $this->getJson('/api/v1/alertes-epidemiques')->assertUnauthorized();
    }

    // ---- Gestion admin (portail) -------------------------------------------

    public function test_l_admin_publie_une_alerte_communale(): void
    {
        $this->actingAs($this->admin())
            ->post(route('portail.sante-publique.store'), [
                'portee' => 'commune', 'commune_saisie' => 'Cocody',
                'titre' => 'Paludisme', 'description' => 'Consignes.', 'maladie' => 'Paludisme',
                'niveau_alerte' => 'alerte', 'source' => 'Ministère', 'date_debut' => now()->toDateString(),
            ])
            ->assertRedirect(route('portail.sante-publique.index'));

        $this->assertDatabaseHas('alertes_epidemiques', ['commune' => 'Cocody', 'maladie' => 'Paludisme']);
    }

    public function test_le_choix_nationale_enregistre_la_sentinelle(): void
    {
        $this->actingAs($this->admin())->post(route('portail.sante-publique.store'), [
            'portee' => 'nationale', 'commune_saisie' => 'ignorée',
            'titre' => 'Choléra', 'description' => 'National.', 'maladie' => 'Choléra',
            'niveau_alerte' => 'vigilance', 'source' => 'OMS', 'date_debut' => now()->toDateString(),
        ]);

        $this->assertDatabaseHas('alertes_epidemiques', ['commune' => AlerteEpidemique::NATIONALE, 'maladie' => 'Choléra']);
    }

    public function test_une_date_de_fin_anterieure_au_debut_est_refusee(): void
    {
        $this->actingAs($this->admin())->post(route('portail.sante-publique.store'), [
            'portee' => 'commune', 'commune_saisie' => 'Cocody',
            'titre' => 'Test', 'description' => 'x', 'maladie' => 'Paludisme', 'niveau_alerte' => 'alerte',
            'source' => 'Ministère', 'date_debut' => now()->toDateString(), 'date_fin' => now()->subDay()->toDateString(),
        ])->assertSessionHasErrors('date_fin');

        $this->assertDatabaseCount('alertes_epidemiques', 0);
    }

    public function test_le_toggle_retire_une_alerte_sans_la_supprimer(): void
    {
        $alerte = $this->alerte();

        $this->actingAs($this->admin())->patch(route('portail.sante-publique.toggle', $alerte))->assertRedirect();

        $this->assertFalse($alerte->fresh()->actif);
        $this->assertDatabaseCount('alertes_epidemiques', 1);   // désactivée, pas supprimée
    }

    public function test_la_gestion_est_reservee_a_l_admin(): void
    {
        $structure = StructureSanitaire::create([
            'nom' => 'CHU', 'type' => 'chu', 'adresse' => 'A', 'commune' => 'Cocody',
            'latitude' => 5.3, 'longitude' => -4.0, 'actif' => true,
        ]);
        $gestionnaire = User::factory()->create(['structure_id' => $structure->id]);
        $gestionnaire->assignRole('gestionnaire_etablissement');

        $this->actingAs($gestionnaire)->get(route('portail.sante-publique.index'))->assertForbidden();
    }
}
