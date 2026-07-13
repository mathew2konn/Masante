<?php

namespace Tests\Feature;

use App\Models\AccesDossier;
use App\Models\Medecin;
use App\Models\MembreFamille;
use App\Models\Referent;
use App\Models\ServiceEtablissement;
use App\Models\StructureSanitaire;
use App\Models\User;
use Database\Seeders\PortailRolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Module 5 / 5.6 — Voie 2 « médecin référent » (Sécurité §4.4 ; Note_Continuite §2).
 *
 * La voie la plus permissive des quatre : un accès permanent, sans QR. Ce qui doit donc tenir —
 * le patient DÉSIGNE (personne d'autre), le patient RÉVOQUE et l'accès cesse à l'instant, chaque
 * ouverture est journalisée comme un scan, un praticien non désigné ne voit rien, et un compte non
 * relié à une fiche d'annuaire ne peut rien ouvrir même avec la permission.
 */
class MedecinReferentTest extends TestCase
{
    use RefreshDatabase;

    private User $titulaire;

    private MembreFamille $membre;

    private Medecin $medecin;

    private User $compteMedecin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PortailRolesSeeder::class);

        $this->titulaire = User::factory()->create();
        $this->membre = MembreFamille::factory()->for($this->titulaire)->create();

        $structure = StructureSanitaire::create([
            'nom' => 'CHU Test', 'type' => 'chu', 'adresse' => 'Abidjan', 'commune' => 'Cocody',
            'latitude' => 5.35, 'longitude' => -3.98, 'actif' => true,
        ]);
        $service = ServiceEtablissement::create([
            'structure_id' => $structure->id, 'nom_service' => 'Diabétologie',
            'specialite' => 'endocrinologie', 'actif' => true,
        ]);

        // Compte portail du praticien, relié à sa fiche d'annuaire par le gestionnaire.
        $this->compteMedecin = User::factory()->create([
            'structure_id' => $structure->id, 'service_id' => $service->id,
        ]);
        $this->compteMedecin->assignRole('agent_garde');

        $this->medecin = Medecin::create([
            'structure_id' => $structure->id, 'service_id' => $service->id,
            'user_id' => $this->compteMedecin->id,
            'titre' => 'Dr', 'nom' => 'Koffi', 'prenom' => 'Aya',
            'specialite' => 'Endocrinologie', 'actif' => true,
        ]);
    }

    /** Le titulaire désigne le médecin depuis l'application. */
    private function designer(): void
    {
        Sanctum::actingAs($this->titulaire);

        $this->postJson("/api/v1/membres/{$this->membre->id}/referent", [
            'medecin_id' => $this->medecin->id,
        ])->assertCreated();
    }

    public function test_le_titulaire_designe_puis_revoque_son_medecin_referent(): void
    {
        $this->designer();

        Sanctum::actingAs($this->titulaire);
        $this->getJson("/api/v1/membres/{$this->membre->id}/referent")
            ->assertOk()
            ->assertJsonPath('referent.medecin.nom', 'Koffi')
            ->assertJsonPath('referent.revoquee_at', null);

        $referent = Referent::first();

        $this->deleteJson("/api/v1/membres/{$this->membre->id}/referent/{$referent->id}")->assertOk();

        // La ligne reste (historique exigé par la loi 2013-450), mais elle n'est plus active.
        $this->assertNotNull($referent->fresh()->revoquee_at);
        $this->getJson("/api/v1/membres/{$this->membre->id}/referent")
            ->assertJsonPath('referent', null)
            ->assertJsonCount(1, 'historique');
    }

    public function test_designer_un_nouveau_referent_revoque_le_precedent(): void
    {
        $this->designer();

        $autre = Medecin::create([
            'structure_id' => $this->medecin->structure_id, 'service_id' => $this->medecin->service_id,
            'titre' => 'Dr', 'nom' => 'Traoré', 'prenom' => 'Ismaël',
            'specialite' => 'Cardiologie', 'actif' => true,
        ]);

        Sanctum::actingAs($this->titulaire);
        $this->postJson("/api/v1/membres/{$this->membre->id}/referent", ['medecin_id' => $autre->id])
            ->assertCreated();

        // Un seul référent actif à la fois : le patient ne laisse pas de porte ouverte derrière lui.
        $this->assertSame(1, Referent::actif()->count());
        $this->assertSame($autre->id, Referent::actif()->first()->medecin_id);
    }

    public function test_un_autre_compte_ne_peut_pas_designer_de_referent_sur_mon_membre(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->postJson("/api/v1/membres/{$this->membre->id}/referent", ['medecin_id' => $this->medecin->id])
            ->assertForbidden();

        $this->assertSame(0, Referent::count());
    }

    public function test_le_referent_ouvre_le_dossier_sans_qr_et_l_acces_est_journalise(): void
    {
        $this->designer();

        $this->actingAs($this->compteMedecin)
            ->get('/portail/mes-patients')
            ->assertOk()
            ->assertSee($this->membre->nom);

        $this->actingAs($this->compteMedecin)
            ->post("/portail/mes-patients/{$this->membre->id}/ouvrir")
            ->assertRedirect(route('portail.dossier.show'));

        // Ligne d'audit d'OUVERTURE : voie `referent`, sans token QR (CdC §8.4).
        $acces = AccesDossier::where('type_acces', 'referent')->first();
        $this->assertNotNull($acces);
        $this->assertSame($this->membre->id, $acces->membre_id);
        $this->assertSame($this->compteMedecin->id, $acces->agent_id);
        $this->assertNull($acces->token_qr_id);

        // Le dossier s'ouvre, et le journal de mesures (FN5) y est une section comme une autre.
        $this->actingAs($this->compteMedecin)->get('/portail/dossier/mesures')->assertOk();

        // Fermeture : seconde ligne d'audit (durée + sections consultées), journal en ajout seul.
        $this->actingAs($this->compteMedecin)
            ->post('/portail/dossier/fermer')
            ->assertRedirect(route('portail.patients.index'));

        $this->assertSame(2, AccesDossier::where('type_acces', 'referent')->count());
        $cloture = AccesDossier::where('type_acces', 'referent')->latest('id')->first();
        $this->assertContains('mesures', $cloture->sections_consultees);
    }

    public function test_une_revocation_ferme_immediatement_la_porte(): void
    {
        $this->designer();
        Referent::first()->update(['revoquee_at' => now()]);

        // Le patient disparaît de la liste…
        $this->actingAs($this->compteMedecin)
            ->get('/portail/mes-patients')
            ->assertOk()
            ->assertDontSee($this->membre->nom);

        // …et l'ouverture directe échoue, même en connaissant l'identifiant du membre (404 : on ne
        // confirme pas l'existence du dossier d'un patient qu'on ne suit plus).
        $this->actingAs($this->compteMedecin)
            ->post("/portail/mes-patients/{$this->membre->id}/ouvrir")
            ->assertNotFound();

        $this->assertSame(0, AccesDossier::count());
    }

    public function test_un_praticien_non_designe_ne_voit_rien_du_patient(): void
    {
        // Aucune désignation : le médecin a beau exister et avoir la permission, il n'a aucun patient.
        $this->actingAs($this->compteMedecin)
            ->get('/portail/mes-patients')
            ->assertOk()
            ->assertDontSee($this->membre->nom);

        $this->actingAs($this->compteMedecin)
            ->post("/portail/mes-patients/{$this->membre->id}/ouvrir")
            ->assertNotFound();
    }

    public function test_un_compte_sans_fiche_d_annuaire_ne_peut_pas_utiliser_la_voie_referent(): void
    {
        $this->designer();

        // Agent de garde ordinaire : la permission `dossier.referent` vient de son rôle, mais aucun
        // lien vers une fiche de praticien. La permission seule n'ouvre rien.
        $agent = User::factory()->create([
            'structure_id' => $this->medecin->structure_id,
            'service_id'   => $this->medecin->service_id,
        ]);
        $agent->assignRole('agent_garde');

        $this->actingAs($agent)->get('/portail/mes-patients')->assertForbidden();
        $this->actingAs($agent)
            ->post("/portail/mes-patients/{$this->membre->id}/ouvrir")
            ->assertForbidden();
    }
}
