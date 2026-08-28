<?php

namespace Tests\Feature;

use App\Models\JeuDonneesEntrainement;
use App\Models\MembreFamille;
use App\Models\Triage;
use App\Models\User;
use App\Services\Triage\ServiceRetourTriage;
use App\Support\RegistreRetourTriage;
use Database\Seeders\PortailRolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * P10c-2-i (F4) — L'écran portail de revue, sans investissement de design (précédent K1 de P6.4d).
 *
 * La garde qui fait autorité reste {@see \App\Services\Triage\ServiceValidationApprentissage} — ces
 * vecteurs prouvent la CHAÎNE HTTP réelle (session, CSRF, redirection), pas une seconde fois la
 * règle métier, déjà couverte par `JeuApprentissageTriageTest`.
 */
class ValidationApprentissageEcranTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PortailRolesSeeder::class);
    }

    private function ligneEnAttente(): JeuDonneesEntrainement
    {
        $membre = MembreFamille::factory()->for(User::factory())->create();
        $triage = Triage::create([
            'membre_id' => $membre->id, 'patient_age' => 40, 'patient_sexe' => 'M',
            'symptomes_json' => [3], 'reponses_json' => [], 'score_severite' => 30,
            'niveau' => 'modere', 'recommandation_texte' => 'x',
        ]);
        $soignant = User::factory()->create();
        $soignant->givePermissionTo('triage.retour');

        app(ServiceRetourTriage::class)->enregistrer($soignant, $membre, $triage, RegistreRetourTriage::ADAPTEE);

        return JeuDonneesEntrainement::where('triage_id', $triage->id)->sole();
    }

    private function reviseur(): User
    {
        $user = User::factory()->create(['password' => bcrypt('Secret@2026!')]);
        $user->givePermissionTo('apprentissage.valider');

        return $user;
    }

    public function test_un_gestionnaire_non_habilite_est_refuse(): void
    {
        $gestionnaire = User::factory()->create();

        $this->actingAs($gestionnaire, 'web')
            ->get(route('portail.apprentissage.index'))
            ->assertForbidden();
    }

    public function test_un_reviseur_habilite_voit_la_ligne_en_attente(): void
    {
        $ligne = $this->ligneEnAttente();

        $this->actingAs($this->reviseur(), 'web')
            ->get(route('portail.apprentissage.index'))
            ->assertOk()
            ->assertSee((string) $ligne->id);
    }

    public function test_valider_depuis_l_ecran_enregistre_la_decision_et_redirige(): void
    {
        $ligne = $this->ligneEnAttente();
        $reviseur = $this->reviseur();

        $this->actingAs($reviseur, 'web')
            ->post(route('portail.apprentissage.valider', $ligne->id))
            ->assertRedirect(route('portail.apprentissage.index'));

        $this->assertSame('valide', $ligne->fresh()->validation->statut);

        // La ligne décidée ne réapparaît plus dans la liste des lignes en attente. Un simple
        // `assertDontSee((string) $id)` serait un piège si l'id est un chiffre bas (« 1 » figure
        // partout dans une page HTML — `initial-scale=1`, versions de script…) : on cherche l'URL
        // d'action, une chaîne bien plus longue et donc sans ambiguïté.
        $this->actingAs($reviseur, 'web')
            ->get(route('portail.apprentissage.index'))
            ->assertDontSee(route('portail.apprentissage.valider', $ligne->id));
    }

    public function test_rejeter_sans_motif_depuis_l_ecran_est_refuse(): void
    {
        $ligne = $this->ligneEnAttente();

        $this->actingAs($this->reviseur(), 'web')
            ->post(route('portail.apprentissage.rejeter', $ligne->id), ['motif' => ''])
            ->assertRedirect();

        $this->assertNull($ligne->fresh()->validation);
    }
}
