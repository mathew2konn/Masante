<?php

namespace Tests\Feature;

use App\Models\Medicament;
use App\Models\PrixPharmacie;
use App\Models\StructureSanitaire;
use App\Models\User;
use App\Services\RecuOcrService;
use Database\Seeders\PortailRolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Module 5 / 5.8 — Comparateur de prix (FN7) et ruptures (FN8), étape A.
 *
 * Le problème n'est pas technique, il est épistémique : un prix rapporté par un inconnu n'a aucune
 * garantie. Ce qui doit donc tenir : le prix ABSURDE est refusé AVANT d'entrer en base ; le
 * plaisantin isolé ne déplace pas l'affichage (médiane, pas dernier relevé) ; le pharmacien fait
 * autorité sur SA pharmacie ; un réapprovisionnement annule une rupture ; et un relevé anonyme est
 * impossible (signaler exige un compte).
 */
class PrixMedicamentTest extends TestCase
{
    use RefreshDatabase;

    private Medicament $paracetamol;

    private StructureSanitaire $pharmacie;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PortailRolesSeeder::class);

        $this->paracetamol = Medicament::create([
            'nom_generique' => 'Paracétamol 500 mg', 'categorie' => 'Antalgique',
            'prix_reference_cfa' => 300, 'ordonnance_requise' => false,
        ]);

        $this->pharmacie = StructureSanitaire::create([
            'nom' => 'Pharmacie de Cocody', 'type' => 'pharmacie', 'adresse' => 'Abidjan',
            'commune' => 'Cocody', 'latitude' => 5.35, 'longitude' => -3.98, 'actif' => true,
        ]);

        $this->user = User::factory()->create();
    }

    private function relever(int $prix, ?User $user = null): \Illuminate\Testing\TestResponse
    {
        Sanctum::actingAs($user ?? $this->user);

        return $this->postJson("/api/v1/medicaments/{$this->paracetamol->id}/prix", [
            'structure_id' => $this->pharmacie->id,
            'prix_cfa'     => $prix,
        ]);
    }

    public function test_un_prix_absurde_est_refuse_avant_d_entrer_en_base(): void
    {
        // Référence CENAME 300 F ; bornes 0,2× à 5× → 60 à 1 500 F. 50 000 F est une faute de frappe.
        $this->relever(50000)->assertStatus(422)->assertJsonValidationErrors('prix_cfa');
        $this->relever(10)->assertStatus(422)->assertJsonValidationErrors('prix_cfa');

        // Un prix élevé mais plausible (officine privée) passe : on n'écarte que l'absurde.
        $this->relever(1400)->assertCreated();

        $this->assertSame(1, PrixPharmacie::count());
    }

    public function test_signaler_un_prix_exige_un_compte(): void
    {
        $this->postJson("/api/v1/medicaments/{$this->paracetamol->id}/prix", [
            'structure_id' => $this->pharmacie->id, 'prix_cfa' => 400,
        ])->assertUnauthorized();

        // Lire, en revanche, est public : une information de prix n'a d'utilité que diffusée.
        $this->getJson("/api/v1/medicaments/{$this->paracetamol->id}/prix")->assertOk();
        $this->getJson('/api/v1/medicaments?q=Paracétamol')->assertOk()->assertJsonCount(1, 'medicaments');
    }

    public function test_le_prix_affiche_est_la_mediane_pas_le_dernier_relevé(): void
    {
        $this->relever(400)->assertCreated();
        $this->relever(450, User::factory()->create())->assertCreated();
        // Le dernier relevé est extrême : il ne doit pas devenir la vérité affichée.
        $this->relever(1400, User::factory()->create())->assertCreated();

        $this->getJson("/api/v1/medicaments/{$this->paracetamol->id}/prix")
            ->assertOk()
            ->assertJsonPath('offres.0.prix_cfa', 450)      // médiane de 400/450/1400
            ->assertJsonPath('offres.0.source', 'crowdsource_patient')
            ->assertJsonPath('offres.0.releves', 3);
    }

    public function test_le_pharmacien_fait_autorite_sur_sa_pharmacie(): void
    {
        $this->relever(400)->assertCreated();

        $gestionnaire = User::factory()->create(['structure_id' => $this->pharmacie->id]);
        $gestionnaire->assignRole('gestionnaire_etablissement');

        $this->actingAs($gestionnaire)
            // B3-b — l'URL a changé : cet écran déclare un PRIX, il ne gère aucun stock, et son
            // ancien nom faisait chercher l'inventaire au mauvais endroit. Le comportement
            // testé ici est INCHANGÉ ; seule l'adresse a suivi le renommage.
            ->post("/portail/prix-officine/{$this->paracetamol->id}", ['etat' => 'en_stock', 'prix_cfa' => 350])
            ->assertRedirect();

        // Sa déclaration prime sur le relevé du patient, quelle que soit la chronologie.
        $this->getJson("/api/v1/medicaments/{$this->paracetamol->id}/prix")
            ->assertJsonPath('offres.0.prix_cfa', 350)
            ->assertJsonPath('offres.0.source', 'pharmacie_portail');
    }

    public function test_une_rupture_remonte_a_la_vue_agregee_puis_disparait_au_reapprovisionnement(): void
    {
        Sanctum::actingAs($this->user);

        $this->postJson("/api/v1/medicaments/{$this->paracetamol->id}/rupture", [
            'structure_id' => $this->pharmacie->id,
        ])->assertCreated();

        // Une rupture n'a pas de prix : on ne relève pas le prix d'un médicament absent (écart CdC).
        $this->assertNull(PrixPharmacie::first()->prix_cfa);

        $this->getJson('/api/v1/ruptures?commune=Cocody')
            ->assertOk()
            ->assertJsonCount(1, 'ruptures')
            ->assertJsonPath('ruptures.0.nb_pharmacies', 1);

        // Réapprovisionnement : un relevé de prix plus récent annule la rupture (dernier mot).
        $this->relever(400)->assertCreated();

        $this->getJson('/api/v1/ruptures')->assertOk()->assertJsonCount(0, 'ruptures');
    }

    public function test_un_prix_ne_se_releve_que_dans_une_pharmacie(): void
    {
        $chu = StructureSanitaire::create([
            'nom' => 'CHU de Cocody', 'type' => 'chu', 'adresse' => 'Abidjan', 'commune' => 'Cocody',
            'latitude' => 5.35, 'longitude' => -3.98, 'actif' => true,
        ]);

        Sanctum::actingAs($this->user);
        $this->postJson("/api/v1/medicaments/{$this->paracetamol->id}/prix", [
            'structure_id' => $chu->id, 'prix_cfa' => 400,
        ])->assertStatus(422)->assertJsonValidationErrors('structure_id');
    }

    public function test_le_comparateur_suggere_le_generique_moins_cher(): void
    {
        // Même DCI, marque plus chère : c'est exactement le cas d'usage de FN7.
        $doliprane = Medicament::create([
            'nom_generique' => 'Paracétamol 500 mg', 'nom_commercial' => 'Doliprane',
            'categorie' => 'Antalgique', 'prix_reference_cfa' => 1200, 'disponible_generique' => true,
        ]);

        $this->getJson("/api/v1/medicaments/{$doliprane->id}/prix")
            ->assertOk()
            ->assertJsonCount(1, 'generiques')
            ->assertJsonPath('generiques.0.id', $this->paracetamol->id)
            ->assertJsonPath('generiques.0.prix_reference_cfa', 300);

        // Et l'inverse n'a pas lieu : le générique n'a rien de moins cher à proposer.
        $this->getJson("/api/v1/medicaments/{$this->paracetamol->id}/prix")
            ->assertJsonCount(0, 'generiques');
    }

    public function test_un_releve_perime_n_est_plus_affiche(): void
    {
        $this->relever(400)->assertCreated();

        // Un prix sans date ne vaut rien : au-delà de la fenêtre de fraîcheur, on se tait.
        PrixPharmacie::first()->update(['date_mise_a_jour' => now()->subDays(120)]);

        $this->getJson("/api/v1/medicaments/{$this->paracetamol->id}/prix")
            ->assertOk()
            ->assertJsonCount(0, 'offres');
    }

    public function test_l_ocr_propose_des_montants_et_ne_conserve_pas_le_recu(): void
    {
        $ocr = app(RecuOcrService::class);

        if (! $ocr->estDisponible()) {
            $this->markTestSkipped('Tesseract n\'est pas installé sur cette machine.');
        }

        $image = $this->recuFactice();

        Sanctum::actingAs($this->user);
        $reponse = $this->post('/api/v1/recus/lecture', ['recu' => $image], ['Accept' => 'application/json'])
            ->assertOk();

        // L'OCR PROPOSE : il ne crée aucun relevé. Le patient choisit, corrige et confirme.
        $this->assertContains(2500, $reponse->json('montants'));
        $this->assertSame(0, PrixPharmacie::count());

        // La photo du reçu (donnée de santé) n'est jamais conservée : rien dans le stockage.
        $this->assertEmpty(glob(storage_path('app/**/recu*')));
    }

    /** Faux ticket de caisse, dessiné à la volée (aucune image versionnée dans le dépôt). */
    private function recuFactice(): UploadedFile
    {
        $im = imagecreatetruecolor(700, 260);
        imagefill($im, 0, 0, imagecolorallocate($im, 255, 255, 255));
        $noir = imagecolorallocate($im, 0, 0, 0);

        $y = 20;
        foreach (['PHARMACIE DE COCODY', 'AMOXICILLINE 500mg  2500 F', 'TOTAL A PAYER  2500 FCFA'] as $ligne) {
            imagestring($im, 5, 20, $y, $ligne, $noir);
            $y += 60;
        }

        $chemin = tempnam(sys_get_temp_dir(), 'recu').'.png';
        imagepng($im, $chemin);
        imagedestroy($im);

        return new UploadedFile($chemin, 'recu.png', 'image/png', null, true);
    }
}
