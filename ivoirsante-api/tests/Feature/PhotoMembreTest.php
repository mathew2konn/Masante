<?php

namespace Tests\Feature;

use App\Models\MembreFamille;
use App\Models\User;
use App\Services\PhotoMembreService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Profil — Photo de profil d'un membre. Vérifie le chiffrement au repos, l'exposition par
 * `a_photo` (jamais le chemin interne), le service déchiffré, la liste blanche MIME et l'anti-IDOR.
 */
class PhotoMembreTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(PhotoMembreService::DISK);
    }

    public function test_upload_chiffre_et_expose_a_photo_sans_chemin(): void
    {
        $user = User::factory()->create();
        $membre = MembreFamille::factory()->for($user)->create();
        Sanctum::actingAs($user);

        $this->post("/api/v1/membres/{$membre->id}/photo", [
            'photo' => UploadedFile::fake()->image('avatar.jpg', 300, 300),
        ], ['Accept' => 'application/json'])->assertOk()->assertJsonPath('membre.a_photo', true);

        // Le chemin interne n'est jamais sérialisé ; seul `a_photo` l'est.
        $this->getJson("/api/v1/membres/{$membre->id}")
            ->assertOk()
            ->assertJsonMissingPath('membre.photo_url')
            ->assertJsonPath('membre.a_photo', true);

        // Chiffré au repos : le blob stocké n'est pas l'image brute, mais se déchiffre en une image.
        $chemin = $membre->fresh()->photo_url;
        $this->assertNotNull($chemin);
        $brut = Storage::disk(PhotoMembreService::DISK)->get($chemin);
        $clair = Crypt::decryptString($brut);
        $this->assertNotSame($brut, $clair);
        $this->assertSame('image/jpeg', (new \finfo(FILEINFO_MIME_TYPE))->buffer($clair));
    }

    public function test_get_sert_l_image_dechiffree(): void
    {
        $user = User::factory()->create();
        $membre = MembreFamille::factory()->for($user)->create();
        Sanctum::actingAs($user);

        $this->post("/api/v1/membres/{$membre->id}/photo", [
            'photo' => UploadedFile::fake()->image('avatar.jpg', 200, 200),
        ], ['Accept' => 'application/json'])->assertOk();

        $this->get("/api/v1/membres/{$membre->id}/photo")
            ->assertOk()
            ->assertHeader('Content-Type', 'image/jpeg');
    }

    public function test_get_404_sans_photo(): void
    {
        $user = User::factory()->create();
        $membre = MembreFamille::factory()->for($user)->create();
        Sanctum::actingAs($user);

        $this->getJson("/api/v1/membres/{$membre->id}/photo")->assertNotFound();
        $this->getJson("/api/v1/membres/{$membre->id}")->assertJsonPath('membre.a_photo', false);
    }

    public function test_type_non_image_rejete_422(): void
    {
        $user = User::factory()->create();
        $membre = MembreFamille::factory()->for($user)->create();
        Sanctum::actingAs($user);

        $this->post("/api/v1/membres/{$membre->id}/photo", [
            'photo' => UploadedFile::fake()->create('notes.txt', 10, 'text/plain'),
        ], ['Accept' => 'application/json'])->assertStatus(422)->assertJsonValidationErrors('photo');

        $this->assertNull($membre->fresh()->photo_url);
    }

    public function test_suppression_photo(): void
    {
        $user = User::factory()->create();
        $membre = MembreFamille::factory()->for($user)->create();
        Sanctum::actingAs($user);

        $this->post("/api/v1/membres/{$membre->id}/photo", [
            'photo' => UploadedFile::fake()->image('avatar.jpg'),
        ], ['Accept' => 'application/json'])->assertOk();

        $chemin = $membre->fresh()->photo_url;

        $this->deleteJson("/api/v1/membres/{$membre->id}/photo")
            ->assertOk()->assertJsonPath('membre.a_photo', false);

        $this->assertNull($membre->fresh()->photo_url);
        Storage::disk(PhotoMembreService::DISK)->assertMissing($chemin);
        $this->getJson("/api/v1/membres/{$membre->id}/photo")->assertNotFound();
    }

    public function test_isolation_idor(): void
    {
        $proprietaire = User::factory()->create();
        $membre = MembreFamille::factory()->for($proprietaire)->create();

        Sanctum::actingAs(User::factory()->create()); // autre compte

        $this->post("/api/v1/membres/{$membre->id}/photo", [
            'photo' => UploadedFile::fake()->image('avatar.jpg'),
        ], ['Accept' => 'application/json'])->assertForbidden();
        $this->getJson("/api/v1/membres/{$membre->id}/photo")->assertForbidden();
        $this->deleteJson("/api/v1/membres/{$membre->id}/photo")->assertForbidden();
    }
}
