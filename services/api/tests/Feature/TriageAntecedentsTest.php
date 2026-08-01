<?php

namespace Tests\Feature;

use App\Models\MembreFamille;
use App\Models\Symptome;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Étape 2A.4 — Branchement antécédents → score de triage (dette F1.3) avec contrôle d'accès.
 */
class TriageAntecedentsTest extends TestCase
{
    use RefreshDatabase;

    private function symptome(int $poids = 10): Symptome
    {
        return Symptome::create([
            'nom_fr' => 'Toux', 'categorie' => 'general',
            'poids_severite' => $poids, 'drapeau_rouge' => false, 'actif' => true,
        ]);
    }

    public function test_le_triage_d_un_membre_integre_ses_antecedents_plafonnes(): void
    {
        $symptome = $this->symptome(10);
        $user = User::factory()->create();
        $membre = MembreFamille::factory()->for($user)->create();
        // Somme des impacts = 27 → replafonnée à 20 par TriageService.
        $membre->antecedents()->create(['type' => 'maladie_chronique', 'description' => 'A', 'impact_triage' => 12]);
        $membre->antecedents()->create(['type' => 'maladie_chronique', 'description' => 'B', 'impact_triage' => 15]);
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/triage/analyser', [
            'symptomes' => [$symptome->id], 'membre_id' => $membre->id,
        ])
            ->assertCreated()
            ->assertJsonPath('details_score.symptomes', 10)
            ->assertJsonPath('details_score.antecedents', 20);
    }

    public function test_le_triage_rattache_au_membre_d_un_autre_est_refuse(): void
    {
        $symptome = $this->symptome();
        $membre = MembreFamille::factory()->for(User::factory())->create();
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/v1/triage/analyser', [
            'symptomes' => [$symptome->id], 'membre_id' => $membre->id,
        ])->assertForbidden();
    }

    public function test_un_triage_avec_membre_exige_l_authentification(): void
    {
        $symptome = $this->symptome();
        $membre = MembreFamille::factory()->for(User::factory())->create();

        $this->postJson('/api/v1/triage/analyser', [
            'symptomes' => [$symptome->id], 'membre_id' => $membre->id,
        ])->assertUnauthorized();
    }

    public function test_le_triage_anonyme_reste_possible_sans_membre(): void
    {
        $symptome = $this->symptome();

        $this->postJson('/api/v1/triage/analyser', ['symptomes' => [$symptome->id]])
            ->assertCreated()
            ->assertJsonPath('details_score.antecedents', 0);
    }
}
