<?php

namespace Tests\Feature;

use App\Models\Commande;
use App\Models\Medicament;
use App\Models\MembreFamille;
use App\Models\StructureSanitaire;
use App\Models\User;
use App\Services\Medicament\ServiceCommande;
use App\Services\Medicament\ServiceTraitementCommande;
use App\Services\PrixMedicamentService;
use Database\Seeders\PortailRolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * B3-d — l'écran portail du pharmacien (CDC_11 §9.5).
 *
 * B1-b avait trouvé qu'un écran non mis à jour peut proposer une action que le serveur refuse
 * ensuite (409 muet) — ici la vérification inverse : le rendu propose la BONNE action pour
 * CHAQUE état du cycle, jamais un bouton qui mènerait à un refus.
 */
class CommandePortailTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PortailRolesSeeder::class);
    }

    private function officine(): StructureSanitaire
    {
        return StructureSanitaire::create([
            'nom' => 'Pharmacie du Plateau', 'type' => 'pharmacie', 'adresse' => 'Abidjan',
            'commune' => 'Plateau', 'latitude' => 5.32, 'longitude' => -4.02, 'actif' => true,
        ]);
    }

    private function pharmacien(StructureSanitaire $officine, bool $habilite = true): User
    {
        $user = User::factory()->create(['structure_id' => $officine->id]);
        if ($habilite) {
            $user->givePermissionTo(ServiceTraitementCommande::PERMISSION);
        }

        return $user->fresh();
    }

    private function commande(StructureSanitaire $officine): Commande
    {
        $patient = User::factory()->create();
        $membre = MembreFamille::factory()->for($patient)->create();
        $medicament = Medicament::create([
            'nom_generique' => 'Paracétamol', 'nom_commercial' => 'Doliprane', 'dosage' => '500 mg',
            'categorie' => 'antalgique', 'ordonnance_requise' => false, 'disponible_generique' => true,
        ]);
        app(PrixMedicamentService::class)->releverPrix($medicament, $officine, 500, 'pharmacie_portail');

        return app(ServiceCommande::class)->passer(
            $patient, $membre, $officine,
            [['medicament_id' => $medicament->id, 'quantite' => 2]],
            'retrait', null, null, null,
        );
    }

    public function test_non_habilite_recoit_403_sur_la_liste(): void
    {
        $officine = $this->officine();
        $pharmacien = $this->pharmacien($officine, habilite: false);

        $this->actingAs($pharmacien)->get(route('portail.commandes.index'))->assertForbidden();
    }

    public function test_liste_affiche_la_commande_de_mon_officine(): void
    {
        $officine = $this->officine();
        $pharmacien = $this->pharmacien($officine);
        $commande = $this->commande($officine);

        $this->actingAs($pharmacien)->get(route('portail.commandes.index'))
            ->assertOk()
            ->assertSee($commande->reference);
    }

    public function test_commande_d_une_autre_officine_est_404(): void
    {
        $officine = $this->officine();
        $autre = $this->officine();
        $pharmacien = $this->pharmacien($autre);
        $commande = $this->commande($officine);

        $this->actingAs($pharmacien)->get(route('portail.commandes.show', $commande))->assertNotFound();
    }

    public function test_en_attente_propose_accepter_et_refuser(): void
    {
        $officine = $this->officine();
        $pharmacien = $this->pharmacien($officine);
        $commande = $this->commande($officine);

        $reponse = $this->actingAs($pharmacien)->get(route('portail.commandes.show', $commande));

        $reponse->assertOk();
        $reponse->assertSee(route('portail.commandes.accepter', $commande), false);
        $reponse->assertSee(route('portail.commandes.refuser', $commande), false);
        $reponse->assertDontSee(route('portail.commandes.preparer', $commande), false);
    }

    public function test_acceptee_propose_marquer_prete(): void
    {
        $officine = $this->officine();
        $pharmacien = $this->pharmacien($officine);
        $commande = $this->commande($officine);
        app(ServiceTraitementCommande::class)->accepter($pharmacien, $commande);

        $reponse = $this->actingAs($pharmacien)->get(route('portail.commandes.show', $commande));

        $reponse->assertOk();
        $reponse->assertSee(route('portail.commandes.preparer', $commande), false);
        $reponse->assertDontSee(route('portail.commandes.accepter', $commande), false);
    }

    public function test_prete_propose_remettre(): void
    {
        $officine = $this->officine();
        $pharmacien = $this->pharmacien($officine);
        $commande = $this->commande($officine);
        app(ServiceTraitementCommande::class)->accepter($pharmacien, $commande);
        app(ServiceTraitementCommande::class)->preparer($pharmacien, $commande->fresh());

        $reponse = $this->actingAs($pharmacien)->get(route('portail.commandes.show', $commande));

        $reponse->assertOk();
        $reponse->assertSee(route('portail.commandes.remettre', $commande), false);
    }

    public function test_refus_reel_via_le_formulaire_persiste_le_motif(): void
    {
        $officine = $this->officine();
        $pharmacien = $this->pharmacien($officine);
        $commande = $this->commande($officine);

        $this->actingAs($pharmacien)
            ->from(route('portail.commandes.show', $commande))
            ->post(route('portail.commandes.refuser', $commande), ['motif' => 'Rupture'])
            ->assertRedirect(route('portail.commandes.show', $commande));

        $this->assertSame('refusee', $commande->fresh()->statut->value);
        $this->assertSame('Rupture', $commande->fresh()->motif_refus);
    }

    public function test_acceptation_reelle_via_le_formulaire(): void
    {
        $officine = $this->officine();
        $pharmacien = $this->pharmacien($officine);
        $commande = $this->commande($officine);

        $this->actingAs($pharmacien)
            ->post(route('portail.commandes.accepter', $commande))
            ->assertRedirect(route('portail.commandes.show', $commande));

        $this->assertSame('acceptee', $commande->fresh()->statut->value);
    }
}
