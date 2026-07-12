<?php

namespace Tests\Feature;

use App\Models\MembreFamille;
use App\Models\Rappel;
use App\Models\SuiviGrossesse;
use App\Models\User;
use Database\Seeders\EtapePrenataleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Module 5 / 5.5 — Suivi de grossesse (FN4), étape A.
 *
 * Points clés : semaine d'aménorrhée CALCULÉE (jamais stockée), terme serveur (DDG + 280 j),
 * une seule grossesse en cours par membre, consultations append-only (le client n'écrit
 * jamais le tableau), rappels CPN auto marqués par FK et gérés par le serveur.
 */
class GrossesseTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private MembreFamille $membre;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(EtapePrenataleSeeder::class);

        $this->user = User::factory()->create();
        $this->membre = MembreFamille::factory()->for($this->user)->create(['sexe' => 'F']);
    }

    private function declarer(string $ddg): SuiviGrossesse
    {
        Sanctum::actingAs($this->user);
        $this->postJson("/api/v1/membres/{$this->membre->id}/grossesse", [
            'date_debut_grossesse' => $ddg,
        ])->assertCreated();

        return SuiviGrossesse::latest('id')->firstOrFail();
    }

    public function test_la_declaration_cree_le_suivi_avec_terme_calcule_et_rappels_cpn(): void
    {
        Sanctum::actingAs($this->user);

        // DDG il y a 10 semaines révolues → 11e semaine ; contacts 12→40 SA tous à venir.
        $ddg = now()->subWeeks(10)->toDateString();

        $reponse = $this->postJson("/api/v1/membres/{$this->membre->id}/grossesse", [
            'date_debut_grossesse' => $ddg,
            // Champs interdits : ils doivent être ignorés (terme serveur, statut par défaut).
            'date_terme_prevue' => now()->toDateString(),
            'statut' => 'termine',
            'consultations_json' => [['date' => '2020-01-01', 'notes' => 'injection']],
        ]);

        $reponse->assertCreated()
            ->assertJsonPath('suivi.statut', 'en_cours')
            ->assertJsonPath('suivi.semaine_actuelle', 11)
            ->assertJsonPath('rappels_crees', 8);

        $suivi = SuiviGrossesse::firstOrFail();
        $this->assertSame(
            now()->subWeeks(10)->addDays(SuiviGrossesse::DUREE_JOURS)->toDateString(),
            $suivi->date_terme_prevue->toDateString()
        );
        $this->assertNull($suivi->consultations_json);

        // Les rappels CPN sont rattachés au suivi et posés sur les dates estimées.
        $this->assertSame(8, Rappel::where('suivi_grossesse_id', $suivi->id)->count());
        $premier = Rappel::where('suivi_grossesse_id', $suivi->id)->orderBy('date_debut')->first();
        $this->assertSame('rendez_vous', $premier->type);
        $this->assertSame(
            $suivi->date_debut_grossesse->copy()->addWeeks(12)->toDateString(),
            $premier->date_debut->toDateString()
        );
    }

    public function test_les_contacts_deja_depasses_ne_generent_pas_de_rappel_retroactif(): void
    {
        // Déclaration tardive à 30 SA révolues : les contacts 12/20/26/30 SA sont passés.
        $suivi = $this->declarer(now()->subWeeks(30)->toDateString());

        $this->assertSame(4, $suivi->rappelsCpn()->count()); // 34, 36, 38, 40 SA
    }

    public function test_le_suivi_est_refuse_pour_un_membre_masculin(): void
    {
        $homme = MembreFamille::factory()->for($this->user)->create(['sexe' => 'M']);
        Sanctum::actingAs($this->user);

        $this->postJson("/api/v1/membres/{$homme->id}/grossesse", [
            'date_debut_grossesse' => now()->subWeeks(8)->toDateString(),
        ])->assertUnprocessable()->assertJsonValidationErrors('membre');
    }

    public function test_une_seule_grossesse_en_cours_par_membre(): void
    {
        $this->declarer(now()->subWeeks(8)->toDateString());

        $this->postJson("/api/v1/membres/{$this->membre->id}/grossesse", [
            'date_debut_grossesse' => now()->subWeeks(4)->toDateString(),
        ])->assertUnprocessable()->assertJsonValidationErrors('suivi');

        $this->assertSame(1, SuiviGrossesse::count());
    }

    public function test_une_ddg_future_ou_invraisemblable_est_refusee(): void
    {
        Sanctum::actingAs($this->user);

        foreach ([now()->addDay(), now()->subWeeks(45)] as $ddg) {
            $this->postJson("/api/v1/membres/{$this->membre->id}/grossesse", [
                'date_debut_grossesse' => $ddg->toDateString(),
            ])->assertUnprocessable()->assertJsonValidationErrors('date_debut_grossesse');
        }
    }

    public function test_le_get_renvoie_suivi_historique_et_calendrier_date(): void
    {
        $suivi = $this->declarer(now()->subWeeks(21)->toDateString());

        $reponse = $this->getJson("/api/v1/membres/{$this->membre->id}/grossesse")
            ->assertOk()
            ->assertJsonPath('suivi.id', $suivi->id)
            ->assertJsonPath('suivi.semaine_actuelle', 22)
            ->assertJsonCount(8, 'calendrier')
            // Contact 1 (12 SA) déjà dépassé en semaine 22 ; contact 3 (26 SA) à venir.
            ->assertJsonPath('calendrier.0.passee', true)
            ->assertJsonPath('calendrier.2.passee', false)
            ->assertJsonPath(
                'calendrier.0.date_estimee',
                $suivi->date_debut_grossesse->copy()->addWeeks(12)->toDateString()
            );

        $this->assertNotEmpty($reponse->json('calendrier.0.conseils_nutrition'));
    }

    public function test_le_calendrier_educatif_est_disponible_sans_grossesse_declaree(): void
    {
        Sanctum::actingAs($this->user);

        $this->getJson("/api/v1/membres/{$this->membre->id}/grossesse")
            ->assertOk()
            ->assertJsonPath('suivi', null)
            ->assertJsonCount(8, 'calendrier')
            ->assertJsonPath('calendrier.0.date_estimee', null)
            ->assertJsonPath('calendrier.0.passee', null);
    }

    public function test_une_consultation_est_ajoutee_en_append_only(): void
    {
        $suivi = $this->declarer(now()->subWeeks(20)->toDateString());

        $this->postJson("/api/v1/membres/{$this->membre->id}/grossesse/{$suivi->id}/consultations", [
            'date'      => now()->subWeeks(8)->toDateString(),
            'medecin'   => 'Dr Kouadio',
            'structure' => 'CHU de Cocody',
            'notes'     => 'RAS, TA normale.',
        ])->assertCreated()
            ->assertJsonPath('suivi.consultations_json.0.medecin', 'Dr Kouadio');

        // La date d'une CPN ne peut être ni future ni antérieure à la DDG.
        $this->postJson("/api/v1/membres/{$this->membre->id}/grossesse/{$suivi->id}/consultations", [
            'date' => now()->addDay()->toDateString(),
        ])->assertJsonValidationErrors('date');

        $this->postJson("/api/v1/membres/{$this->membre->id}/grossesse/{$suivi->id}/consultations", [
            'date' => now()->subWeeks(30)->toDateString(),
        ])->assertJsonValidationErrors('date');

        $this->assertCount(1, $suivi->refresh()->consultations_json);
        $this->assertArrayHasKey('enregistree_le', $suivi->consultations_json[0]);
    }

    public function test_le_client_ne_peut_pas_reecrire_le_tableau_des_consultations(): void
    {
        $suivi = $this->declarer(now()->subWeeks(20)->toDateString());
        $this->postJson("/api/v1/membres/{$this->membre->id}/grossesse/{$suivi->id}/consultations", [
            'date' => now()->subWeeks(8)->toDateString(), 'notes' => 'CPN 1',
        ])->assertCreated();

        // Tentative d'écrasement via l'update du suivi : le champ n'est pas accepté.
        $this->putJson("/api/v1/membres/{$this->membre->id}/grossesse/{$suivi->id}", [
            'consultations_json' => [],
        ])->assertOk();

        $this->assertCount(1, $suivi->refresh()->consultations_json);
    }

    public function test_l_ajustement_de_la_ddg_recalcule_terme_et_rappels(): void
    {
        $suivi = $this->declarer(now()->subWeeks(10)->toDateString());
        $this->assertSame(8, $suivi->rappelsCpn()->count());

        // Rappel personnel de l'utilisateur : il ne doit JAMAIS être touché par la régénération.
        $perso = new Rappel([
            'type' => 'medicament', 'titre' => 'Fer + acide folique', 'contenu' => 'Chaque matin',
            'frequence' => 'quotidien', 'heure' => '07:00', 'date_debut' => now()->toDateString(),
            'actif' => true,
        ]);
        $this->membre->rappels()->save($perso);

        // Échographie de datation : la grossesse est en fait à 22 SA révolues.
        $nouvelleDdg = now()->subWeeks(22)->toDateString();
        $this->putJson("/api/v1/membres/{$this->membre->id}/grossesse/{$suivi->id}", [
            'date_debut_grossesse' => $nouvelleDdg,
        ])->assertOk()->assertJsonPath('suivi.semaine_actuelle', 23);

        $suivi->refresh();
        $this->assertSame(
            SuiviGrossesse::DUREE_JOURS,
            (int) $suivi->date_debut_grossesse->diffInDays($suivi->date_terme_prevue)
        );
        // Contacts 12 et 20 SA désormais dépassés : 6 rappels CPN au lieu de 8.
        $this->assertSame(6, $suivi->rappelsCpn()->count());
        $this->assertTrue($perso->refresh()->actif);
    }

    public function test_la_cloture_desactive_les_rappels_cpn_et_libere_un_nouveau_suivi(): void
    {
        $suivi = $this->declarer(now()->subWeeks(10)->toDateString());

        $this->putJson("/api/v1/membres/{$this->membre->id}/grossesse/{$suivi->id}", [
            'statut' => 'termine',
        ])->assertOk()
            ->assertJsonPath('suivi.statut', 'termine')
            ->assertJsonPath('suivi.semaine_actuelle', null); // plus de « semaine en cours » après clôture

        $this->assertSame(0, $suivi->rappelsCpn()->where('actif', true)->count());

        // Un suivi clos est figé (rétention) ; une nouvelle grossesse peut être déclarée.
        $this->putJson("/api/v1/membres/{$this->membre->id}/grossesse/{$suivi->id}", [
            'statut' => 'interruption',
        ])->assertUnprocessable();

        $this->postJson("/api/v1/membres/{$this->membre->id}/grossesse", [
            'date_debut_grossesse' => now()->subWeeks(2)->toDateString(),
        ])->assertCreated();
    }

    public function test_anti_idor_le_dossier_grossesse_d_autrui_est_inaccessible(): void
    {
        $suivi = $this->declarer(now()->subWeeks(10)->toDateString());

        Sanctum::actingAs(User::factory()->create());

        $this->getJson("/api/v1/membres/{$this->membre->id}/grossesse")->assertForbidden();
        $this->postJson("/api/v1/membres/{$this->membre->id}/grossesse", [
            'date_debut_grossesse' => now()->subWeeks(4)->toDateString(),
        ])->assertForbidden();
        $this->putJson("/api/v1/membres/{$this->membre->id}/grossesse/{$suivi->id}", [
            'statut' => 'termine',
        ])->assertForbidden();
    }

    public function test_les_endpoints_grossesse_exigent_une_authentification(): void
    {
        $this->getJson("/api/v1/membres/{$this->membre->id}/grossesse")->assertUnauthorized();
    }
}
