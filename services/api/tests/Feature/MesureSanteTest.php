<?php

namespace Tests\Feature;

use App\Models\MembreFamille;
use App\Models\MesureSante;
use App\Models\User;
use Database\Seeders\ReferentielMesureSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\PublieLesSeuilsDeMesure;
use Tests\TestCase;

/**
 * Module 5 / 5.6 — Journal de bord des maladies chroniques (FN5), étape A.
 *
 * Ce qui doit tenir : le statut d'une mesure est calculé PAR LE SERVEUR d'après le référentiel de
 * seuils (un client ne peut pas déclarer normale une glycémie mortelle) ; une tension est une saisie
 * et deux lignes liées ; une valeur invraisemblable est refusée AVANT écriture ; le patient efface
 * ses erreurs mais pas les mesures d'une structure ; et l'isolation entre comptes tient (anti-IDOR).
 */
class MesureSanteTest extends TestCase
{
    use PublieLesSeuilsDeMesure;
    use RefreshDatabase;

    private User $user;

    private MembreFamille $membre;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ReferentielMesureSeeder::class);

        // Depuis la bascule L1 (ADR-025 §5), seeder la table ne suffit plus : le journal de bord lit
        // la version PUBLIÉE du référentiel national. Ces vecteurs ne sont pas « réparés » pour
        // qu'ils repassent — ils reflètent la nouvelle règle d'exploitation, qui est le but même de
        // la bascule.
        $this->publierLesSeuils('Seuils cliniques de référence, mise en vigueur initiale.');

        $this->user = User::factory()->create();
        $this->membre = MembreFamille::factory()->for($this->user)->create();

        Sanctum::actingAs($this->user);
    }

    private function url(string $suffixe = ''): string
    {
        return "/api/v1/membres/{$this->membre->id}/mesures".$suffixe;
    }

    public function test_le_statut_est_calcule_par_le_serveur_selon_les_seuils(): void
    {
        // Glycémie normale (0,70–1,10 g/L), le client tente de la déclarer critique : ignoré.
        $this->postJson($this->url(), [
            'type_mesure'  => 'glycemie',
            'valeur'       => 0.95,
            'date_mesure'  => now()->subHour()->toDateTimeString(),
            'statut_norme' => 'critique',
        ])
            ->assertCreated()
            ->assertJsonPath('mesures.0.statut_norme', 'normal')
            ->assertJsonPath('mesures.0.unite', 'g/L')
            ->assertJsonPath('alerte', null);
    }

    public function test_une_valeur_critique_declenche_une_alerte_avec_le_conseil_du_referentiel(): void
    {
        // 3,20 g/L ≥ seuil critique haut (2,50) : hyperglycémie majeure.
        $reponse = $this->postJson($this->url(), [
            'type_mesure' => 'glycemie',
            'valeur'      => 3.20,
            'date_mesure' => now()->toDateTimeString(),
        ])
            ->assertCreated()
            ->assertJsonPath('mesures.0.statut_norme', 'critique')
            ->assertJsonPath('alerte.statut', 'critique');

        // Le conseil vient de la base (F1.3), pas d'un texte figé dans l'app : il oriente vers le SAMU.
        $this->assertStringContainsString('185', $reponse->json('alerte.conseil'));
    }

    public function test_une_valeur_basse_est_qualifiee_bas_et_une_valeur_haute_elevee(): void
    {
        $this->postJson($this->url(), [
            'type_mesure' => 'temperature', 'valeur' => 38.4, 'date_mesure' => now()->toDateTimeString(),
        ])->assertCreated()->assertJsonPath('mesures.0.statut_norme', 'eleve');

        $this->postJson($this->url(), [
            'type_mesure' => 'pouls', 'valeur' => 52, 'date_mesure' => now()->toDateTimeString(),
        ])->assertCreated()->assertJsonPath('mesures.0.statut_norme', 'bas');
    }

    public function test_la_tension_est_une_saisie_et_deux_lignes_liees(): void
    {
        $reponse = $this->postJson($this->url(), [
            'type_mesure' => 'tension',
            'systolique'  => 150,
            'diastolique' => 95,
            'date_mesure' => now()->toDateTimeString(),
        ])->assertCreated();

        $mesures = $reponse->json('mesures');
        $this->assertCount(2, $mesures);
        $this->assertSame('tension_systolique', $mesures[0]['type_mesure']);
        $this->assertSame('tension_diastolique', $mesures[1]['type_mesure']);
        $this->assertSame('eleve', $mesures[0]['statut_norme']);   // 150 > 139
        $this->assertSame('eleve', $mesures[1]['statut_norme']);   // 95 > 89

        // Même groupe : les deux lignes forment UNE prise de tension.
        $this->assertNotNull($mesures[0]['groupe_uuid']);
        $this->assertSame($mesures[0]['groupe_uuid'], $mesures[1]['groupe_uuid']);
    }

    public function test_supprimer_une_tension_emporte_ses_deux_lignes(): void
    {
        $mesures = $this->postJson($this->url(), [
            'type_mesure' => 'tension', 'systolique' => 120, 'diastolique' => 80,
            'date_mesure' => now()->toDateTimeString(),
        ])->json('mesures');

        $this->deleteJson($this->url('/'.$mesures[0]['id']))
            ->assertOk()
            ->assertJsonPath('supprimees', 2);

        // Aucune systolique orpheline : une demi-tension ne veut rien dire.
        $this->assertSame(0, MesureSante::count());
    }

    public function test_une_valeur_invraisemblable_est_refusee_avant_ecriture(): void
    {
        // 500 g/L de glycémie : faute de frappe, pas une urgence. Bornes lues au référentiel.
        $this->postJson($this->url(), [
            'type_mesure' => 'glycemie', 'valeur' => 500, 'date_mesure' => now()->toDateTimeString(),
        ])->assertStatus(422)->assertJsonValidationErrors('valeur');

        // Une mesure ne peut pas être future.
        $this->postJson($this->url(), [
            'type_mesure' => 'poids', 'valeur' => 70, 'date_mesure' => now()->addDay()->toDateTimeString(),
        ])->assertStatus(422)->assertJsonValidationErrors('date_mesure');

        $this->assertSame(0, MesureSante::count());
    }

    public function test_le_patient_ne_peut_pas_supprimer_une_mesure_prise_par_une_structure(): void
    {
        $mesure = new MesureSante([
            'type_mesure' => 'poids', 'valeur' => 70, 'date_mesure' => now(), 'source' => 'structure',
        ]);
        $mesure->unite = 'kg';
        $mesure->statut_norme = 'normal';
        $this->membre->mesuresSante()->save($mesure);

        $this->deleteJson($this->url('/'.$mesure->id))
            ->assertStatus(422)
            ->assertJsonValidationErrors('mesure');

        $this->assertSame(1, MesureSante::count());
    }

    public function test_le_journal_renvoie_le_referentiel_et_le_resume_meme_sans_mesure(): void
    {
        $this->getJson($this->url())
            ->assertOk()
            ->assertJsonCount(7, 'referentiels')     // les 7 types du CdC §8.3
            ->assertJsonCount(7, 'resume')
            ->assertJsonCount(0, 'mesures')
            ->assertJsonPath('resume.0.valeur', null);
    }

    public function test_un_autre_compte_ne_peut_ni_lire_ni_ecrire_le_journal(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson($this->url())->assertForbidden();
        $this->postJson($this->url(), [
            'type_mesure' => 'poids', 'valeur' => 70, 'date_mesure' => now()->toDateTimeString(),
        ])->assertForbidden();
    }
}
