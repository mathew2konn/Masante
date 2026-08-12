<?php

namespace Tests\Feature;

use App\Models\Contribution;
use App\Models\Delegation;
use App\Models\MembreFamille;
use App\Models\ResponsableFamille;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Carnet familial partagé / incrément C — contributions au brouillon et responsables.
 *
 * LE SCÉNARIO PROTÉGÉ : les parents sont absents, un enfant est malade, la personne restée à la
 * maison l'emmène à l'hôpital et note ce qui s'est passé. Sa contribution attend une validation ;
 * elle n'est jamais cachée.
 */
class ContributionCarnetTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: User, 1: MembreFamille, 2: User} [responsable, carnet enfant, delegue] */
    private function famille(string $droits = Delegation::DROIT_LECTURE_ECRITURE): array
    {
        $parent  = User::factory()->create();
        $enfant  = MembreFamille::factory()->for($parent)->create();
        $delegue = User::factory()->create();

        Delegation::create([
            'titulaire_user_id' => $parent->id,
            'delegue_user_id'   => $delegue->id,
            'membre_id'         => $enfant->id,
            'droits'            => $droits,
            'invitee_at'        => now(),
            'acceptee_at'       => now(),
        ]);

        return [$parent, $enfant, $delegue];
    }

    /** @return array<string, mixed> */
    private function antecedent(): array
    {
        return ['type' => 'maladie_chronique', 'description' => 'Fièvre à 39°C, vue aux urgences'];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Déposer
    // ─────────────────────────────────────────────────────────────────────────

    public function test_un_delegue_depose_une_contribution_au_brouillon(): void
    {
        [, $enfant, $delegue] = $this->famille();

        Sanctum::actingAs($delegue);
        $this->postJson("/api/v1/membres/{$enfant->id}/contributions", [
            'section' => 'antecedents',
            'donnees' => $this->antecedent(),
        ])
            ->assertCreated()
            ->assertJsonPath('contribution.statut', Contribution::BROUILLON);

        // Rien n'est encore écrit au carnet.
        $this->assertDatabaseCount('antecedents', 0);
    }

    /**
     * LA GARANTIE STRUCTURELLE : une contribution est toujours auto-déclarée. Un délégué ne peut
     * pas faire passer son ajout pour un acte de soignant, quoi qu'il envoie.
     */
    public function test_une_contribution_est_toujours_auto_declaree(): void
    {
        [, $enfant, $delegue] = $this->famille();

        Sanctum::actingAs($delegue);
        $this->postJson("/api/v1/membres/{$enfant->id}/contributions", [
            'section' => 'antecedents',
            'donnees' => $this->antecedent() + ['source' => 'medecin', 'added_by' => 'medecin'],
        ])->assertCreated();

        $donnees = Contribution::first()->donnees;
        $this->assertSame('patient', $donnees['source']);
        $this->assertSame('patient', $donnees['added_by']);
    }

    public function test_une_contribution_invalide_est_refusee(): void
    {
        [, $enfant, $delegue] = $this->famille();

        Sanctum::actingAs($delegue);
        $this->postJson("/api/v1/membres/{$enfant->id}/contributions", [
            'section' => 'antecedents',
            'donnees' => ['type' => 'inexistant'],
        ])->assertStatus(422);
    }

    public function test_une_section_hors_liste_blanche_est_refusee(): void
    {
        [, $enfant, $delegue] = $this->famille();

        Sanctum::actingAs($delegue);
        $this->postJson("/api/v1/membres/{$enfant->id}/contributions", [
            'section' => 'user',   // relation Eloquent existante, mais pas une section de carnet
            'donnees' => ['x' => 1],
        ])->assertStatus(403);
    }

    /** Une délégation `lecture` seule ne donne pas le droit de proposer. */
    public function test_un_delegue_en_lecture_seule_ne_contribue_pas(): void
    {
        [, $enfant, $delegue] = $this->famille(droits: Delegation::DROIT_LECTURE);

        Sanctum::actingAs($delegue);
        $this->postJson("/api/v1/membres/{$enfant->id}/contributions", [
            'section' => 'antecedents',
            'donnees' => $this->antecedent(),
        ])->assertStatus(403);
    }

    public function test_un_tiers_sans_delegation_ne_contribue_pas(): void
    {
        [, $enfant] = $this->famille();

        Sanctum::actingAs(User::factory()->create());
        $this->postJson("/api/v1/membres/{$enfant->id}/contributions", [
            'section' => 'antecedents',
            'donnees' => $this->antecedent(),
        ])->assertStatus(403);
    }

    /** Le propriétaire écrit directement : lui faire valider ses propres brouillons serait absurde. */
    public function test_le_proprietaire_ne_passe_pas_par_le_brouillon(): void
    {
        [$parent, $enfant] = $this->famille();

        Sanctum::actingAs($parent);
        $this->postJson("/api/v1/membres/{$enfant->id}/contributions", [
            'section' => 'antecedents',
            'donnees' => $this->antecedent(),
        ])->assertStatus(403);

        // …et son écriture directe fonctionne toujours.
        $this->postJson("/api/v1/membres/{$enfant->id}/antecedents", $this->antecedent())
            ->assertCreated();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Valider et rejeter
    // ─────────────────────────────────────────────────────────────────────────

    public function test_le_proprietaire_valide_et_l_entree_est_ecrite(): void
    {
        [$parent, $enfant, $delegue] = $this->famille();
        $c = $this->deposer($delegue, $enfant);

        Sanctum::actingAs($parent);
        $this->postJson("/api/v1/contributions/{$c->id}/valider")
            ->assertOk()
            ->assertJsonPath('contribution.statut', Contribution::VALIDEE);

        $this->assertDatabaseCount('antecedents', 1);

        // `description` est CHIFFRÉ au repos (cast `encrypted`) : on relit par le modèle, jamais
        // par une assertion SQL brute — sinon on comparerait du texte clair à du chiffré.
        $entree = $enfant->antecedents()->first();
        $this->assertSame('Fièvre à 39°C, vue aux urgences', $entree->description);
        $this->assertSame('patient', $entree->source);
        $this->assertSame($entree->id, $c->fresh()->entree_id);
    }

    public function test_un_responsable_designe_peut_valider(): void
    {
        [$parent, $enfant, $delegue] = $this->famille();
        $c      = $this->deposer($delegue, $enfant);
        $second = User::factory()->create();

        ResponsableFamille::create([
            'titulaire_user_id'   => $parent->id,
            'responsable_user_id' => $second->id,
            'designe_le'          => now(),
        ]);

        Sanctum::actingAs($second);
        $this->postJson("/api/v1/contributions/{$c->id}/valider")->assertOk();
        $this->assertDatabaseCount('antecedents', 1);
    }

    public function test_ni_l_auteur_ni_un_tiers_ne_peuvent_valider(): void
    {
        [, $enfant, $delegue] = $this->famille();
        $c = $this->deposer($delegue, $enfant);

        // L'auteur ne se valide pas lui-même — c'est tout l'objet du circuit.
        Sanctum::actingAs($delegue);
        $this->postJson("/api/v1/contributions/{$c->id}/valider")->assertStatus(409);

        Sanctum::actingAs(User::factory()->create());
        $this->postJson("/api/v1/contributions/{$c->id}/valider")->assertStatus(409);

        $this->assertDatabaseCount('antecedents', 0);
    }

    public function test_une_designation_revoquee_ne_permet_plus_de_valider(): void
    {
        [$parent, $enfant, $delegue] = $this->famille();
        $c      = $this->deposer($delegue, $enfant);
        $second = User::factory()->create();

        $ligne = ResponsableFamille::create([
            'titulaire_user_id'   => $parent->id,
            'responsable_user_id' => $second->id,
            'designe_le'          => now(),
        ]);
        $ligne->update(['revoque_le' => now()]);

        Sanctum::actingAs($second);
        $this->postJson("/api/v1/contributions/{$c->id}/valider")->assertStatus(409);
    }

    public function test_le_rejet_n_ecrit_rien_et_reste_explicable(): void
    {
        [$parent, $enfant, $delegue] = $this->famille();
        $c = $this->deposer($delegue, $enfant);

        Sanctum::actingAs($parent);
        $this->postJson("/api/v1/contributions/{$c->id}/rejeter", ['motif' => 'Vérification faite : pas de consultation'])
            ->assertOk()
            ->assertJsonPath('contribution.statut', Contribution::REJETEE);

        $this->assertDatabaseCount('antecedents', 0);
        $this->assertSame('Vérification faite : pas de consultation', $c->fresh()->motif_rejet);
    }

    public function test_une_contribution_deja_traitee_ne_se_rejoue_pas(): void
    {
        [$parent, $enfant, $delegue] = $this->famille();
        $c = $this->deposer($delegue, $enfant);

        Sanctum::actingAs($parent);
        $this->postJson("/api/v1/contributions/{$c->id}/valider")->assertOk();
        $this->postJson("/api/v1/contributions/{$c->id}/valider")->assertStatus(409);
        $this->postJson("/api/v1/contributions/{$c->id}/rejeter")->assertStatus(409);

        $this->assertDatabaseCount('antecedents', 1);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Visibilité
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * RÈGLE DE SÉCURITÉ CLINIQUE : un brouillon n'est jamais caché à qui a accès au dossier. Un
     * fait médical non validé reste un fait médical.
     */
    public function test_le_brouillon_est_visible_de_qui_lit_le_carnet(): void
    {
        [$parent, $enfant, $delegue] = $this->famille();
        $this->deposer($delegue, $enfant);

        foreach ([$parent, $delegue] as $qui) {
            Sanctum::actingAs($qui);
            $this->getJson("/api/v1/membres/{$enfant->id}/contributions")
                ->assertOk()
                ->assertJsonCount(1, 'contributions')
                ->assertJsonPath('contributions.0.statut', Contribution::BROUILLON);
        }
    }

    public function test_un_tiers_ne_voit_pas_les_contributions(): void
    {
        [, $enfant, $delegue] = $this->famille();
        $this->deposer($delegue, $enfant);

        Sanctum::actingAs(User::factory()->create());
        $this->getJson("/api/v1/membres/{$enfant->id}/contributions")->assertStatus(403);
    }

    public function test_la_file_du_responsable_ne_montre_que_ses_carnets(): void
    {
        [$parent, $enfant, $delegue] = $this->famille();
        $this->deposer($delegue, $enfant);

        // Une autre famille, avec sa propre contribution en attente.
        [, $autreEnfant, $autreDelegue] = $this->famille();
        $this->deposer($autreDelegue, $autreEnfant);

        Sanctum::actingAs($parent);
        $this->getJson('/api/v1/contributions')
            ->assertOk()
            ->assertJsonCount(1, 'contributions')
            ->assertJsonPath('contributions.0.membre.id', $enfant->id);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Responsables
    // ─────────────────────────────────────────────────────────────────────────

    public function test_designer_puis_retirer_un_second_responsable(): void
    {
        $parent = User::factory()->create();
        $second = User::factory()->create();

        Sanctum::actingAs($parent);
        $this->postJson('/api/v1/responsables', ['telephone' => $second->telephone])
            ->assertCreated();

        $this->getJson('/api/v1/responsables')->assertJsonCount(1, 'designes');

        $id = ResponsableFamille::first()->id;
        $this->deleteJson("/api/v1/responsables/{$id}")->assertOk();

        $this->getJson('/api/v1/responsables')->assertJsonCount(0, 'designes');
        $this->assertSame([$parent->id], ResponsableFamille::decideursPour($parent->id));
    }

    public function test_on_ne_se_designe_pas_soi_meme(): void
    {
        $parent = User::factory()->create();

        Sanctum::actingAs($parent);
        $this->postJson('/api/v1/responsables', ['telephone' => $parent->telephone])
            ->assertStatus(422);
    }

    /** Le désigné peut se retirer lui-même : renoncer doit être aussi simple qu'accepter. */
    public function test_le_designe_peut_se_retirer(): void
    {
        $parent = User::factory()->create();
        $second = User::factory()->create();

        $ligne = ResponsableFamille::create([
            'titulaire_user_id'   => $parent->id,
            'responsable_user_id' => $second->id,
            'designe_le'          => now(),
        ]);

        Sanctum::actingAs($second);
        $this->deleteJson("/api/v1/responsables/{$ligne->id}")->assertOk();
        $this->assertNotNull($ligne->fresh()->revoque_le);
    }

    private function deposer(User $delegue, MembreFamille $enfant): Contribution
    {
        Sanctum::actingAs($delegue);
        $this->postJson("/api/v1/membres/{$enfant->id}/contributions", [
            'section' => 'antecedents',
            'donnees' => $this->antecedent(),
        ])->assertCreated();

        return Contribution::latest('id')->first();
    }
}
