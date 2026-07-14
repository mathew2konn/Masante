<?php

namespace Tests\Feature;

use App\Models\BesoinSang;
use App\Models\DonneurSang;
use App\Models\MembreFamille;
use App\Models\ServiceEtablissement;
use App\Models\StructureSanitaire;
use App\Models\User;
use App\Services\DonSangService;
use Database\Seeders\PortailRolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Module 5 / 5.7 — Don de sang (CdC FN6), étape A.
 *
 * Ce qui doit tenir : la compatibilité ABO/Rhésus (une erreur ici tue) ; le ciblage des alertes,
 * calculé serveur — un O− est alerté pour une poche A+, un A+ ne l'est pas pour une poche O− ; le
 * délai de carence, qui met un donneur au repos sans le désinscrire ; le consentement, retirable
 * d'un geste ; et la MINIMISATION : l'établissement compte les donneurs mobilisables, il ne les
 * nomme jamais.
 */
class DonSangTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private StructureSanitaire $structure;

    private User $gestionnaire;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PortailRolesSeeder::class);

        $this->user = User::factory()->create(['commune' => 'Cocody']);

        $this->structure = StructureSanitaire::create([
            'nom' => 'CHU de Cocody', 'type' => 'chu', 'adresse' => 'Abidjan', 'commune' => 'Cocody',
            'latitude' => 5.35, 'longitude' => -3.98, 'actif' => true,
        ]);

        $this->gestionnaire = User::factory()->create(['structure_id' => $this->structure->id]);
        $this->gestionnaire->assignRole('gestionnaire_etablissement');
    }

    /** Membre majeur du compte, avec son groupe sanguin. */
    private function membre(string $groupe, int $age = 30): MembreFamille
    {
        return MembreFamille::factory()->for($this->user)->create([
            'groupe_sanguin' => $groupe,
            'date_naissance' => now()->subYears($age)->toDateString(),
        ]);
    }

    private function publier(string $groupe, string $niveau = 'urgent'): BesoinSang
    {
        $besoin = new BesoinSang([
            'groupe_sanguin' => $groupe,
            'niveau'         => $niveau,
            'date_debut'     => now()->toDateString(),
        ]);
        $besoin->structure_id = $this->structure->id;
        $besoin->save();

        return $besoin;
    }

    public function test_la_compatibilite_abo_rhesus_est_juste(): void
    {
        $dons = app(DonSangService::class);

        // O− : donneur universel — il figure dans la liste des donneurs de CHAQUE groupe.
        foreach (array_keys(DonSangService::COMPATIBILITE) as $receveur) {
            $this->assertContains('O-', $dons->donneursCompatiblesAvec($receveur), "O- doit pouvoir donner à {$receveur}");
        }

        // AB+ : receveur universel — les 8 groupes peuvent l'alimenter.
        $this->assertCount(8, $dons->donneursCompatiblesAvec('AB+'));

        // Et l'inverse est faux : une poche O− ne s'obtient que d'un O−.
        $this->assertSame(['O-'], $dons->donneursCompatiblesAvec('O-'));

        // Le Rhésus compte : un A+ ne peut pas alimenter une poche A−.
        $this->assertNotContains('A+', $dons->donneursCompatiblesAvec('A-'));
    }

    public function test_l_inscription_exige_un_groupe_sanguin_et_l_age_legal(): void
    {
        Sanctum::actingAs($this->user);

        $sansGroupe = MembreFamille::factory()->for($this->user)->create([
            'groupe_sanguin' => null,
            'date_naissance' => now()->subYears(30)->toDateString(),
        ]);
        $this->postJson("/api/v1/membres/{$sansGroupe->id}/donneur")
            ->assertStatus(422)->assertJsonValidationErrors('membre');

        // Un enfant n'est pas un donneur : l'application oriente, elle ne recrute pas.
        $enfant = $this->membre('O-', age: 12);
        $this->postJson("/api/v1/membres/{$enfant->id}/donneur")
            ->assertStatus(422)->assertJsonValidationErrors('membre');

        $adulte = $this->membre('O-');
        $this->postJson("/api/v1/membres/{$adulte->id}/donneur")->assertCreated();

        $this->assertSame(1, DonneurSang::count());
    }

    public function test_un_donneur_o_negatif_est_alerte_pour_une_poche_a_positif(): void
    {
        $membre = $this->membre('O-');
        Sanctum::actingAs($this->user);
        $this->postJson("/api/v1/membres/{$membre->id}/donneur")->assertCreated();

        $this->publier('A+');   // O− peut donner à A+ : il est concerné

        $this->getJson('/api/v1/don-sang')
            ->assertOk()
            ->assertJsonCount(1, 'alertes')
            ->assertJsonPath('alertes.0.besoin.groupe_sanguin', 'A+')
            ->assertJsonPath('alertes.0.mes_groupes_utiles', ['O-']);
    }

    public function test_un_donneur_incompatible_n_est_pas_alerte(): void
    {
        $membre = $this->membre('A+');
        Sanctum::actingAs($this->user);
        $this->postJson("/api/v1/membres/{$membre->id}/donneur")->assertCreated();

        $this->publier('O-');   // seule une poche O− convient : un A+ n'y peut rien

        $this->getJson('/api/v1/don-sang')->assertOk()->assertJsonCount(0, 'alertes');
    }

    public function test_seule_une_urgence_alerte_les_donneurs(): void
    {
        $membre = $this->membre('O-');
        Sanctum::actingAs($this->user);
        $this->postJson("/api/v1/membres/{$membre->id}/donneur")->assertCreated();

        $this->publier('O-', niveau: 'courant');

        // Le besoin courant est PUBLIC (il figure dans les groupes demandés)…
        $this->getJson('/api/v1/don-sang/besoins')->assertOk()->assertJsonCount(1, 'besoins');
        // …mais il ne réveille personne : si tout alertait, plus rien n'alerterait.
        $this->getJson('/api/v1/don-sang')->assertOk()->assertJsonCount(0, 'alertes');
    }

    public function test_un_don_recent_met_le_donneur_au_repos_sans_le_desinscrire(): void
    {
        $membre = $this->membre('O-');
        Sanctum::actingAs($this->user);
        $this->postJson("/api/v1/membres/{$membre->id}/donneur")->assertCreated();

        $this->postJson("/api/v1/membres/{$membre->id}/donneur/don", [
            'date' => now()->subDays(10)->toDateString(),
        ])->assertOk();

        $this->publier('O-');

        $this->getJson('/api/v1/don-sang')
            ->assertOk()
            ->assertJsonCount(0, 'alertes')                       // en carence : on ne le sollicite pas
            ->assertJsonPath('donneurs.0.peut_donner', false)
            ->assertJsonPath('donneurs.0.jours_avant_don', 80);   // 90 jours de délai − 10 écoulés

        // Il reste donneur : le retrait est un acte volontaire, pas une conséquence du don.
        $this->assertTrue(DonneurSang::first()->disponible);
    }

    public function test_le_retrait_du_consentement_conserve_la_date_du_dernier_don(): void
    {
        $membre = $this->membre('O-');
        Sanctum::actingAs($this->user);
        $this->postJson("/api/v1/membres/{$membre->id}/donneur")->assertCreated();
        $this->postJson("/api/v1/membres/{$membre->id}/donneur/don", [
            'date' => now()->subDays(10)->toDateString(),
        ])->assertOk();

        $this->deleteJson("/api/v1/membres/{$membre->id}/donneur")->assertOk();

        $donneur = DonneurSang::first();
        $this->assertFalse($donneur->disponible);
        // La carence survit à une réinscription : sinon on la remettrait à zéro à volonté.
        $this->assertNotNull($donneur->dernier_don_at);

        $this->publier('O-');
        $this->getJson('/api/v1/don-sang')->assertOk()->assertJsonCount(0, 'alertes');
    }

    public function test_un_autre_compte_ne_peut_pas_inscrire_mon_membre_comme_donneur(): void
    {
        $membre = $this->membre('O-');

        Sanctum::actingAs(User::factory()->create());
        $this->postJson("/api/v1/membres/{$membre->id}/donneur")->assertForbidden();

        $this->assertSame(0, DonneurSang::count());
    }

    public function test_l_etablissement_compte_les_donneurs_mais_ne_les_nomme_jamais(): void
    {
        $membre = $this->membre('O-');
        Sanctum::actingAs($this->user);
        $this->postJson("/api/v1/membres/{$membre->id}/donneur")->assertCreated();

        $this->actingAs($this->gestionnaire)
            ->post('/portail/don-sang', [
                'groupe_sanguin' => 'AB+', 'niveau' => 'urgent',
                'date_debut' => now()->toDateString(),
            ])
            ->assertRedirect(route('portail.don-sang.index'));

        $besoin = BesoinSang::first();
        $this->assertSame($this->structure->id, $besoin->structure_id);
        $this->assertSame($this->gestionnaire->id, $besoin->publie_par_user_id);
        $this->assertSame(1, app(DonSangService::class)->compterDonneursCompatibles($besoin));

        // L'écran affiche le NOMBRE de donneurs mobilisables — jamais l'identité de quiconque.
        $this->actingAs($this->gestionnaire)
            ->get('/portail/don-sang')
            ->assertOk()
            ->assertDontSee($membre->nom)
            ->assertDontSee($this->user->telephone);
    }

    public function test_un_gestionnaire_ne_touche_pas_aux_besoins_d_un_autre_etablissement(): void
    {
        $autre = StructureSanitaire::create([
            'nom' => 'CHU de Treichville', 'type' => 'chu', 'adresse' => 'Abidjan',
            'commune' => 'Treichville', 'latitude' => 5.29, 'longitude' => -4.00, 'actif' => true,
        ]);
        $besoin = new BesoinSang(['groupe_sanguin' => 'O-', 'niveau' => 'urgent', 'date_debut' => now()->toDateString()]);
        $besoin->structure_id = $autre->id;
        $besoin->save();

        $this->actingAs($this->gestionnaire)->get("/portail/don-sang/{$besoin->id}/editer")->assertNotFound();
        $this->actingAs($this->gestionnaire)->patch("/portail/don-sang/{$besoin->id}/actif")->assertNotFound();

        $this->assertTrue($besoin->fresh()->actif);
    }

    public function test_les_centres_de_collecte_sortent_de_l_annuaire_geolocalise(): void
    {
        // FN6 « localiser les centres de collecte » = une structure portant un service `don_sang`.
        // Aucun second annuaire : c'est la recherche du Module 3 qui répond.
        ServiceEtablissement::create([
            'structure_id' => $this->structure->id, 'nom_service' => 'Banque de sang',
            'specialite' => 'don_sang', 'actif' => true,
        ]);

        $this->getJson('/api/v1/structures?specialite=don_sang')
            ->assertOk()
            ->assertJsonCount(1, 'structures')
            ->assertJsonPath('structures.0.nom', 'CHU de Cocody');
    }
}
