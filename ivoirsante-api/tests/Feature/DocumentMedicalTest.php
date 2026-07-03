<?php

namespace Tests\Feature;

use App\Models\MembreFamille;
use App\Models\User;
use App\Services\DocumentStorageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * F2.10 — Documents médicaux importés. On couvre la chaîne de sécurité : chiffrement au repos,
 * liste blanche MIME (validation serveur, pas l'extension), verrou de téléchargement par statut
 * antivirus, soft-delete avec rétention du blob, audit de l'auteur (serveur), et isolation anti-IDOR.
 */
class DocumentMedicalTest extends TestCase
{
    use RefreshDatabase;

    /** PDF minimal : contenu réel pour que finfo détecte application/pdf (liste blanche). */
    private const PDF = "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF";

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(DocumentStorageService::DISK);
        config(['masante.antivirus.enabled' => false]); // stub dev : scan synchrone → 'sain'
    }

    private function membreConnecte(): MembreFamille
    {
        $user = User::factory()->create();
        $membre = MembreFamille::factory()->for($user)->create();
        Sanctum::actingAs($user);

        return $membre;
    }

    private function pdf(string $nom = 'resultat.pdf'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($nom, self::PDF);
    }

    private function importer(MembreFamille $membre, array $extra = []): \Illuminate\Testing\TestResponse
    {
        return $this->post("/api/v1/membres/{$membre->id}/documents", array_merge([
            'fichier'   => $this->pdf(),
            'categorie' => 'resultat_labo',
        ], $extra));
    }

    public function test_import_chiffre_le_blob_et_marque_sain(): void
    {
        $membre = $this->membreConnecte();

        $reponse = $this->importer($membre, ['titre' => 'Analyse de sang'])
            ->assertCreated()
            ->assertJsonPath('item.statut_antivirus', 'sain')
            ->assertJsonPath('item.mime_type', 'application/pdf')
            ->assertJsonPath('item.titre', 'Analyse de sang');

        $chemin = $reponse->json('item.fichier_url');

        // Le blob existe, n'est PAS en clair, et se déchiffre vers le contenu d'origine.
        Storage::disk(DocumentStorageService::DISK)->assertExists($chemin);
        $brut = Storage::disk(DocumentStorageService::DISK)->get($chemin);
        $this->assertNotSame(self::PDF, $brut, 'Le blob doit être chiffré au repos.');
        $this->assertSame(self::PDF, Crypt::decryptString($brut));

        $this->assertDatabaseHas('documents_medicaux', [
            'id'          => $reponse->json('item.id'),
            'hash_sha256' => hash('sha256', self::PDF),
        ]);
    }

    public function test_auteur_de_l_import_est_injecte_cote_serveur(): void
    {
        $membre = $this->membreConnecte();

        // Le client tente d'usurper l'auteur et le membre : ignorés (hors $fillable / non validés).
        $reponse = $this->importer($membre, [
            'uploaded_by_user_id' => 999,
            'membre_id'           => 999,
        ])->assertCreated();

        $this->assertDatabaseHas('documents_medicaux', [
            'id'                  => $reponse->json('item.id'),
            'membre_id'           => $membre->id,
            'uploaded_by_user_id' => $membre->user_id,
        ]);
    }

    public function test_type_hors_liste_blanche_est_refuse(): void
    {
        $membre = $this->membreConnecte();

        $html = UploadedFile::fake()->createWithContent('facture.html', '<html><body>x</body></html>');

        $this->post("/api/v1/membres/{$membre->id}/documents", [
            'fichier'   => $html,
            'categorie' => 'autre',
        ])->assertStatus(422)->assertJsonValidationErrors('fichier');

        $this->assertDatabaseCount('documents_medicaux', 0);
    }

    public function test_categorie_hors_enum_est_refusee(): void
    {
        $membre = $this->membreConnecte();

        $this->importer($membre, ['categorie' => 'inventee'])
            ->assertStatus(422)->assertJsonValidationErrors('categorie');
    }

    public function test_telechargement_rend_le_contenu_dechiffre(): void
    {
        $membre = $this->membreConnecte();
        $id = $this->importer($membre)->json('item.id');

        $reponse = $this->get("/api/v1/membres/{$membre->id}/documents/{$id}")->assertOk();

        $this->assertSame(self::PDF, $reponse->streamedContent());
        $this->assertSame('application/pdf', $reponse->headers->get('content-type'));
    }

    public function test_telechargement_bloque_tant_que_non_sain(): void
    {
        $membre = $this->membreConnecte();
        $id = $this->importer($membre)->json('item.id');

        foreach (['en_attente', 'infecte'] as $statut) {
            $membre->documentsMedicaux()->findOrFail($id)->update(['statut_antivirus' => $statut]);

            $this->getJson("/api/v1/membres/{$membre->id}/documents/{$id}")->assertStatus(423);
        }
    }

    public function test_suppression_est_un_soft_delete_le_blob_est_conserve(): void
    {
        $membre = $this->membreConnecte();
        $reponse = $this->importer($membre);
        $id = $reponse->json('item.id');
        $chemin = $reponse->json('item.fichier_url');

        $this->deleteJson("/api/v1/membres/{$membre->id}/documents/{$id}")->assertOk();

        $this->getJson("/api/v1/membres/{$membre->id}/documents")->assertOk()->assertJsonCount(0, 'items');
        $this->assertSoftDeleted('documents_medicaux', ['id' => $id]);
        Storage::disk(DocumentStorageService::DISK)->assertExists($chemin); // rétention médicale
    }

    public function test_isolation_idor(): void
    {
        $membre = MembreFamille::factory()->for(User::factory())->create();
        Sanctum::actingAs(User::factory()->create());

        $this->getJson("/api/v1/membres/{$membre->id}/documents")->assertForbidden();
        $this->post("/api/v1/membres/{$membre->id}/documents", [
            'fichier'   => $this->pdf(),
            'categorie' => 'autre',
        ])->assertForbidden();

        $this->assertDatabaseCount('documents_medicaux', 0);
    }
}
