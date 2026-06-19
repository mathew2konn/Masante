<?php

namespace Tests\Feature;

use App\Models\MembreFamille;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Étape 2A.2 — Membres de la famille. On vérifie surtout le point sensible : l'isolation
 * entre comptes (anti-IDOR, §4.3 Sécurité), ainsi que la règle métier « max 5 » (F2.2).
 */
class MembreFamilleTest extends TestCase
{
    use RefreshDatabase;

    private function donneesMembre(array $extra = []): array
    {
        return array_merge([
            'nom'            => 'Koffi',
            'prenom'         => 'Awa',
            'date_naissance' => '2000-01-15',
            'sexe'           => 'F',
            'groupe_sanguin' => 'O+',
            'cmu_numero'     => 'CMU12345678',
            'cmu_statut'     => 'actif',
        ], $extra);
    }

    public function test_un_utilisateur_cree_un_membre_avec_matricule_genere_et_masque(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/membres', $this->donneesMembre());

        $response->assertCreated()
            ->assertJsonPath('membre.nom', 'Koffi')
            // Le matricule interne ne doit jamais être exposé (§1 Sécurité).
            ->assertJsonMissingPath('membre.matricule_ivs')
            ->assertJsonMissingPath('membre.user_id');

        $membre = MembreFamille::first();
        $this->assertSame($user->id, $membre->user_id);
        $this->assertMatchesRegularExpression('/^IVS-\d{4}-[A-Z]{2}-\d{5}$/', $membre->matricule_ivs);
        // Le numéro CMU est chiffré au repos : la valeur brute en base n'est pas le clair.
        $this->assertNotSame('CMU12345678', $membre->getRawOriginal('cmu_numero'));
        $this->assertSame('CMU12345678', $membre->cmu_numero);
    }

    public function test_le_compte_ne_peut_pas_depasser_cinq_membres(): void
    {
        $user = User::factory()->create();
        MembreFamille::factory()->count(5)->for($user)->create();
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/membres', $this->donneesMembre())
            ->assertStatus(422)
            ->assertJsonValidationErrors('membre');
    }

    public function test_la_liste_ne_renvoie_que_les_membres_du_compte(): void
    {
        $moi = User::factory()->create();
        $autre = User::factory()->create();
        MembreFamille::factory()->count(2)->for($moi)->create();
        MembreFamille::factory()->count(3)->for($autre)->create();
        Sanctum::actingAs($moi);

        $this->getJson('/api/v1/membres')
            ->assertOk()
            ->assertJsonCount(2, 'membres');
    }

    public function test_un_utilisateur_ne_peut_pas_acceder_au_membre_d_un_autre(): void
    {
        $proprietaire = User::factory()->create();
        $intrus = User::factory()->create();
        $membre = MembreFamille::factory()->for($proprietaire)->create();
        Sanctum::actingAs($intrus);

        $this->getJson("/api/v1/membres/{$membre->id}")->assertForbidden();
        $this->putJson("/api/v1/membres/{$membre->id}", ['nom' => 'Pirate'])->assertForbidden();
        $this->deleteJson("/api/v1/membres/{$membre->id}")->assertForbidden();

        $this->assertDatabaseHas('membres_famille', ['id' => $membre->id, 'nom' => $membre->nom]);
    }

    public function test_les_routes_membres_exigent_une_authentification(): void
    {
        $this->getJson('/api/v1/membres')->assertUnauthorized();
    }
}
