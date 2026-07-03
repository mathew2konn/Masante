<?php

namespace Tests\Feature;

use App\Models\MembreFamille;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * F2.11 — Contacts d'urgence, révision « exactement 2 contacts » (modification.txt, 2026-07-03).
 * On couvre les invariants produit : rôle par ordre, plafond 2, distinction des numéros, enum du
 * lien de parenté, promotion du secondaire à la suppression du principal, et isolation anti-IDOR.
 */
class ContactUrgenceTest extends TestCase
{
    use RefreshDatabase;

    private function membreConnecte(): MembreFamille
    {
        $user = User::factory()->create();
        $membre = MembreFamille::factory()->for($user)->create();
        Sanctum::actingAs($user);

        return $membre;
    }

    private function contact(array $extra = []): array
    {
        return array_merge([
            'nom'          => 'Koffi Awa',
            'lien_parente' => 'maman',
            'telephone'    => '+2250700000001',
        ], $extra);
    }

    public function test_le_premier_contact_est_principal_le_second_secondaire(): void
    {
        $membre = $this->membreConnecte();

        $this->postJson("/api/v1/membres/{$membre->id}/contacts-urgence", $this->contact())
            ->assertCreated()->assertJsonPath('item.est_principal', true);

        $this->postJson("/api/v1/membres/{$membre->id}/contacts-urgence", $this->contact([
            'lien_parente' => 'papa', 'telephone' => '+2250700000002',
        ]))->assertCreated()->assertJsonPath('item.est_principal', false);
    }

    public function test_un_troisieme_contact_est_refuse(): void
    {
        $membre = $this->membreConnecte();

        $this->postJson("/api/v1/membres/{$membre->id}/contacts-urgence", $this->contact())->assertCreated();
        $this->postJson("/api/v1/membres/{$membre->id}/contacts-urgence", $this->contact([
            'telephone' => '+2250700000002',
        ]))->assertCreated();

        $this->postJson("/api/v1/membres/{$membre->id}/contacts-urgence", $this->contact([
            'telephone' => '+2250700000003',
        ]))->assertStatus(422)->assertJsonValidationErrors('contact');

        $this->assertDatabaseCount('contacts_urgence', 2);
    }

    public function test_les_deux_contacts_doivent_avoir_des_numeros_distincts(): void
    {
        $membre = $this->membreConnecte();

        $this->postJson("/api/v1/membres/{$membre->id}/contacts-urgence", $this->contact())->assertCreated();

        $this->postJson("/api/v1/membres/{$membre->id}/contacts-urgence", $this->contact([
            'nom' => 'Autre personne',
        ]))->assertStatus(422)->assertJsonValidationErrors('telephone');
    }

    public function test_le_lien_de_parente_hors_liste_est_refuse(): void
    {
        $membre = $this->membreConnecte();

        $this->postJson("/api/v1/membres/{$membre->id}/contacts-urgence", $this->contact([
            'lien_parente' => 'voisin',
        ]))->assertStatus(422)->assertJsonValidationErrors('lien_parente');
    }

    public function test_supprimer_le_principal_promeut_le_secondaire(): void
    {
        $membre = $this->membreConnecte();

        $principalId = $this->postJson("/api/v1/membres/{$membre->id}/contacts-urgence", $this->contact())
            ->json('item.id');
        $secondaireId = $this->postJson("/api/v1/membres/{$membre->id}/contacts-urgence", $this->contact([
            'telephone' => '+2250700000002',
        ]))->json('item.id');

        $this->deleteJson("/api/v1/membres/{$membre->id}/contacts-urgence/{$principalId}")->assertOk();

        $this->assertDatabaseHas('contacts_urgence', ['id' => $secondaireId, 'est_principal' => true]);
    }

    public function test_le_client_ne_peut_pas_forcer_le_role_principal(): void
    {
        $membre = $this->membreConnecte();

        // Un 2e contact qui tente `est_principal=true` reste secondaire (rôle = ordre, non piloté client).
        $this->postJson("/api/v1/membres/{$membre->id}/contacts-urgence", $this->contact())->assertCreated();
        $this->postJson("/api/v1/membres/{$membre->id}/contacts-urgence", $this->contact([
            'telephone' => '+2250700000002', 'est_principal' => true,
        ]))->assertCreated()->assertJsonPath('item.est_principal', false);
    }

    public function test_isolation_idor(): void
    {
        $membre = MembreFamille::factory()->for(User::factory())->create();
        Sanctum::actingAs(User::factory()->create());

        $this->getJson("/api/v1/membres/{$membre->id}/contacts-urgence")->assertForbidden();
        $this->postJson("/api/v1/membres/{$membre->id}/contacts-urgence", $this->contact())->assertForbidden();
        $this->assertDatabaseCount('contacts_urgence', 0);
    }
}
