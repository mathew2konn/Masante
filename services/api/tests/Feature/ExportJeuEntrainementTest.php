<?php

namespace Tests\Feature;

use App\Models\JeuDonneesEntrainement;
use App\Models\MembreFamille;
use App\Models\Triage;
use App\Models\User;
use App\Services\Triage\ServiceExportJeuEntrainement;
use App\Services\Triage\ServiceRetourTriage;
use App\Services\Triage\ServiceValidationApprentissage;
use App\Support\RegistreRetourTriage;
use Database\Seeders\PortailRolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * P10c-3-i (F17/F20) — L'export anonymisant : là où la pseudonymisation devient une anonymisation.
 *
 * CE QUE CETTE SUITE PROTÈGE : `triage_id` et toute identité disparaissent à l'export (F20) ; l'âge
 * est généralisé en bande ; seules les lignes VALIDÉES entrent, y compris tous les retours révisés
 * sur un même triage (F13) ; l'habilitation fait autorité dans le service, pas seulement au routeur.
 */
class ExportJeuEntrainementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PortailRolesSeeder::class);
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

    /** @return array{0: User, 1: MembreFamille} */
    private function famille(): array
    {
        $parent = User::factory()->create();

        return [$parent, MembreFamille::factory()->for($parent)->create()];
    }

    private function triage(MembreFamille $membre, ?int $age = 34, ?string $sexe = 'F', ?int $scoreAntecedents = 5): Triage
    {
        return Triage::create([
            'membre_id' => $membre->id,
            'patient_age' => $age,
            'patient_sexe' => $sexe,
            'symptomes_json' => [['id' => 12, 'nom' => 'Fièvre', 'poids' => 20]],
            'reponses_json' => [],
            'score_severite' => 42,
            'score_antecedents' => $scoreAntecedents,
            'niveau' => 'modere',
            'recommandation_texte' => 'Consultez un médecin.',
        ]);
    }

    private function ligneValidee(User $soignant, User $reviseur, MembreFamille $membre, ?int $age = 34, string $label = RegistreRetourTriage::ADAPTEE): JeuDonneesEntrainement
    {
        $triage = $this->triage($membre, $age);
        app(ServiceRetourTriage::class)->enregistrer(
            $soignant, $membre, $triage, $label, $label === RegistreRetourTriage::ADAPTEE ? null : 'Motif.'
        );
        $ligne = JeuDonneesEntrainement::where('triage_id', $triage->id)->sole();
        app(ServiceValidationApprentissage::class)->valider($reviseur, $ligne);

        return $ligne;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Habilitation — vérifiée EN SERVICE
    // ─────────────────────────────────────────────────────────────────────────

    public function test_un_compte_non_habilite_ne_peut_pas_exporter(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/ia_triage\.valider/');

        app(ServiceExportJeuEntrainement::class)->exporter(User::factory()->create(), 'CI');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // L'anonymisation — F20
    // ─────────────────────────────────────────────────────────────────────────

    public function test_lexport_ne_porte_aucune_identite(): void
    {
        [, $membre] = $this->famille();
        $this->ligneValidee($this->soignant(), $this->reviseur(), $membre);

        $export = app(ServiceExportJeuEntrainement::class)->exporter($this->gouvernant(), 'CI');

        $this->assertSame(1, $export->nb_lignes);
        foreach ($export->instantane_json as $ligne) {
            $colonnes = array_keys($ligne);
            foreach (['triage_id', 'membre_id', 'user_id', 'patient_nom', 'nis', 'id'] as $champInterdit) {
                $this->assertNotContains($champInterdit, $colonnes);
            }
        }
    }

    public function test_lage_est_generalise_en_bande_jamais_expose_exact(): void
    {
        [, $membre] = $this->famille();
        $this->ligneValidee($this->soignant(), $this->reviseur(), $membre, age: 34);

        $export = app(ServiceExportJeuEntrainement::class)->exporter($this->gouvernant(), 'CI');

        $this->assertSame('25-44', $export->instantane_json[0]['bande_age']);
        $this->assertArrayNotHasKey('age', $export->instantane_json[0]);
    }

    #[DataProvider('bandesAge')]
    public function test_bande_pour_couvre_les_bornes_sans_trou(int $age, ?string $bandeAttendue): void
    {
        $bande = app(ServiceExportJeuEntrainement::class)->bandePour($age);
        $this->assertSame($bandeAttendue, $bande);
    }

    public static function bandesAge(): array
    {
        return [
            'nourrisson, borne basse' => [0, '0-1'],
            'nourrisson, borne haute' => [1, '0-1'],
            'jonction 2-4' => [2, '2-4'],
            'jonction 4/5' => [4, '2-4'],
            'jonction 5' => [5, '5-14'],
            'adulte jeune' => [24, '15-24'],
            'jonction 24/25' => [25, '25-44'],
            'senior' => [70, '65+'],
        ];
    }

    public function test_bande_pour_age_inconnu_rend_null_jamais_une_bande_devinee(): void
    {
        $this->assertNull(app(ServiceExportJeuEntrainement::class)->bandePour(null));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // k estimé — calculé, jamais bloquant
    // ─────────────────────────────────────────────────────────────────────────

    public function test_k_estime_est_la_taille_du_plus_petit_groupe(): void
    {
        $lignes = [
            ['bande_age' => '25-44', 'sexe' => 'F', 'annee_mois' => '2026-08'],
            ['bande_age' => '25-44', 'sexe' => 'F', 'annee_mois' => '2026-08'],
            ['bande_age' => '65+', 'sexe' => 'M', 'annee_mois' => '2026-08'],
        ];

        $this->assertSame(1, app(ServiceExportJeuEntrainement::class)->kEstime($lignes));
    }

    public function test_k_estime_export_vide_rend_null(): void
    {
        $this->assertNull(app(ServiceExportJeuEntrainement::class)->kEstime([]));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Seules les lignes VALIDÉES entrent — y compris tous les retours révisés (F13)
    // ─────────────────────────────────────────────────────────────────────────

    public function test_seules_les_lignes_validees_entrent_a_lexport(): void
    {
        [, $membre] = $this->famille();
        $soignant = $this->soignant();
        $reviseur = $this->reviseur();

        $this->ligneValidee($soignant, $reviseur, $membre); // validée

        $triageRejete = $this->triage($membre);
        app(ServiceRetourTriage::class)->enregistrer($soignant, $membre, $triageRejete, RegistreRetourTriage::ADAPTEE);
        app(ServiceValidationApprentissage::class)->rejeter(
            $reviseur, JeuDonneesEntrainement::where('triage_id', $triageRejete->id)->sole(), 'Incohérent.'
        );

        $triageEnAttente = $this->triage($membre);
        app(ServiceRetourTriage::class)->enregistrer($soignant, $membre, $triageEnAttente, RegistreRetourTriage::ADAPTEE);

        $export = app(ServiceExportJeuEntrainement::class)->exporter($this->gouvernant(), 'CI');

        $this->assertSame(1, $export->nb_lignes);
    }

    public function test_un_retour_revise_produit_deux_lignes_toutes_deux_exportees(): void
    {
        [, $membre] = $this->famille();
        $soignant = $this->soignant();
        $reviseur = $this->reviseur();
        $triage = $this->triage($membre);

        app(ServiceRetourTriage::class)->enregistrer($soignant, $membre, $triage, RegistreRetourTriage::ADAPTEE);
        app(ServiceRetourTriage::class)->enregistrer($soignant, $membre, $triage, RegistreRetourTriage::SOUS_TRIAGE, 'Ravisé.');

        foreach (JeuDonneesEntrainement::where('triage_id', $triage->id)->get() as $ligne) {
            app(ServiceValidationApprentissage::class)->valider($reviseur, $ligne);
        }

        $export = app(ServiceExportJeuEntrainement::class)->exporter($this->gouvernant(), 'CI');

        // F13 : les DEUX lignes validées entrent, jamais un tri arbitraire de laquelle « fait foi ».
        $this->assertSame(2, $export->nb_lignes);
        $labels = array_column($export->instantane_json, 'label');
        sort($labels);
        $this->assertSame([RegistreRetourTriage::ADAPTEE, RegistreRetourTriage::SOUS_TRIAGE], $labels);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // `score_antecedents` — F14, la feature déjà gouvernée
    // ─────────────────────────────────────────────────────────────────────────

    public function test_score_antecedents_traverse_du_triage_a_lexport(): void
    {
        [, $membre] = $this->famille();
        $this->ligneValidee($this->soignant(), $this->reviseur(), $membre);

        $export = app(ServiceExportJeuEntrainement::class)->exporter($this->gouvernant(), 'CI');

        $this->assertSame(5, $export->instantane_json[0]['score_antecedents']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Numérotation — par pays
    // ─────────────────────────────────────────────────────────────────────────

    public function test_deux_exports_successifs_sont_numerotes(): void
    {
        [, $membre] = $this->famille();
        $this->ligneValidee($this->soignant(), $this->reviseur(), $membre);
        $gouvernant = $this->gouvernant();

        $e1 = app(ServiceExportJeuEntrainement::class)->exporter($gouvernant, 'CI');
        $e2 = app(ServiceExportJeuEntrainement::class)->exporter($gouvernant, 'CI');

        $this->assertSame(1, $e1->numero_export);
        $this->assertSame(2, $e2->numero_export);
    }
}
