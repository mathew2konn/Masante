<?php

namespace Tests\Feature;

use App\Models\AlerteSos;
use App\Models\MembreFamille;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Module 5 / 5.2 — Journalisation des alertes SOS (FN1), étape A.
 *
 * Rappel : l'alerte réelle (appel SAMU + SMS) part du téléphone. Ici on ne teste que
 * l'enregistrement a posteriori, qui ne doit jamais être un obstacle au déclenchement.
 */
class AlerteSosTest extends TestCase
{
    use RefreshDatabase;

    public function test_une_alerte_est_journalisee_avec_sa_position_et_son_contact(): void
    {
        $user = User::factory()->create();
        $membre = MembreFamille::factory()->for($user)->create();
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/sos', [
            'membre_id' => $membre->id,
            'latitude' => 5.3599517, 'longitude' => -4.0082563, 'precision_metres' => 12,
            'canal' => 'appel_sms',
            'contact_prevenu_nom' => 'Kouassi', 'contact_prevenu_tel' => '0701020304',
        ])->assertCreated();

        $alerte = AlerteSos::firstOrFail();
        $this->assertSame($user->id, $alerte->user_id);
        $this->assertSame($membre->id, $alerte->membre_id);
        $this->assertTrue($alerte->aUnePosition());
        $this->assertSame('Kouassi', $alerte->contact_prevenu_nom);
        $this->assertNotNull($alerte->declenchee_le);
    }

    public function test_une_alerte_sans_position_ni_membre_reste_acceptee(): void
    {
        // GPS refusé ou indisponible, aucune carte vitale activée : un SOS sans position vaut
        // mieux qu'un SOS refusé.
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/v1/sos', ['canal' => 'appel'])->assertCreated();

        $this->assertFalse(AlerteSos::firstOrFail()->aUnePosition());
    }

    public function test_une_position_incomplete_ou_hors_bornes_est_refusee(): void
    {
        Sanctum::actingAs(User::factory()->create());

        // Latitude sans longitude : la trace serait inexploitable.
        $this->postJson('/api/v1/sos', ['canal' => 'appel', 'latitude' => 5.36])
            ->assertJsonValidationErrors('longitude');

        $this->postJson('/api/v1/sos', ['canal' => 'appel', 'latitude' => 99, 'longitude' => 0])
            ->assertJsonValidationErrors('latitude');

        $this->postJson('/api/v1/sos', ['canal' => 'telepathie'])
            ->assertJsonValidationErrors('canal');

        $this->assertDatabaseCount('alertes_sos', 0);
    }

    public function test_on_ne_declenche_pas_une_alerte_au_nom_du_membre_d_autrui(): void
    {
        $membreAutrui = MembreFamille::factory()->for(User::factory())->create();
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/v1/sos', ['canal' => 'appel', 'membre_id' => $membreAutrui->id])
            ->assertJsonValidationErrors('membre_id');

        $this->assertDatabaseCount('alertes_sos', 0);
    }

    public function test_le_patient_consulte_l_historique_de_ses_propres_alertes(): void
    {
        $user = User::factory()->create();
        $membre = MembreFamille::factory()->for($user)->create();
        AlerteSos::create(['user_id' => $user->id, 'membre_id' => $membre->id, 'canal' => 'appel']);
        // Alerte d'un autre compte : elle ne doit pas apparaître.
        AlerteSos::create(['user_id' => User::factory()->create()->id, 'canal' => 'sms']);

        Sanctum::actingAs($user);

        $this->getJson('/api/v1/sos')
            ->assertOk()
            ->assertJsonCount(1, 'alertes')
            ->assertJsonPath('alertes.0.canal', 'appel')
            ->assertJsonPath('alertes.0.membre.prenom', $membre->prenom);
    }

    public function test_l_endpoint_sos_exige_une_authentification(): void
    {
        $this->postJson('/api/v1/sos', ['canal' => 'appel'])->assertUnauthorized();
    }
}
