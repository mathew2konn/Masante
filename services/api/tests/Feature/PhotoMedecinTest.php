<?php

namespace Tests\Feature;

use App\Models\Medecin;
use App\Models\ServiceEtablissement;
use App\Models\StructureSanitaire;
use App\Models\User;
use App\Services\Professionnel\PhotoMedecin;
use Database\Seeders\PortailRolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * B1-b (D5) — Photo de profil d'un médecin de l'annuaire.
 *
 * Patron ALLÉGÉ de `ImagesEtablissementTest` (P6.4c) : même double crible sur la nature du
 * fichier (dont le vecteur du PNG à zéro pixel, qui avait trouvé un vrai trou en P6.4c), mais
 * UNE seule photo par praticien — pas de catégorie, pas de quota, pas de table séparée.
 *
 * L'HABILITATION N'EST PAS TESTÉE AU NIVEAU DU SERVICE, ET C'EST DÉLIBÉRÉ : contrairement à
 * `ImagesEtablissement`, `PhotoMedecin` ne la revérifie pas — elle vit déjà dans le groupe de
 * routes (`permission:medecin.manage`) et dans `Portail\MedecinController::fichePossedee()`. Les
 * vecteurs d'habilitation sont donc au niveau ROUTE (HTTP), pas service.
 */
class PhotoMedecinTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PortailRolesSeeder::class);
        Storage::fake(PhotoMedecin::DISK);
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // Fixtures
    // ─────────────────────────────────────────────────────────────────────────────

    private function structure(string $nom = 'CHU de Treichville'): StructureSanitaire
    {
        return StructureSanitaire::create([
            'nom' => $nom, 'type' => 'chu', 'adresse' => 'Treichville',
            'commune' => 'Treichville', 'latitude' => 5.29, 'longitude' => -4.00, 'actif' => true,
        ]);
    }

    private function service(StructureSanitaire $structure): ServiceEtablissement
    {
        return ServiceEtablissement::create([
            'structure_id' => $structure->id, 'nom_service' => 'Cardiologie',
            'specialite' => 'cardiologie', 'actif' => true,
        ]);
    }

    private function medecin(StructureSanitaire $structure, ServiceEtablissement $svc): Medecin
    {
        return Medecin::create([
            'structure_id' => $structure->id, 'service_id' => $svc->id,
            'titre' => 'Dr', 'nom' => 'Koffi', 'prenom' => 'Kablan',
            'specialite' => 'Cardiologie', 'actif' => true,
        ]);
    }

    private function gestionnaireDe(StructureSanitaire $structure): User
    {
        $user = User::factory()->create(['structure_id' => $structure->id]);
        $user->assignRole('gestionnaire_etablissement');

        return $user->fresh();
    }

    /** Les octets d'une vraie image PNG, produits par GD — jamais un fichier factice. */
    private function octetsPng(int $largeur = 40, int $hauteur = 40): string
    {
        $gd = imagecreatetruecolor($largeur, $hauteur);
        imagefill($gd, 0, 0, imagecolorallocate($gd, 0, 90, 200));

        ob_start();
        imagepng($gd);

        return (string) ob_get_clean();
    }

    private function image(int $largeur = 40, int $hauteur = 40): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('portrait.png', $this->octetsPng($largeur, $hauteur));
    }

    private function svc(): PhotoMedecin
    {
        return app(PhotoMedecin::class);
    }

    /** Même garde qu'`ImagesEtablissementTest` : `abort()` porte le statut dans `getStatusCode()`. */
    private function assertRefus(int $statut, callable $action): void
    {
        try {
            $action();
        } catch (HttpException $e) {
            $this->assertSame($statut, $e->getStatusCode(), "Refus attendu en {$statut}. Message : ".$e->getMessage());

            return;
        }

        $this->fail("Aucun refus levé, alors qu'un {$statut} était attendu.");
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // Dépôt / remplacement
    // ─────────────────────────────────────────────────────────────────────────────

    public function test_depose_une_photo(): void
    {
        $medecin = $this->medecin($this->structure(), $this->service($this->structure()));

        $this->svc()->deposer($this->image(), $medecin);

        $medecin->refresh();
        $this->assertNotNull($medecin->photo_uuid);
        $this->assertSame('image/png', $medecin->photo_mime);
        Storage::disk(PhotoMedecin::DISK)->assertExists($medecin->photo_uuid.'.png');
    }

    public function test_redeposer_remplace_et_supprime_l_ancien_fichier(): void
    {
        $structure = $this->structure();
        $medecin = $this->medecin($structure, $this->service($structure));

        $this->svc()->deposer($this->image(), $medecin);
        $medecin->refresh();
        $ancienChemin = $medecin->photo_uuid.'.png';

        $this->svc()->deposer($this->image(60, 60), $medecin);
        $medecin->refresh();

        Storage::disk(PhotoMedecin::DISK)->assertMissing($ancienChemin);
        Storage::disk(PhotoMedecin::DISK)->assertExists($medecin->photo_uuid.'.png');
    }

    public function test_photo_url_est_null_sans_photo_et_relative_avec(): void
    {
        $structure = $this->structure();
        $medecin = $this->medecin($structure, $this->service($structure));

        $this->assertNull($medecin->photo_url);

        $this->svc()->deposer($this->image(), $medecin);

        $this->assertSame("/api/v1/medecins/{$medecin->id}/photo", $medecin->fresh()->photo_url);
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // Nature réelle du fichier — même double crible que P6.4c
    // ─────────────────────────────────────────────────────────────────────────────

    public function test_un_fichier_texte_nomme_png_est_refuse(): void
    {
        $medecin = $this->medecin($this->structure(), $this->service($this->structure()));
        $menteur = UploadedFile::fake()->createWithContent('portrait.png', 'ceci est du texte, pas une image');

        $this->assertRefus(422, fn () => $this->svc()->deposer($menteur, $medecin));
        $this->assertNull($medecin->fresh()->photo_uuid);
    }

    public function test_un_format_hors_liste_blanche_est_refuse(): void
    {
        // GIF : une VRAIE image (même vecteur qu'ImagesEtablissementTest), refusée quand même —
        // la liste blanche est plus étroite que « toute image ». HEIC n'a pas d'encodeur GD
        // disponible ici pour produire un vecteur honnête (octets réels, pas un nom qui ment) ;
        // le principe qu'il teste (nature réelle, jamais l'extension déclarée) est le même.
        $medecin = $this->medecin($this->structure(), $this->service($this->structure()));
        $gd = imagecreatetruecolor(10, 10);
        ob_start();
        imagegif($gd);
        $gif = (string) ob_get_clean();
        imagedestroy($gd);

        $this->assertRefus(422, fn () => $this->svc()->deposer(
            UploadedFile::fake()->createWithContent('portrait.gif', $gif),
            $medecin,
        ));
        $this->assertNull($medecin->fresh()->photo_uuid);
    }

    public function test_une_image_de_zero_pixel_est_refusee_par_le_second_crible(): void
    {
        // Même vecteur qu'ImagesEtablissementTest, qui avait trouvé le trou en P6.4c : finfo dit
        // « image/png », getimagesizefromstring répond [0,0] et non `false`.
        $medecin = $this->medecin($this->structure(), $this->service($this->structure()));
        $png = $this->octetsPng();
        $zeroPixel = substr($png, 0, 16).pack('N', 0).pack('N', 0).substr($png, 24);

        $this->assertSame('image/png', (new \finfo(FILEINFO_MIME_TYPE))->buffer($zeroPixel));

        $this->assertRefus(422, fn () => $this->svc()->deposer(
            UploadedFile::fake()->createWithContent('portrait.png', $zeroPixel),
            $medecin,
        ));
    }

    public function test_un_fichier_trop_lourd_est_refuse(): void
    {
        config(['masante.medecin_photo.max_ko' => 1]);
        $medecin = $this->medecin($this->structure(), $this->service($this->structure()));

        $this->assertRefus(422, fn () => $this->svc()->deposer($this->image(400, 400), $medecin));
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // Suppression
    // ─────────────────────────────────────────────────────────────────────────────

    public function test_supprimer_efface_le_blob_et_les_colonnes(): void
    {
        $structure = $this->structure();
        $medecin = $this->medecin($structure, $this->service($structure));
        $this->svc()->deposer($this->image(), $medecin);
        $medecin->refresh();
        $chemin = $medecin->photo_uuid.'.png';

        $this->svc()->supprimer($medecin);

        Storage::disk(PhotoMedecin::DISK)->assertMissing($chemin);
        $medecin->refresh();
        $this->assertNull($medecin->photo_uuid);
        $this->assertNull($medecin->photo_mime);
        $this->assertNull($medecin->photo_empreinte_sha256);
    }

    public function test_supprimer_sans_photo_est_silencieux(): void
    {
        $medecin = $this->medecin($this->structure(), $this->service($this->structure()));

        $this->svc()->supprimer($medecin); // ne doit rien lever

        $this->assertNull($medecin->fresh()->photo_uuid);
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // Diffusion publique (contrôleur)
    // ─────────────────────────────────────────────────────────────────────────────

    public function test_la_diffusion_est_publique_et_porte_le_bon_type(): void
    {
        $structure = $this->structure();
        $medecin = $this->medecin($structure, $this->service($structure));
        $this->svc()->deposer($this->image(), $medecin);
        $medecin->refresh();

        $reponse = $this->get($medecin->photo_url);

        $reponse->assertOk();
        $reponse->assertHeader('Content-Type', 'image/png');
        $reponse->assertHeader('ETag', '"'.$medecin->photo_empreinte_sha256.'"');
    }

    public function test_une_photo_deja_connue_du_client_repond_304(): void
    {
        $structure = $this->structure();
        $medecin = $this->medecin($structure, $this->service($structure));
        $this->svc()->deposer($this->image(), $medecin);
        $medecin->refresh();

        $this->get($medecin->photo_url, ['If-None-Match' => '"'.$medecin->photo_empreinte_sha256.'"'])
            ->assertStatus(304);
    }

    public function test_sans_photo_la_diffusion_repond_404(): void
    {
        $medecin = $this->medecin($this->structure(), $this->service($this->structure()));

        $this->getJson("/api/v1/medecins/{$medecin->id}/photo")->assertNotFound();
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // Habilitation — AU NIVEAU ROUTE, la garde vit dans le contrôleur + fichePossedee()
    // ─────────────────────────────────────────────────────────────────────────────

    public function test_le_gestionnaire_de_l_etablissement_depose_la_photo(): void
    {
        $structure = $this->structure();
        $medecin = $this->medecin($structure, $this->service($structure));

        $this->actingAs($this->gestionnaireDe($structure))
            ->post("/portail/medecins/{$medecin->id}/photo", ['photo' => $this->image()])
            ->assertRedirect();

        $this->assertNotNull($medecin->fresh()->photo_uuid);
    }

    public function test_le_gestionnaire_d_un_autre_etablissement_est_refuse(): void
    {
        $sien = $this->structure('Clinique A');
        $autre = $this->structure('Clinique B');
        $medecin = $this->medecin($autre, $this->service($autre));

        $this->actingAs($this->gestionnaireDe($sien))
            ->post("/portail/medecins/{$medecin->id}/photo", ['photo' => $this->image()])
            ->assertNotFound();

        $this->assertNull($medecin->fresh()->photo_uuid);
    }

    public function test_retirer_la_photo_par_la_route(): void
    {
        $structure = $this->structure();
        $medecin = $this->medecin($structure, $this->service($structure));
        $this->svc()->deposer($this->image(), $medecin);

        $this->actingAs($this->gestionnaireDe($structure))
            ->delete("/portail/medecins/{$medecin->id}/photo")
            ->assertRedirect();

        $this->assertNull($medecin->fresh()->photo_uuid);
    }
}
