<?php

namespace Tests\Feature;

use App\Models\Medecin;
use App\Models\ServiceEtablissement;
use App\Models\StructureSanitaire;
use App\Models\User;
use Database\Seeders\PortailRolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Module 5 / 5.6 — Annuaire des praticiens géré par le gestionnaire.
 *
 * Le trou que ce test verrouille : l'annuaire `medecins` n'était alimenté QUE par le seeder. Un
 * établissement créé après coup n'avait donc aucun praticien — donc aucune fiche à relier à un
 * compte, donc aucun médecin référent possible chez lui. Le gestionnaire doit pouvoir créer ses
 * fiches, les relier à ses agents, sans jamais toucher à celles d'un autre établissement.
 *
 * On y vérifie aussi la recherche mot à mot de l'annuaire public : un patient tape « Aya Kouamé »,
 * pas un nom de famille isolé.
 */
class PortailMedecinTest extends TestCase
{
    use RefreshDatabase;

    private StructureSanitaire $structure;

    private ServiceEtablissement $service;

    private User $gestionnaire;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PortailRolesSeeder::class);

        $this->structure = StructureSanitaire::create([
            'nom' => 'Clinique de Morofé', 'type' => 'clinique_privee', 'adresse' => 'Yamoussoukro',
            'commune' => 'Yamoussoukro', 'latitude' => 6.82, 'longitude' => -5.27, 'actif' => true,
        ]);
        $this->service = ServiceEtablissement::create([
            'structure_id' => $this->structure->id, 'nom_service' => 'Cardiologie',
            'specialite' => 'cardiologie', 'actif' => true,
        ]);

        $this->gestionnaire = User::factory()->create(['structure_id' => $this->structure->id]);
        $this->gestionnaire->assignRole('gestionnaire_etablissement');
    }

    private function agent(): User
    {
        $agent = User::factory()->create([
            'structure_id' => $this->structure->id,
            'service_id'   => $this->service->id,
        ]);
        $agent->assignRole('agent_garde');

        return $agent;
    }

    public function test_le_gestionnaire_cree_une_fiche_et_la_relie_a_un_compte(): void
    {
        $agent = $this->agent();

        $this->actingAs($this->gestionnaire)
            ->post('/portail/medecins', [
                'titre' => 'Dr', 'prenom' => 'Kablan', 'nom' => 'Koffi',
                'specialite' => 'Cardiologie', 'service_id' => $this->service->id,
                'tarif_consultation' => 10000, 'user_id' => $agent->id,
            ])
            ->assertRedirect(route('portail.medecins.index'));

        $fiche = Medecin::first();
        $this->assertSame('Dr Kablan Koffi', $fiche->nom_complet);
        $this->assertSame($this->structure->id, $fiche->structure_id);
        // Le lien est ce qui rend la voie 2 opérante : sans lui, la fiche est visible mais muette.
        $this->assertSame($agent->id, $fiche->user_id);
        $this->assertTrue($fiche->consulte_en_ligne);
        $this->assertTrue($agent->fresh()->medecin->is($fiche));
    }

    public function test_un_compte_ne_peut_pas_etre_relie_a_deux_fiches(): void
    {
        $agent = $this->agent();

        Medecin::create([
            'structure_id' => $this->structure->id, 'service_id' => $this->service->id,
            'user_id' => $agent->id, 'titre' => 'Dr', 'nom' => 'Koffi', 'prenom' => 'Kablan',
            'specialite' => 'Cardiologie', 'actif' => true,
        ]);

        $this->actingAs($this->gestionnaire)
            ->post('/portail/medecins', [
                'titre' => 'Dr', 'prenom' => 'Autre', 'nom' => 'Praticien',
                'specialite' => 'Pédiatrie', 'service_id' => $this->service->id,
                'user_id' => $agent->id,
            ])
            ->assertSessionHasErrors('user_id');

        $this->assertSame(1, Medecin::count());
    }

    public function test_le_gestionnaire_ne_touche_pas_aux_praticiens_d_un_autre_etablissement(): void
    {
        $autre = StructureSanitaire::create([
            'nom' => 'CHU de Cocody', 'type' => 'chu', 'adresse' => 'Abidjan', 'commune' => 'Cocody',
            'latitude' => 5.35, 'longitude' => -3.98, 'actif' => true,
        ]);
        $autreService = ServiceEtablissement::create([
            'structure_id' => $autre->id, 'nom_service' => 'Urgences',
            'specialite' => 'urgences', 'actif' => true,
        ]);
        $ficheAutre = Medecin::create([
            'structure_id' => $autre->id, 'service_id' => $autreService->id,
            'titre' => 'Dr', 'nom' => 'Traoré', 'prenom' => 'Ismaël',
            'specialite' => 'Urgences', 'actif' => true,
        ]);

        // Ni en lecture, ni en écriture : 404 (on ne confirme même pas l'existence de la fiche).
        $this->actingAs($this->gestionnaire)
            ->get("/portail/medecins/{$ficheAutre->id}/editer")
            ->assertNotFound();

        $this->actingAs($this->gestionnaire)
            ->patch("/portail/medecins/{$ficheAutre->id}/actif")
            ->assertNotFound();

        $this->assertTrue($ficheAutre->fresh()->actif);

        // Et sa liste ne montre que SES praticiens.
        $this->actingAs($this->gestionnaire)
            ->get('/portail/medecins')
            ->assertOk()
            ->assertDontSee('Traoré');
    }

    public function test_desactiver_une_fiche_la_retire_de_l_annuaire_public(): void
    {
        $fiche = Medecin::create([
            'structure_id' => $this->structure->id, 'service_id' => $this->service->id,
            'titre' => 'Dr', 'nom' => 'Koffi', 'prenom' => 'Kablan',
            'specialite' => 'Cardiologie', 'actif' => true,
        ]);

        $this->actingAs($this->gestionnaire)->patch("/portail/medecins/{$fiche->id}/actif")->assertRedirect();

        $this->assertFalse($fiche->fresh()->actif);
        $this->getJson('/api/v1/medecins?q=Koffi')->assertOk()->assertJsonCount(0, 'medecins');
    }

    public function test_la_recherche_publique_accepte_plusieurs_mots(): void
    {
        Medecin::create([
            'structure_id' => $this->structure->id, 'service_id' => $this->service->id,
            'titre' => 'Dr', 'nom' => 'Kouamé', 'prenom' => 'Aya',
            'specialite' => 'Cardiologie', 'actif' => true,
        ]);

        // Ce qu'un patient tape réellement : un prénom et un nom, parfois précédés du titre.
        foreach (['Kouamé', 'Aya Kouamé', 'Kouamé Aya', 'Dr Kouamé', 'Kouamé cardiologie'] as $saisie) {
            $this->getJson('/api/v1/medecins?q='.urlencode($saisie))
                ->assertOk()
                ->assertJsonCount(1, 'medecins');
        }

        // Un mot qui ne correspond à rien exclut : les mots affinent, ils ne s'ignorent pas.
        $this->getJson('/api/v1/medecins?q='.urlencode('Aya Traoré'))
            ->assertOk()
            ->assertJsonCount(0, 'medecins');
    }
}
