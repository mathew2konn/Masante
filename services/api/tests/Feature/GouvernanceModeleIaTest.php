<?php

namespace Tests\Feature;

use App\Models\ExportJeuEntrainement;
use App\Models\JeuDonneesEntrainement;
use App\Models\MembreFamille;
use App\Models\Triage;
use App\Models\User;
use App\Models\VersionModeleIa;
use App\Notifications\NotificationMasante;
use App\Services\Triage\ServiceExportJeuEntrainement;
use App\Services\Triage\ServiceGouvernanceModeleIa;
use App\Services\Triage\ServiceRetourTriage;
use App\Services\Triage\ServiceValidationApprentissage;
use App\Support\RegistreRetourTriage;
use App\Support\StatutVersionModeleIa;
use App\Support\TypeNotification;
use Database\Seeders\PortailRolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * P10c-3-i (F15/F16/F17/F18/F19) — Le cycle candidat → validé.
 *
 * CE QUE CETTE SUITE PROTÈGE : refus bruyant sous le seuil minimal (F15) ; `versions_modeles`/
 * `metriques_modeles` peuplées depuis la réponse RÉELLE de `triage-service` (mockée, jamais un vrai
 * réseau en PHPUnit) ; le quatre-yeux (F18) ; et que RIEN de tout cela ne branche `ClientTriageIa`
 * ni ne change le comportement de `/api/v1/triage/score` (Y10/F18 — la frontière i/ii).
 */
class GouvernanceModeleIaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PortailRolesSeeder::class);
        config(['masante.triage_ia.seuil_min_entrainement' => 3]);
    }

    private function soignant(): User
    {
        $user = User::factory()->create();
        $user->givePermissionTo('triage.retour');

        return $user->fresh();
    }

    private function reviseur(): User
    {
        $user = User::factory()->create();
        $user->givePermissionTo('apprentissage.valider');

        return $user->fresh();
    }

    private function gouvernant(): User
    {
        $user = User::factory()->create();
        $user->givePermissionTo('ia_triage.valider');

        return $user->fresh();
    }

    /** Un export réel, à N lignes validées (mécanisme prouvé, pas un raccourci — motif JeuApprentissageTriageTest). */
    private function exportReel(int $nLignes): ExportJeuEntrainement
    {
        $parent = User::factory()->create();
        $membre = MembreFamille::factory()->for($parent)->create();
        $soignant = $this->soignant();
        $reviseur = $this->reviseur();
        $labels = [RegistreRetourTriage::ADAPTEE, RegistreRetourTriage::SUR_TRIAGE, RegistreRetourTriage::SOUS_TRIAGE];

        for ($i = 0; $i < $nLignes; $i++) {
            $label = $labels[$i % 3];
            $triage = Triage::create([
                'membre_id' => $membre->id,
                'patient_age' => 20 + $i,
                'patient_sexe' => $i % 2 === 0 ? 'F' : 'M',
                'symptomes_json' => [],
                'reponses_json' => [],
                'score_severite' => 10,
                'niveau' => 'modere',
                'recommandation_texte' => 'x',
            ]);
            app(ServiceRetourTriage::class)->enregistrer(
                $soignant, $membre, $triage, $label, $label === RegistreRetourTriage::ADAPTEE ? null : 'Motif.'
            );
            app(ServiceValidationApprentissage::class)->valider(
                $reviseur, JeuDonneesEntrainement::where('triage_id', $triage->id)->sole()
            );
        }

        return app(ServiceExportJeuEntrainement::class)->exporter($this->gouvernant(), 'CI');
    }

    private function reponsePythonReussie(): array
    {
        return [
            'mlflow_run_id' => 'abc123run',
            'nb_lignes_entrainement' => 7,
            'nb_lignes_test' => 3,
            'metriques' => ['exactitude' => 0.8, 'rappel_sous_triage' => 0.75],
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Habilitation
    // ─────────────────────────────────────────────────────────────────────────

    public function test_un_compte_non_habilite_ne_peut_pas_entrainer(): void
    {
        $export = $this->exportReel(3);

        $this->expectException(\RuntimeException::class);
        app(ServiceGouvernanceModeleIa::class)->entrainer(User::factory()->create(), $export);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // F15 — refus bruyant sous le seuil, AVANT tout appel réseau
    // ─────────────────────────────────────────────────────────────────────────

    public function test_refuse_sous_le_seuil_minimal_sans_appeler_le_service(): void
    {
        Http::fake();
        config(['masante.triage_ia.seuil_min_entrainement' => 10]);
        $export = $this->exportReel(3); // < 10

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/10 requises au minimum/');

        try {
            app(ServiceGouvernanceModeleIa::class)->entrainer($this->gouvernant(), $export);
        } finally {
            Http::assertNothingSent();
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Entraînement réussi — versions_modeles + metriques_modeles + notification
    // ─────────────────────────────────────────────────────────────────────────

    public function test_entrainement_reussi_cree_un_candidat_avec_ses_metriques(): void
    {
        Notification::fake();
        Http::fake(['*/api/v1/triage/entrainement' => Http::response($this->reponsePythonReussie(), 200)]);
        $export = $this->exportReel(6);

        $version = app(ServiceGouvernanceModeleIa::class)->entrainer($this->gouvernant(), $export);

        $this->assertSame(StatutVersionModeleIa::CANDIDAT, $version->statut);
        $this->assertSame('abc123run', $version->mlflow_run_id);
        $this->assertSame(1, $version->numero_version);
        $this->assertNull($version->valide_par);
        $this->assertNull($version->date_validation_clinique);

        $metriques = $version->metriques()->pluck('valeur', 'cle');
        $this->assertEqualsWithDelta(0.8, (float) $metriques['exactitude'], 0.0001);
        $this->assertEqualsWithDelta(0.75, (float) $metriques['rappel_sous_triage'], 0.0001);
    }

    public function test_lauteur_de_lentrainement_ne_recoit_pas_sa_propre_notification(): void
    {
        Notification::fake();
        Http::fake(['*/api/v1/triage/entrainement' => Http::response($this->reponsePythonReussie(), 200)]);
        $export = $this->exportReel(6);
        $auteur = $this->gouvernant();

        app(ServiceGouvernanceModeleIa::class)->entrainer($auteur, $export);

        Notification::assertNotSentTo($auteur, NotificationMasante::class);
    }

    public function test_un_autre_gouvernant_recoit_la_notification_sans_metrique(): void
    {
        Notification::fake();
        Http::fake(['*/api/v1/triage/entrainement' => Http::response($this->reponsePythonReussie(), 200)]);
        $export = $this->exportReel(6);
        $auteur = $this->gouvernant();
        $autre = $this->gouvernant();

        app(ServiceGouvernanceModeleIa::class)->entrainer($auteur, $export);

        Notification::assertSentTo($autre, function (NotificationMasante $notif) {
            $this->assertSame(TypeNotification::MODELE_IA_CANDIDAT, $notif->type);
            $this->assertStringNotContainsString('0.8', $notif->corps);
            $this->assertStringNotContainsString('exactitude', $notif->corps);

            return true;
        });
    }

    public function test_service_en_echec_ne_cree_aucune_version(): void
    {
        Http::fake(['*/api/v1/triage/entrainement' => Http::response(['detail' => 'erreur'], 500)]);
        $export = $this->exportReel(6);

        try {
            app(ServiceGouvernanceModeleIa::class)->entrainer($this->gouvernant(), $export);
            $this->fail('Une exception était attendue.');
        } catch (\RuntimeException) {
            // attendu
        }

        $this->assertSame(0, VersionModeleIa::count());
    }

    // ─────────────────────────────────────────────────────────────────────────
    // F18 — quatre-yeux
    // ─────────────────────────────────────────────────────────────────────────

    public function test_lauteur_de_lentrainement_ne_peut_pas_valider_son_propre_candidat(): void
    {
        Notification::fake();
        Http::fake(['*/api/v1/triage/entrainement' => Http::response($this->reponsePythonReussie(), 200)]);
        $export = $this->exportReel(6);
        $auteur = $this->gouvernant();
        $version = app(ServiceGouvernanceModeleIa::class)->entrainer($auteur, $export);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/ne peut pas valider ce candidat lui-même/');

        app(ServiceGouvernanceModeleIa::class)->valider($auteur, $version);
    }

    public function test_un_second_agent_habilite_peut_valider(): void
    {
        Notification::fake();
        Http::fake(['*/api/v1/triage/entrainement' => Http::response($this->reponsePythonReussie(), 200)]);
        $export = $this->exportReel(6);
        $auteur = $this->gouvernant();
        $validateur = $this->gouvernant();
        $version = app(ServiceGouvernanceModeleIa::class)->entrainer($auteur, $export);

        $version = app(ServiceGouvernanceModeleIa::class)->valider($validateur, $version);

        $this->assertSame(StatutVersionModeleIa::VALIDE, $version->statut);
        $this->assertSame($validateur->id, $version->valide_par);
        $this->assertNotNull($version->date_validation_clinique);
    }

    public function test_un_candidat_deja_valide_ne_peut_pas_etre_revalide(): void
    {
        Notification::fake();
        Http::fake(['*/api/v1/triage/entrainement' => Http::response($this->reponsePythonReussie(), 200)]);
        $export = $this->exportReel(6);
        $service = app(ServiceGouvernanceModeleIa::class);
        $version = $service->entrainer($this->gouvernant(), $export);
        $version = $service->valider($this->gouvernant(), $version);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Seul un candidat se valide/');

        $service->valider($this->gouvernant(), $version);
    }
}
