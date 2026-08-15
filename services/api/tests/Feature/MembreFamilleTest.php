<?php

namespace Tests\Feature;

use App\Models\MembreFamille;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Étape 2A.2 — Membres de la famille. On vérifie surtout le point sensible : l'isolation
 * entre comptes (anti-IDOR, §4.3 Sécurité), ainsi que la règle métier « max 15 » (F2.2).
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
            // P6.8d — `cmu_numero` et `cmu_statut` sont ENVOYÉS EXPRÈS alors que le serveur ne les
            // accepte plus : c'est ce qui fait de ce jeu de données le vecteur de la garde. Voir
            // `test_les_champs_cmu_envoyes_sont_ignores`.
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
    }

    /**
     * P6.8d — VECTEUR HÉRITÉ RÉÉCRIT POUR DIRE LA GARANTIE NEUVE, pas corrigé pour passer.
     *
     * Il affirmait auparavant que `cmu_numero` était chiffré au repos par cet endpoint. Ce n'est
     * plus vrai, et ce n'est pas une régression : **une couverture santé est un contrat, pas un
     * attribut de la personne**, et elle se déclare sur `POST /membres/{id}/couvertures` — où le
     * numéro est chiffré exactement de la même façon (vecteur dédié dans `ReferentielAssurancesTest`).
     *
     * Ce que ce vecteur tient désormais : les trois champs envoyés sont IGNORÉS EN SILENCE, et rien
     * n'est écrit dans les colonnes héritées. Un client mobile plus ancien continue de créer des
     * membres ; il ne fabrique simplement plus une couverture que plus rien ne lit.
     */
    public function test_les_champs_cmu_envoyes_sont_ignores(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/membres', $this->donneesMembre([
            'cmu_validite' => '2030-01-01',
        ]))->assertCreated()
            // Aucune couverture n'existe → la valeur DÉRIVÉE est « non inscrit », quoi qu'ait
            // envoyé le client.
            ->assertJsonPath('membre.cmu_statut', 'non_inscrit')
            ->assertJsonPath('membre.cmu_numero_masque', null);

        $membre = MembreFamille::first();

        // Et rien n'a été écrit dans les colonnes héritées : la garantie ne vient pas seulement des
        // règles de validation, elle vient aussi de `$fillable` — chaque couche a son vecteur.
        $this->assertNull($membre->getRawOriginal('cmu_numero'));
        $this->assertNull($membre->getRawOriginal('cmu_validite'));
        $this->assertSame(0, $membre->couvertures()->count());
    }

    public function test_le_compte_ne_peut_pas_depasser_quinze_membres(): void
    {
        $user = User::factory()->create();
        MembreFamille::factory()->count(15)->for($user)->create();
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
