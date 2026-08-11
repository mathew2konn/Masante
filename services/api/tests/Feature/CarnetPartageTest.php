<?php

namespace Tests\Feature;

use App\Models\Delegation;
use App\Models\MembreFamille;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Carnet familial partagé / incrément A — la délégation ouvre la LECTURE du dossier.
 *
 * Ce fichier couvre la modification la plus sensible du projet : `MembreFamillePolicy::view`,
 * la barrière anti-IDOR de P2, s'ouvre à un tiers. Les tests sont donc écrits dans les deux
 * sens — ce qui doit s'ouvrir, et surtout tout ce qui doit RESTER fermé.
 */
class CarnetPartageTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: User, 1: MembreFamille, 2: User} [titulaire, membre, delegue] */
    private function trio(): array
    {
        $titulaire = User::factory()->create();
        $membre    = MembreFamille::factory()->for($titulaire)->create();
        $delegue   = User::factory()->create();

        return [$titulaire, $membre, $delegue];
    }

    /** Crée une délégation dans l'état voulu, sans passer par l'API. */
    private function deleguer(
        User $titulaire,
        MembreFamille $membre,
        User $delegue,
        string $droits = Delegation::DROIT_LECTURE,
        bool $acceptee = true,
        bool $revoquee = false,
    ): Delegation {
        return Delegation::create([
            'titulaire_user_id' => $titulaire->id,
            'delegue_user_id'   => $delegue->id,
            'membre_id'         => $membre->id,
            'droits'            => $droits,
            'invitee_at'        => now(),
            'acceptee_at'       => $acceptee ? now() : null,
            'revoquee_at'       => $revoquee ? now() : null,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Ce qui doit s'ouvrir
    // ─────────────────────────────────────────────────────────────────────────

    public function test_un_delegue_accepte_lit_le_dossier(): void
    {
        [$titulaire, $membre, $delegue] = $this->trio();
        $this->deleguer($titulaire, $membre, $delegue);

        Sanctum::actingAs($delegue);
        $this->getJson("/api/v1/membres/{$membre->id}")
            ->assertOk()
            ->assertJsonPath('membre.id', $membre->id);
    }

    /**
     * Une seule méthode de Policy gouverne TOUTES les sections : si le partage marche pour le
     * dossier mais pas pour les antécédents, c'est que quelque chose a divergé.
     */
    public function test_un_delegue_lit_les_sections_du_carnet(): void
    {
        [$titulaire, $membre, $delegue] = $this->trio();
        $this->deleguer($titulaire, $membre, $delegue);

        Sanctum::actingAs($delegue);

        foreach (['antecedents', 'vaccinations', 'ordonnances', 'rappels'] as $section) {
            $this->getJson("/api/v1/membres/{$membre->id}/{$section}")
                ->assertOk();
        }
    }

    public function test_un_delegue_lit_le_nis_du_dossier(): void
    {
        [$titulaire, $membre, $delegue] = $this->trio();
        $this->deleguer($titulaire, $membre, $delegue);

        Sanctum::actingAs($delegue);
        $this->getJson("/api/v1/membres/{$membre->id}/nis")->assertOk();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Ce qui doit rester fermé
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * LA RÉGRESSION À NE JAMAIS LAISSER PASSER : les délégations créées avant l'incrément A
     * portent `qr_generation`. Elles ne doivent ouvrir AUCUN dossier — la migration n'a rien
     * élargi rétroactivement, et ce test est ce qui le garantit dans le temps.
     */
    public function test_une_delegation_historique_qr_generation_n_ouvre_pas_le_dossier(): void
    {
        [$titulaire, $membre, $delegue] = $this->trio();
        $this->deleguer($titulaire, $membre, $delegue, droits: Delegation::DROIT_QR);

        Sanctum::actingAs($delegue);
        $this->getJson("/api/v1/membres/{$membre->id}")->assertStatus(403);
        $this->getJson("/api/v1/membres/{$membre->id}/antecedents")->assertStatus(403);

        // …mais elle continue d'ouvrir ce pour quoi elle a été accordée.
        $this->postJson("/api/v1/membres/{$membre->id}/qr")->assertCreated();
    }

    public function test_une_invitation_non_acceptee_n_ouvre_rien(): void
    {
        [$titulaire, $membre, $delegue] = $this->trio();
        $this->deleguer($titulaire, $membre, $delegue, acceptee: false);

        Sanctum::actingAs($delegue);
        $this->getJson("/api/v1/membres/{$membre->id}")->assertStatus(403);
    }

    public function test_une_delegation_revoquee_ferme_immediatement(): void
    {
        [$titulaire, $membre, $delegue] = $this->trio();
        $delegation = $this->deleguer($titulaire, $membre, $delegue);

        Sanctum::actingAs($delegue);
        $this->getJson("/api/v1/membres/{$membre->id}")->assertOk();

        $delegation->update(['revoquee_at' => now()]);

        $this->getJson("/api/v1/membres/{$membre->id}")->assertStatus(403);
    }

    public function test_un_tiers_sans_delegation_ne_lit_rien(): void
    {
        [, $membre] = $this->trio();

        Sanctum::actingAs(User::factory()->create());
        $this->getJson("/api/v1/membres/{$membre->id}")->assertStatus(403);
    }

    /** Le partage ouvre la lecture, jamais l'écriture — celle-ci arrive à l'incrément C. */
    public function test_un_delegue_ne_peut_ni_modifier_ni_supprimer(): void
    {
        [$titulaire, $membre, $delegue] = $this->trio();
        $this->deleguer($titulaire, $membre, $delegue);

        Sanctum::actingAs($delegue);
        $this->putJson("/api/v1/membres/{$membre->id}", ['prenom' => 'Piraté'])->assertStatus(403);
        $this->deleteJson("/api/v1/membres/{$membre->id}")->assertStatus(403);
        $this->postJson("/api/v1/membres/{$membre->id}/antecedents", [
            'libelle' => 'Ajout non autorisé',
        ])->assertStatus(403);
    }

    /** Qui a consulté le dossier regarde le patient, pas ses proches. */
    public function test_un_delegue_ne_voit_pas_l_historique_des_acces(): void
    {
        [$titulaire, $membre, $delegue] = $this->trio();
        $this->deleguer($titulaire, $membre, $delegue);

        Sanctum::actingAs($delegue);
        $this->getJson("/api/v1/membres/{$membre->id}/acces")->assertStatus(403);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Journalisation
    // ─────────────────────────────────────────────────────────────────────────

    public function test_chaque_lecture_deleguee_est_journalisee(): void
    {
        [$titulaire, $membre, $delegue] = $this->trio();
        $this->deleguer($titulaire, $membre, $delegue);

        Sanctum::actingAs($delegue);
        $this->getJson("/api/v1/membres/{$membre->id}")->assertOk();
        $this->getJson("/api/v1/membres/{$membre->id}/antecedents")->assertOk();

        $this->assertDatabaseHas('acces_dossier', [
            'membre_id'  => $membre->id,
            'agent_id'   => $delegue->id,
            'type_acces' => 'delegation',
        ]);

        $this->assertSame(2, \App\Models\AccesDossier::where('membre_id', $membre->id)
            ->where('type_acces', 'delegation')->count());
    }

    public function test_la_lecture_par_le_proprietaire_n_est_pas_journalisee(): void
    {
        [$titulaire, $membre] = $this->trio();

        Sanctum::actingAs($titulaire);
        $this->getJson("/api/v1/membres/{$membre->id}")->assertOk();

        $this->assertDatabaseMissing('acces_dossier', [
            'membre_id'  => $membre->id,
            'type_acces' => 'delegation',
        ]);
    }

    /** Un accès refusé n'est pas un accès : il ne doit pas polluer le journal. */
    public function test_une_lecture_refusee_n_est_pas_journalisee(): void
    {
        [, $membre] = $this->trio();
        $intrus = User::factory()->create();

        Sanctum::actingAs($intrus);
        $this->getJson("/api/v1/membres/{$membre->id}")->assertStatus(403);

        $this->assertDatabaseMissing('acces_dossier', ['agent_id' => $intrus->id]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Liste des carnets partagés
    // ─────────────────────────────────────────────────────────────────────────

    public function test_les_carnets_partages_sont_listes_avec_leur_origine(): void
    {
        [$titulaire, $membre, $delegue] = $this->trio();
        $this->deleguer($titulaire, $membre, $delegue);

        Sanctum::actingAs($delegue);
        $this->getJson('/api/v1/membres/partages')
            ->assertOk()
            ->assertJsonCount(1, 'partages')
            ->assertJsonPath('partages.0.membre.id', $membre->id)
            ->assertJsonPath('partages.0.partage_par.nom', $titulaire->nom);
    }

    public function test_une_delegation_qr_ou_non_acceptee_n_apparait_pas_dans_les_partages(): void
    {
        [$titulaire, $membre, $delegue] = $this->trio();
        $this->deleguer($titulaire, $membre, $delegue, droits: Delegation::DROIT_QR);

        $autreMembre = MembreFamille::factory()->for($titulaire)->create();
        $this->deleguer($titulaire, $autreMembre, $delegue, acceptee: false);

        Sanctum::actingAs($delegue);
        $this->getJson('/api/v1/membres/partages')
            ->assertOk()
            ->assertJsonCount(0, 'partages');
    }

    /** `/membres` reste le contrat de P2 : il ne renvoie que les carnets du compte. */
    public function test_les_carnets_partages_ne_polluent_pas_la_liste_des_membres(): void
    {
        [$titulaire, $membre, $delegue] = $this->trio();
        $this->deleguer($titulaire, $membre, $delegue);

        Sanctum::actingAs($delegue);
        $this->getJson('/api/v1/membres')
            ->assertOk()
            ->assertJsonCount(0, 'membres');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Partage en masse
    // ─────────────────────────────────────────────────────────────────────────

    public function test_le_partage_en_masse_couvre_tous_les_carnets_du_compte(): void
    {
        [$titulaire, $membre, $delegue] = $this->trio();
        MembreFamille::factory()->for($titulaire)->count(2)->create();

        Sanctum::actingAs($titulaire);
        $this->postJson('/api/v1/delegations/en-masse', ['telephone' => $delegue->telephone])
            ->assertCreated()
            ->assertJsonPath('invitations_creees', 3)
            ->assertJsonPath('deja_partages', 0);

        $this->assertSame(3, Delegation::where('delegue_user_id', $delegue->id)->count());
        $this->assertSame(
            Delegation::DROIT_LECTURE,
            Delegation::where('membre_id', $membre->id)->first()->droits
        );
    }

    /** Rejouable : un partage déjà accordé est ignoré, pas rejeté. */
    public function test_le_partage_en_masse_est_rejouable(): void
    {
        [$titulaire, , $delegue] = $this->trio();

        Sanctum::actingAs($titulaire);
        $this->postJson('/api/v1/delegations/en-masse', ['telephone' => $delegue->telephone])
            ->assertCreated();

        $this->postJson('/api/v1/delegations/en-masse', ['telephone' => $delegue->telephone])
            ->assertCreated()
            ->assertJsonPath('invitations_creees', 0)
            ->assertJsonPath('deja_partages', 1);
    }

    /** Un id appartenant à autrui est simplement absent du périmètre — jamais une erreur. */
    public function test_le_partage_en_masse_ignore_le_carnet_d_autrui(): void
    {
        [$titulaire, $membre, $delegue] = $this->trio();
        $carnetEtranger = MembreFamille::factory()->for(User::factory()->create())->create();

        Sanctum::actingAs($titulaire);
        $this->postJson('/api/v1/delegations/en-masse', [
            'telephone'  => $delegue->telephone,
            'membre_ids' => [$membre->id, $carnetEtranger->id],
        ])
            ->assertCreated()
            ->assertJsonPath('invitations_creees', 1);

        $this->assertDatabaseMissing('delegations', ['membre_id' => $carnetEtranger->id]);
    }
}
