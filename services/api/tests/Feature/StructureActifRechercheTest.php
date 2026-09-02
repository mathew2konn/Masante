<?php

namespace Tests\Feature;

use App\Models\AbonnementStructure;
use App\Models\PlanTarifaire;
use App\Models\StructureSanitaire;
use App\Services\StructureService;
use App\Support\MotifSuspension;
use App\Support\StatutAbonnement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;

/**
 * Lot 4 — correction du défaut de la colonne `actif` (CdC §5.4.2).
 *
 * Cahier des charges : rapport de Phase 0 du lot 4 (2026-08-27), croquis de Phase 1 du prompt
 * `04_Prompt_ClaudeCode_Correction_Colonne_Actif.md`, étendu au défaut trouvé au G0 : `show()`
 * (accès direct par id) ne filtrait rien, contrairement à `rechercher()`.
 *
 * Verrouille la distinction centrale : `actif` (administratif) masque, `abonnements_structure`
 * (commercial, lot 1) ne masque JAMAIS — ce sont deux mécanismes qui ne doivent plus jamais se
 * mélanger, quoi qu'un futur correctif tente d'en faire.
 */
class StructureActifRechercheTest extends TestCase
{
    use RefreshDatabase;

    private function service(): StructureService
    {
        return app(StructureService::class);
    }

    private function structure(array $overrides = []): StructureSanitaire
    {
        return StructureSanitaire::create(array_merge([
            'nom' => 'Structure de test '.uniqid(),
            'type' => 'chu',
            'adresse' => 'Abidjan',
            'commune' => 'Cocody',
            'latitude' => 5.35,
            'longitude' => -3.98,
            'actif' => true,
        ], $overrides));
    }

    // ── 1. `actif=false` masque de la recherche ────────────────────────────────────────────

    public function test_actif_false_masque_de_la_recherche(): void
    {
        $visible = $this->structure(['nom' => 'Structure visible']);
        $masquee = $this->structure(['nom' => 'Structure désactivée', 'actif' => false]);

        $resultats = $this->service()->rechercher([]);

        $this->assertTrue($resultats->contains('id', $visible->id));
        $this->assertFalse($resultats->contains('id', $masquee->id), 'Une structure désactivée ne doit jamais apparaître.');
    }

    // ── 2. Une structure suspendue pour impayé (Palier 0) reste visible ────────────────────

    public function test_suspendu_pour_impaye_reste_visible(): void
    {
        $structure = $this->structure(['nom' => 'Structure suspendue pour impayé']);

        $plan = PlanTarifaire::create([
            'code' => 'TEST_PLAN',
            'libelle' => 'Plan de test',
            'montant_mensuel' => 15000,
            'devise' => 'XOF',
            'commission_incluse' => false,
            'actif' => true,
            'date_effet' => now()->subYear()->toDateString(),
        ]);

        AbonnementStructure::create([
            'structure_sanitaire_id' => $structure->id,
            'plan_tarifaire_id' => $plan->id,
            'rang_signature' => 1,
            'date_debut' => now()->subMonths(2)->toDateString(),
            'date_fin_essai' => now()->subMonths(2)->toDateString(),
            'statut' => StatutAbonnement::SUSPENDU,
            'motif_suspension' => MotifSuspension::IMPAYE,
            'date_bascule_palier0' => now()->subDays(5),
        ]);

        $resultats = $this->service()->rechercher([]);

        $this->assertTrue(
            $resultats->contains('id', $structure->id),
            'Le Palier 0 (impayé) ne doit JAMAIS masquer une structure — décision D-E1.'
        );
        $this->assertTrue($structure->fresh()->actif, 'La bascule Palier 0 ne doit jamais avoir touché `actif`.');
    }

    // ── 3. `show()` (fiche par id) refuse aussi une structure désactivée ──────────────────

    public function test_actif_false_refuse_en_fiche(): void
    {
        $masquee = $this->structure(['actif' => false]);

        $this->expectException(NotFoundHttpException::class);
        $this->service()->fiche($masquee);
    }

    public function test_actif_true_reste_consultable_en_fiche(): void
    {
        $visible = $this->structure();

        $fiche = $this->service()->fiche($visible);

        $this->assertSame($visible->id, $fiche->id);
    }

    // ── 4. Pharmacies de garde : une pharmacie désactivée n'apparaît plus ──────────────────

    public function test_actif_false_exclu_des_pharmacies_de_garde(): void
    {
        $pharmacieActive = $this->structure(['type' => 'pharmacie', 'nom' => 'Pharmacie active']);
        $pharmacieDesactivee = $this->structure(['type' => 'pharmacie', 'nom' => 'Pharmacie fermée', 'actif' => false]);

        \App\Models\PharmacieGarde::create(['structure_id' => $pharmacieActive->id, 'date' => now()->toDateString()]);
        \App\Models\PharmacieGarde::create(['structure_id' => $pharmacieDesactivee->id, 'date' => now()->toDateString()]);

        $resultats = $this->service()->pharmaciesDeGarde([]);

        $this->assertTrue($resultats->contains('id', $pharmacieActive->id));
        $this->assertFalse($resultats->contains('id', $pharmacieDesactivee->id));
    }
}
