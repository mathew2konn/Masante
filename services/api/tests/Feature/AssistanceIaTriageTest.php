<?php

namespace Tests\Feature;

use App\Models\PredictionIa;
use App\Models\Symptome;
use App\Services\Triage\DisjoncteurTriageIa;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\PublieLeProtocoleDeTriage;
use Tests\TestCase;

/**
 * P10c-2-i (F7/F8/F9) — L'appel à `triage-service` et sa dégradation (CDC_05, CDC_03 §10.1).
 *
 * Aucun test ne prouve un VRAI score : il n'y a pas de modèle (Y5, F5). Ce qui est protégé ici,
 * c'est que le triage aboutit TOUJOURS — gaté OFF, service qui refuse honnêtement, service
 * injoignable, disjoncteur ouvert — et que chaque cas laisse une trace distincte dans
 * `predictions_ia`. Et que rien d'identifiant ne quitte jamais Laravel (F9).
 */
class AssistanceIaTriageTest extends TestCase
{
    use PublieLeProtocoleDeTriage;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    private function symptomePublie(): Symptome
    {
        $symptome = Symptome::create([
            'nom_fr' => 'Fièvre', 'categorie' => 'general',
            'poids_severite' => 20, 'drapeau_rouge' => false, 'actif' => true,
        ]);
        $this->publierProtocoleDeTriage();
        $this->publierReferentiel(\App\Services\Referentiel\SourceSymptomesTriage::CODE);

        return $symptome;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // F7 — gaté OFF par défaut
    // ─────────────────────────────────────────────────────────────────────────

    public function test_gate_off_par_defaut_aucun_appel_reseau_et_le_triage_aboutit(): void
    {
        Http::fake();
        $symptome = $this->symptomePublie();

        $reponse = $this->postJson('/api/v1/triage/analyser', ['symptomes' => [$symptome->id]])
            ->assertCreated();

        Http::assertNothingSent();

        $prediction = PredictionIa::where('triage_id', $reponse->json('triage_id'))->sole();
        $this->assertSame('degrade', $prediction->mode);
        $this->assertSame('desactive', $prediction->motif_degradation);
        $this->assertSame(0, $prediction->latence_ms);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // F6 — refus honnête du service : atteint, répondu, jamais un score inventé
    // ─────────────────────────────────────────────────────────────────────────

    public function test_gate_on_service_debout_503_honnete_le_triage_reste_complet(): void
    {
        config(['masante.triage_ia.enabled' => true]);
        Http::fake([
            '*/api/v1/triage/score' => Http::response(
                ['motif' => 'modele_indisponible', 'message' => 'Aucun modèle chargé.'], 503
            ),
        ]);
        $symptome = $this->symptomePublie();

        $reponse = $this->postJson('/api/v1/triage/analyser', ['symptomes' => [$symptome->id]])
            ->assertCreated();

        // Le triage lui-même n'est PAS dégradé : la recommandation, le niveau, le score sont
        // ceux du protocole seul (A6 — le résultat du protocole est complet sans l'IA).
        $this->assertNotNull($reponse->json('niveau'));
        $this->assertNotNull($reponse->json('recommandation_texte'));

        $prediction = PredictionIa::where('triage_id', $reponse->json('triage_id'))->sole();
        $this->assertSame('degrade', $prediction->mode);
        $this->assertSame('modele_indisponible', $prediction->motif_degradation);

        // Un 503 honnête n'ouvre PAS le disjoncteur (voir l'en-tête de ClientTriageIa) : le
        // service a répondu, il n'est pas en panne.
        $this->assertFalse(app(DisjoncteurTriageIa::class)->estOuvert());
    }

    // ─────────────────────────────────────────────────────────────────────────
    // F8 — service injoignable → échec compté → disjoncteur ouvert → plus aucun appel
    // ─────────────────────────────────────────────────────────────────────────

    public function test_service_injoignable_ouvre_le_disjoncteur_apres_le_seuil(): void
    {
        config(['masante.triage_ia.enabled' => true, 'masante.triage_ia.disjoncteur_seuil_echecs' => 2]);
        Http::fake(['*/api/v1/triage/score' => Http::failedConnection('Timed out.')]);
        $symptome = $this->symptomePublie();

        // Deux échecs pour atteindre le seuil configuré.
        $this->postJson('/api/v1/triage/analyser', ['symptomes' => [$symptome->id]])->assertCreated();
        $this->postJson('/api/v1/triage/analyser', ['symptomes' => [$symptome->id]])->assertCreated();

        $this->assertTrue(app(DisjoncteurTriageIa::class)->estOuvert());
        Http::assertSentCount(2);

        // Le troisième appel ne part PAS : latence effondrée, prouvée par l'absence de requête.
        $reponse = $this->postJson('/api/v1/triage/analyser', ['symptomes' => [$symptome->id]])
            ->assertCreated();
        Http::assertSentCount(2); // toujours 2 : le 3e n'a rien envoyé

        $prediction = PredictionIa::where('triage_id', $reponse->json('triage_id'))->sole();
        $this->assertSame('disjoncteur_ouvert', $prediction->motif_degradation);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // F9 — minimisation : jamais d'identité dans la charge sortante
    // ─────────────────────────────────────────────────────────────────────────

    public function test_la_charge_sortante_ne_contient_aucune_identite(): void
    {
        config(['masante.triage_ia.enabled' => true]);
        Http::fake(['*/api/v1/triage/score' => Http::response(['motif' => 'modele_indisponible', 'message' => 'x'], 503)]);
        $symptome = $this->symptomePublie();

        $this->postJson('/api/v1/triage/analyser', [
            'symptomes' => [$symptome->id],
            'patient_nom' => 'Kouassi Awa',
            'patient_age' => 34,
            'patient_sexe' => 'F',
        ])->assertCreated();

        Http::assertSent(function ($requete) {
            $corps = $requete->data();

            $this->assertArrayNotHasKey('patient_nom', $corps);
            $this->assertArrayNotHasKey('membre_id', $corps);
            $this->assertArrayNotHasKey('user_id', $corps);
            $this->assertArrayNotHasKey('nis', $corps);

            $json = json_encode($corps);
            $this->assertStringNotContainsString('Kouassi', $json);
            $this->assertStringNotContainsString('Awa', $json);

            $this->assertStringStartsWith('triage:', $corps['reference']);

            return true;
        });
    }
}
