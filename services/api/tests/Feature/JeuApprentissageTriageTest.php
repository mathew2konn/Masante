<?php

namespace Tests\Feature;

use App\Models\JeuDonneesEntrainement;
use App\Models\MembreFamille;
use App\Models\Triage;
use App\Models\TriageConstante;
use App\Models\TriageReponse;
use App\Models\User;
use App\Services\Triage\ServiceRetourTriage;
use App\Services\Triage\ServiceValidationApprentissage;
use App\Support\RegistreRetourTriage;
use Database\Seeders\PortailRolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * P10c-2-i (F4) — Le jeu d'apprentissage §5.5.4/§7.2 : pseudonymisé, et validé avant export.
 *
 * CE QUE CETTE SUITE PROTÈGE : une ligne naît à chaque retour, sans AUCUNE identité ; et une ligne
 * non validée par un médecin habilité n'entre JAMAIS dans {@see ServiceValidationApprentissage::pretsPourExport()}.
 */
class JeuApprentissageTriageTest extends TestCase
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

    /** @return array{0: User, 1: MembreFamille} */
    private function famille(): array
    {
        $parent = User::factory()->create();

        return [$parent, MembreFamille::factory()->for($parent)->create()];
    }

    private function triageAvecConstantes(MembreFamille $membre): Triage
    {
        $triage = Triage::create([
            'membre_id' => $membre->id,
            'patient_age' => 34,
            'patient_sexe' => 'F',
            'symptomes_json' => [12, 47],
            'reponses_json' => [],
            'score_severite' => 42,
            'niveau' => 'modere',
            'recommandation_texte' => 'Consultez un médecin.',
        ]);

        TriageConstante::create([
            'triage_id' => $triage->id, 'type_mesure' => 'temperature', 'valeur' => 38.9,
            'unite' => '°C', 'origine' => 'saisie', 'referentiel_version' => 1,
        ]);
        TriageReponse::create([
            'triage_id' => $triage->id, 'question_cle' => 'duree_jours',
            'question_libelle' => 'Depuis combien de jours ?', 'valeur' => '2',
        ]);
        TriageReponse::create([
            'triage_id' => $triage->id, 'question_cle' => 'grossesse',
            'question_libelle' => 'Êtes-vous enceinte ?', 'valeur' => 'non',
        ]);

        return $triage;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Une ligne naît à chaque retour, sans identité
    // ─────────────────────────────────────────────────────────────────────────

    public function test_un_retour_cree_une_ligne_du_jeu_sans_aucune_identite(): void
    {
        [, $membre] = $this->famille();
        $triage = $this->triageAvecConstantes($membre);

        app(ServiceRetourTriage::class)->enregistrer(
            $this->soignant(), $membre, $triage, RegistreRetourTriage::SOUS_TRIAGE, 'Douleur thoracique sous-évaluée.'
        );

        $ligne = JeuDonneesEntrainement::where('triage_id', $triage->id)->sole();

        $this->assertSame(34, $ligne->age);
        $this->assertSame('F', $ligne->sexe);
        $this->assertSame([12, 47], $ligne->symptomes_json);
        $this->assertSame(38.9, (float) $ligne->temperature);
        $this->assertNull($ligne->pouls);
        $this->assertSame(2, $ligne->duree_jours);
        $this->assertFalse($ligne->grossesse);
        $this->assertSame('modere', $ligne->niveau_protocole);
        $this->assertSame(RegistreRetourTriage::SOUS_TRIAGE, $ligne->label);

        // Aucune colonne d'identité n'existe sur ce modèle — la garantie tient au SCHÉMA, pas
        // seulement au service : une identité ne pourrait pas s'y écrire même par erreur.
        $colonnes = array_keys($ligne->getAttributes());
        foreach (['patient_nom', 'user_id', 'membre_id', 'nis'] as $champInterdit) {
            $this->assertNotContains($champInterdit, $colonnes);
        }
    }

    public function test_un_second_retour_cree_une_seconde_ligne_jamais_une_reecriture(): void
    {
        [, $membre] = $this->famille();
        $triage = $this->triageAvecConstantes($membre);
        $service = app(ServiceRetourTriage::class);

        $service->enregistrer($this->soignant(), $membre, $triage, RegistreRetourTriage::ADAPTEE);
        $service->enregistrer($this->soignant(), $membre, $triage, RegistreRetourTriage::SOUS_TRIAGE, 'Ravisé.');

        $this->assertSame(2, JeuDonneesEntrainement::where('triage_id', $triage->id)->count());
    }

    // ─────────────────────────────────────────────────────────────────────────
    // La revue médicale — F4
    // ─────────────────────────────────────────────────────────────────────────

    public function test_un_reviseur_habilite_valide_une_ligne(): void
    {
        [, $membre] = $this->famille();
        $triage = $this->triageAvecConstantes($membre);
        app(ServiceRetourTriage::class)->enregistrer($this->soignant(), $membre, $triage, RegistreRetourTriage::ADAPTEE);
        $ligne = JeuDonneesEntrainement::where('triage_id', $triage->id)->sole();

        $validation = app(ServiceValidationApprentissage::class)->valider($this->reviseur(), $ligne);

        $this->assertSame('valide', $validation->statut);
        $this->assertNull($validation->motif);
    }

    public function test_un_compte_non_habilite_ne_peut_pas_valider(): void
    {
        [, $membre] = $this->famille();
        $triage = $this->triageAvecConstantes($membre);
        app(ServiceRetourTriage::class)->enregistrer($this->soignant(), $membre, $triage, RegistreRetourTriage::ADAPTEE);
        $ligne = JeuDonneesEntrainement::where('triage_id', $triage->id)->sole();

        $this->expectException(\RuntimeException::class);
        app(ServiceValidationApprentissage::class)->valider(User::factory()->create(), $ligne);
    }

    public function test_un_rejet_sans_motif_est_refuse(): void
    {
        [, $membre] = $this->famille();
        $triage = $this->triageAvecConstantes($membre);
        app(ServiceRetourTriage::class)->enregistrer($this->soignant(), $membre, $triage, RegistreRetourTriage::ADAPTEE);
        $ligne = JeuDonneesEntrainement::where('triage_id', $triage->id)->sole();

        $this->expectException(\RuntimeException::class);
        app(ServiceValidationApprentissage::class)->rejeter($this->reviseur(), $ligne, '');
    }

    public function test_une_ligne_deja_decidee_ne_peut_pas_etre_redecidee(): void
    {
        [, $membre] = $this->famille();
        $triage = $this->triageAvecConstantes($membre);
        app(ServiceRetourTriage::class)->enregistrer($this->soignant(), $membre, $triage, RegistreRetourTriage::ADAPTEE);
        $ligne = JeuDonneesEntrainement::where('triage_id', $triage->id)->sole();
        $service = app(ServiceValidationApprentissage::class);
        $service->valider($this->reviseur(), $ligne);

        $this->expectException(\RuntimeException::class);
        $service->rejeter($this->reviseur(), $ligne, 'Trop tard.');
    }

    public function test_pretsPourExport_ne_rend_que_les_lignes_validees(): void
    {
        [, $membre] = $this->famille();
        $service = app(ServiceRetourTriage::class);
        $validation = app(ServiceValidationApprentissage::class);
        $reviseur = $this->reviseur();

        $triageValide = $this->triageAvecConstantes($membre);
        $service->enregistrer($this->soignant(), $membre, $triageValide, RegistreRetourTriage::ADAPTEE);
        $validation->valider($reviseur, JeuDonneesEntrainement::where('triage_id', $triageValide->id)->sole());

        $triageRejete = $this->triageAvecConstantes($membre);
        $service->enregistrer($this->soignant(), $membre, $triageRejete, RegistreRetourTriage::ADAPTEE);
        $validation->rejeter($reviseur, JeuDonneesEntrainement::where('triage_id', $triageRejete->id)->sole(), 'Incohérent.');

        $triageEnAttente = $this->triageAvecConstantes($membre);
        $service->enregistrer($this->soignant(), $membre, $triageEnAttente, RegistreRetourTriage::ADAPTEE);

        $prets = $validation->pretsPourExport()->pluck('triage_id')->all();

        $this->assertSame([$triageValide->id], $prets);
    }
}
