<?php

namespace Tests\Feature;

use App\Models\CarnetTransfert;
use App\Models\Delegation;
use App\Models\MembreFamille;
use App\Models\User;
use App\Services\Nis\AttributeurNis;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Carnet familial partagé / incrément B — revendication d'un carnet.
 *
 * Ce que ces tests protègent : la revendication ne repose sur AUCUN score. Elle exige deux actes
 * humains — l'assertion du responsable au partage, et la reconnaissance par la personne. Chaque
 * test vérifie qu'aucun des deux ne peut être contourné.
 */
class RevendicationCarnetTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: User, 1: MembreFamille, 2: User} [responsable, carnet, proche] */
    private function scene(bool $assertion = true, bool $acceptee = true): array
    {
        $responsable = User::factory()->create();
        $carnet      = MembreFamille::factory()->for($responsable)->create(['est_titulaire' => false]);
        $proche      = User::factory()->create();

        Delegation::create([
            'titulaire_user_id'         => $responsable->id,
            'delegue_user_id'           => $proche->id,
            'membre_id'                 => $carnet->id,
            'droits'                    => Delegation::DROIT_LECTURE,
            'est_le_dossier_du_delegue' => $assertion,
            'invitee_at'                => now(),
            'acceptee_at'               => $acceptee ? now() : null,
        ]);

        return [$responsable, $carnet, $proche];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Le chemin nominal
    // ─────────────────────────────────────────────────────────────────────────

    public function test_le_carnet_reconnu_est_propose_a_la_revendication(): void
    {
        [$responsable, $carnet, $proche] = $this->scene();

        Sanctum::actingAs($proche);
        $this->getJson('/api/v1/membres/revendicables')
            ->assertOk()
            ->assertJsonCount(1, 'revendicables')
            ->assertJsonPath('revendicables.0.membre.id', $carnet->id)
            ->assertJsonPath('revendicables.0.propose_par.nom', $responsable->nom);
    }

    public function test_la_revendication_transfere_la_propriete(): void
    {
        [$responsable, $carnet, $proche] = $this->scene();

        Sanctum::actingAs($proche);
        $this->postJson("/api/v1/membres/{$carnet->id}/revendiquer")
            ->assertOk()
            ->assertJsonPath('membre.est_titulaire', true);

        $carnet->refresh();
        $this->assertSame($proche->id, $carnet->user_id);
        $this->assertTrue($carnet->est_titulaire);

        // L'ancien propriétaire garde la vue — mais par délégation, désormais révocable par la
        // personne concernée. C'est le renversement de propriété que B apporte.
        $this->assertTrue(Delegation::lecturePour($responsable->id, $carnet->id));
    }

    /**
     * LE CŒUR DU MODULE : aucun second NIS n'est créé. La ligne garde son id, donc le NIS, le
     * matricule et les dix-neuf tables qui référencent le dossier suivent sans un seul UPDATE.
     */
    public function test_la_revendication_ne_cree_aucun_second_nis(): void
    {
        [, $carnet, $proche] = $this->scene();

        app(AttributeurNis::class)->attribuer(
            $carnet->fresh(),
            AttributeurNis::MOTIF_CREATION
        );
        $nisAvant   = $carnet->fresh()->nis;
        $journalAvant = DB::table('nis_journal')->count();

        Sanctum::actingAs($proche);
        $this->postJson("/api/v1/membres/{$carnet->id}/revendiquer")->assertOk();

        $this->assertSame($nisAvant, $carnet->fresh()->nis);
        $this->assertSame($journalAvant, DB::table('nis_journal')->count());
        $this->assertSame(1, MembreFamille::where('user_id', $proche->id)->count());
    }

    /** Après revendication, l'écran de complétion de P6.1 ne doit plus apparaître. */
    public function test_apres_revendication_le_compte_a_un_dossier_titulaire(): void
    {
        [, $carnet, $proche] = $this->scene();

        Sanctum::actingAs($proche);
        $this->postJson("/api/v1/membres/{$carnet->id}/revendiquer")->assertOk();

        $this->getJson('/api/v1/membres/titulaire')
            ->assertOk()
            ->assertJsonPath('existe', true);

        $this->getJson('/api/v1/auth/me')->assertJsonPath('user.a_dossier_titulaire', true);
    }

    public function test_la_revendication_laisse_une_trace_immuable(): void
    {
        [$responsable, $carnet, $proche] = $this->scene();

        Sanctum::actingAs($proche);
        $this->postJson("/api/v1/membres/{$carnet->id}/revendiquer")->assertOk();

        $this->assertDatabaseHas('carnet_transferts', [
            'membre_id'       => $carnet->id,
            'ancien_user_id'  => $responsable->id,
            'nouveau_user_id' => $proche->id,
            'motif'           => CarnetTransfert::MOTIF_REVENDICATION,
        ]);

        $trace = CarnetTransfert::first();
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        $trace->update(['motif' => 'falsifie']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Ce qui doit être refusé
    // ─────────────────────────────────────────────────────────────────────────

    /** Sans l'assertion du responsable, un simple délégué en lecture ne revendique rien. */
    public function test_sans_assertion_du_responsable_la_revendication_est_refusee(): void
    {
        [, $carnet, $proche] = $this->scene(assertion: false);

        Sanctum::actingAs($proche);
        $this->getJson('/api/v1/membres/revendicables')->assertJsonCount(0, 'revendicables');
        $this->postJson("/api/v1/membres/{$carnet->id}/revendiquer")
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'REVENDICATION_IMPOSSIBLE');
    }

    /** Une invitation non acceptée ne vaut rien : le consentement du délégué manque. */
    public function test_une_delegation_non_acceptee_ne_permet_pas_de_revendiquer(): void
    {
        [, $carnet, $proche] = $this->scene(acceptee: false);

        Sanctum::actingAs($proche);
        $this->postJson("/api/v1/membres/{$carnet->id}/revendiquer")->assertStatus(409);
    }

    public function test_un_tiers_sans_delegation_ne_revendique_rien(): void
    {
        [, $carnet] = $this->scene();

        Sanctum::actingAs(User::factory()->create());
        $this->postJson("/api/v1/membres/{$carnet->id}/revendiquer")->assertStatus(409);
    }

    /**
     * On ne prend pas au responsable son propre dossier de santé, même s'il coche la case par
     * mégarde. La garde est double : à l'émission de l'assertion, et à la revendication.
     */
    public function test_le_dossier_titulaire_du_responsable_n_est_pas_revendicable(): void
    {
        $responsable = User::factory()->create();
        $sien        = MembreFamille::factory()->for($responsable)->create(['est_titulaire' => true]);
        $proche      = User::factory()->create();

        Delegation::create([
            'titulaire_user_id'         => $responsable->id,
            'delegue_user_id'           => $proche->id,
            'membre_id'                 => $sien->id,
            'droits'                    => Delegation::DROIT_LECTURE,
            'est_le_dossier_du_delegue' => true,
            'invitee_at'                => now(),
            'acceptee_at'               => now(),
        ]);

        Sanctum::actingAs($proche);
        $this->getJson('/api/v1/membres/revendicables')->assertJsonCount(0, 'revendicables');
        $this->postJson("/api/v1/membres/{$sien->id}/revendiquer")->assertStatus(409);

        $this->assertSame($responsable->id, $sien->fresh()->user_id);
    }

    /** Une personne a un seul dossier de santé. Deux titulaires violeraient l'unicité de P6.1. */
    public function test_un_compte_ayant_deja_un_dossier_titulaire_ne_revendique_pas(): void
    {
        [, $carnet, $proche] = $this->scene();
        MembreFamille::factory()->for($proche)->create(['est_titulaire' => true]);

        Sanctum::actingAs($proche);
        $this->getJson('/api/v1/membres/revendicables')->assertJsonCount(0, 'revendicables');
        $this->postJson("/api/v1/membres/{$carnet->id}/revendiquer")->assertStatus(409);
    }

    /** Rejouer la revendication ne doit pas produire un second transfert. */
    public function test_la_revendication_n_est_pas_rejouable(): void
    {
        [, $carnet, $proche] = $this->scene();

        Sanctum::actingAs($proche);
        $this->postJson("/api/v1/membres/{$carnet->id}/revendiquer")->assertOk();
        $this->postJson("/api/v1/membres/{$carnet->id}/revendiquer")->assertStatus(409);

        $this->assertSame(1, CarnetTransfert::count());
    }

    // ─────────────────────────────────────────────────────────────────────────
    // L'assertion à l'émission
    // ─────────────────────────────────────────────────────────────────────────

    public function test_le_partage_en_masse_ne_marque_qu_un_seul_carnet(): void
    {
        $responsable = User::factory()->create();
        $sien        = MembreFamille::factory()->for($responsable)->create();
        $autre       = MembreFamille::factory()->for($responsable)->create();
        $proche      = User::factory()->create();

        Sanctum::actingAs($responsable);
        $this->postJson('/api/v1/delegations/en-masse', [
            'telephone'            => $proche->telephone,
            'membre_id_du_delegue' => $sien->id,
        ])->assertCreated();

        $this->assertTrue(
            Delegation::where('membre_id', $sien->id)->first()->est_le_dossier_du_delegue
        );
        $this->assertFalse(
            Delegation::where('membre_id', $autre->id)->first()->est_le_dossier_du_delegue
        );
    }

    /** Par défaut, aucune assertion : partager n'est pas céder. */
    public function test_un_partage_ordinaire_ne_porte_aucune_assertion(): void
    {
        $responsable = User::factory()->create();
        $carnet      = MembreFamille::factory()->for($responsable)->create();
        $proche      = User::factory()->create();

        Sanctum::actingAs($responsable);
        $this->postJson("/api/v1/membres/{$carnet->id}/delegations", [
            'telephone' => $proche->telephone,
        ])->assertCreated();

        $this->assertFalse(
            Delegation::where('membre_id', $carnet->id)->first()->est_le_dossier_du_delegue
        );
    }
}
