<?php

namespace Tests\Feature;

use App\Models\CategorieImageEtablissement;
use App\Models\EtablissementImage;
use App\Models\StructureSanitaire;
use App\Models\User;
use App\Services\Etablissement\ImagesEtablissement;
use App\Services\Referentiel\EmpreinteReferentiel;
use App\Services\Referentiel\SourceEtablissements;
use Database\Seeders\CategoriesImageEtablissementSeeder;
use Database\Seeders\PortailRolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * P6.4c — Images des établissements (CDC_09 §4.2, CDC_11 §3.1).
 *
 * CE QUE CETTE SUITE PROTÈGE.
 *
 *  · **Les cinq gardes du dépôt**, chacune avec son vecteur — habilitation, catégorie, quota,
 *    nature réelle du fichier, nom de stockage. Aucune ne rattrape les autres : une suite qui n'en
 *    prouverait que quatre laisserait croire que la cinquième existe.
 *  · **La sensibilité du référentiel, dans les deux sens** (décision I3) : déposer une image DOIT
 *    faire diverger le référentiel publié ; redéposer la même image octet pour octet, après
 *    suppression, ne DOIT PAS le faire — c'est ce qui prouve que l'instantané porte l'empreinte du
 *    contenu et non le chemin de stockage, qui, lui, change à chaque dépôt.
 *  · **Ce qui ne sort jamais** : le chemin de stockage. Et le fait que l'URL servie est RELATIVE,
 *    donc insensible au changement d'URL Ngrok (constat H3 du G0).
 */
class ImagesEtablissementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PortailRolesSeeder::class);
        $this->seed(CategoriesImageEtablissementSeeder::class);
        Storage::fake(ImagesEtablissement::DISK);
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

    private function administrateurNational(): User
    {
        $user = User::factory()->create();
        $user->givePermissionTo(ImagesEtablissement::PERMISSION);

        return $user->fresh();
    }

    private function gestionnaireDe(StructureSanitaire $structure): User
    {
        $user = User::factory()->create(['structure_id' => $structure->id]);
        $user->assignRole('gestionnaire_etablissement');

        return $user->fresh();
    }

    /** Les octets d'une vraie image PNG, produits par GD — jamais un fichier factice. */
    private function octetsPng(int $largeur = 40, int $hauteur = 30, string $couleur = 'bleu'): string
    {
        $gd = imagecreatetruecolor($largeur, $hauteur);
        $teinte = $couleur === 'bleu'
            ? imagecolorallocate($gd, 0, 90, 200)
            : imagecolorallocate($gd, 220, 90, 0);
        imagefill($gd, 0, 0, $teinte);

        ob_start();
        imagepng($gd);

        return (string) ob_get_clean();
    }

    private function image(int $largeur = 40, int $hauteur = 30, string $couleur = 'bleu'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent(
            'logo.png',
            $this->octetsPng($largeur, $hauteur, $couleur),
        );
    }

    private function service(): ImagesEtablissement
    {
        return app(ImagesEtablissement::class);
    }

    /**
     * Vérifie qu'une action est refusée AVEC LE BON STATUT.
     *
     * `abort()` lève une `HttpException` dont `getCode()` vaut 0 : le statut vit dans
     * `getStatusCode()`. Un `expectExceptionCode(403)` passerait donc… en n'ayant rien vérifié du
     * tout — et pire, un refus 500 se ferait passer pour un refus 403.
     */
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
    // La table de référence (décision I4 : les catégories sont des DONNÉES)
    // ─────────────────────────────────────────────────────────────────────────────

    public function test_les_cinq_categories_du_cdc_11_sont_seedees(): void
    {
        $codes = CategorieImageEtablissement::query()->orderBy('ordre')->pluck('code')->all();

        $this->assertSame(
            ['logo', 'accueil', 'salle_attente', 'bloc_operatoire', 'parking'],
            $codes,
            'CDC_11 §3.1 nomme exactement ces cinq sujets.',
        );
    }

    public function test_le_logo_est_unique_par_une_donnee_pas_par_un_if(): void
    {
        $this->assertSame(1, CategorieImageEtablissement::where('code', 'logo')->value('max_par_etablissement'));
        $this->assertGreaterThan(1, CategorieImageEtablissement::where('code', 'accueil')->value('max_par_etablissement'));
    }

    public function test_le_seeder_est_idempotent_et_ne_reecrit_pas_un_maximum_ajuste(): void
    {
        CategorieImageEtablissement::where('code', 'accueil')->update(['max_par_etablissement' => 10]);

        $this->seed(CategoriesImageEtablissementSeeder::class);

        $this->assertSame(5, CategorieImageEtablissement::count());
        $this->assertSame(
            10,
            CategorieImageEtablissement::where('code', 'accueil')->value('max_par_etablissement'),
            'Un réglage posé à la main prime sur la valeur du seeder.',
        );
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // Garde 1 — habilitation
    // ─────────────────────────────────────────────────────────────────────────────

    public function test_un_administrateur_national_peut_deposer(): void
    {
        $structure = $this->structure();

        $image = $this->service()->deposer($this->image(), $structure, 'logo', $this->administrateurNational());

        $this->assertSame('logo', $image->categorie_code);
        Storage::disk(ImagesEtablissement::DISK)->assertExists($image->chemin);
    }

    public function test_le_gestionnaire_de_cet_etablissement_peut_deposer(): void
    {
        $structure = $this->structure();

        // C'est le chemin le plus important : CDC_11 §3 fait remplir la vitrine par l'hôpital
        // lui-même, pas par une administration centrale.
        $image = $this->service()->deposer($this->image(), $structure, 'accueil', $this->gestionnaireDe($structure));

        $this->assertSame($structure->id, $image->structure_id);
    }

    public function test_le_gestionnaire_d_un_autre_etablissement_est_refuse(): void
    {
        $sien = $this->structure('Clinique A');
        $autre = $this->structure('Clinique B');

        $this->assertRefus(403, fn () => $this->service()
            ->deposer($this->image(), $autre, 'logo', $this->gestionnaireDe($sien)));
    }

    public function test_un_compte_sans_habilitation_est_refuse(): void
    {
        $this->assertRefus(403, fn () => $this->service()
            ->deposer($this->image(), $this->structure(), 'logo', User::factory()->create()));
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // Garde 2 — catégorie
    // ─────────────────────────────────────────────────────────────────────────────

    public function test_une_categorie_inconnue_est_refusee(): void
    {
        $this->assertRefus(404, fn () => $this->service()
            ->deposer($this->image(), $this->structure(), 'piscine', $this->administrateurNational()));
    }

    public function test_une_categorie_desactivee_n_accepte_plus_de_depot(): void
    {
        CategorieImageEtablissement::where('code', 'parking')->update(['actif' => false]);

        $this->assertRefus(404, fn () => $this->service()
            ->deposer($this->image(), $this->structure(), 'parking', $this->administrateurNational()));
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // Garde 3 — quota
    // ─────────────────────────────────────────────────────────────────────────────

    public function test_un_second_logo_est_refuse(): void
    {
        $structure = $this->structure();
        $acteur = $this->administrateurNational();

        $this->service()->deposer($this->image(), $structure, 'logo', $acteur);

        $this->assertRefus(409, fn () => $this->service()
            ->deposer($this->image(50, 50, 'orange'), $structure, 'logo', $acteur));
    }

    public function test_le_quota_est_par_etablissement_pas_global(): void
    {
        $acteur = $this->administrateurNational();

        $this->service()->deposer($this->image(), $this->structure('A'), 'logo', $acteur);
        $seconde = $this->service()->deposer($this->image(), $this->structure('B'), 'logo', $acteur);

        $this->assertNotNull($seconde->id, 'Deux établissements ont chacun droit à leur logo.');
    }

    public function test_la_meme_image_deux_fois_dans_la_meme_categorie_est_refusee(): void
    {
        $structure = $this->structure();
        $acteur = $this->administrateurNational();
        $fichier = $this->image();

        $this->service()->deposer($fichier, $structure, 'accueil', $acteur);

        $this->assertRefus(409, fn () => $this->service()
            ->deposer($this->image(), $structure, 'accueil', $acteur));
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // Garde 4 — nature réelle du fichier
    // ─────────────────────────────────────────────────────────────────────────────

    public function test_un_fichier_texte_nomme_png_est_refuse(): void
    {
        // Le nom et l'extension mentent ; seuls les octets disent la vérité.
        $menteur = UploadedFile::fake()->createWithContent('logo.png', 'ceci est du texte, pas une image');

        $this->assertRefus(422, fn () => $this->service()
            ->deposer($menteur, $this->structure(), 'logo', $this->administrateurNational()));
    }

    public function test_un_format_hors_liste_blanche_est_refuse(): void
    {
        // GIF : c'est une vraie image, et elle est tout de même refusée — la liste blanche des
        // établissements est plus étroite que « toute image » (config `etablissement_images`).
        $gd = imagecreatetruecolor(10, 10);
        ob_start();
        imagegif($gd);
        $gif = (string) ob_get_clean();
        imagedestroy($gd);

        $this->assertRefus(422, fn () => $this->service()->deposer(
            UploadedFile::fake()->createWithContent('anim.gif', $gif),
            $this->structure(),
            'logo',
            $this->administrateurNational(),
        ));
    }

    public function test_une_image_de_zero_pixel_est_refusee_par_le_second_crible(): void
    {
        // CE VECTEUR EXISTE POUR PROUVER LA SECONDE MOITIÉ DE LA GARDE 4, et il a trouvé un trou.
        //
        // On zéroie la largeur et la hauteur dans l'en-tête IHDR d'un PNG par ailleurs valide.
        // `finfo` répond « image/png » : le premier crible le LAISSE PASSER. Et
        // `getimagesizefromstring` ne répond pas `false` mais `[0, 0]` — le contrôle initial, qui
        // ne testait que `false`, laissait donc entrer une image de zéro pixel.
        $png = $this->octetsPng();
        $zeroPixel = substr($png, 0, 16).pack('N', 0).pack('N', 0).substr($png, 24);

        $this->assertSame('image/png', (new \finfo(FILEINFO_MIME_TYPE))->buffer($zeroPixel));

        $this->assertRefus(422, fn () => $this->service()->deposer(
            UploadedFile::fake()->createWithContent('logo.png', $zeroPixel),
            $this->structure(),
            'logo',
            $this->administrateurNational(),
        ));
    }

    public function test_un_fichier_trop_lourd_est_refuse(): void
    {
        config(['masante.etablissement_images.max_ko' => 1]);

        $this->assertRefus(422, fn () => $this->service()
            ->deposer($this->image(400, 400), $this->structure(), 'logo', $this->administrateurNational()));
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // Garde 5 — nom de stockage
    // ─────────────────────────────────────────────────────────────────────────────

    public function test_le_nom_du_client_n_atteint_jamais_le_disque(): void
    {
        $piege = UploadedFile::fake()->createWithContent('../../../.env', $this->octetsPng());

        $image = $this->service()->deposer($piege, $this->structure(), 'logo', $this->administrateurNational());

        $this->assertMatchesRegularExpression(
            '#^\d+/[0-9a-f-]{36}\.png$#',
            $image->chemin,
            'Le chemin est un UUID sous le dossier de la structure, et son extension vient du MIME réel.',
        );
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // Le contrat exposé
    // ─────────────────────────────────────────────────────────────────────────────

    public function test_le_chemin_de_stockage_n_est_jamais_serialise(): void
    {
        $image = $this->service()->deposer($this->image(), $this->structure(), 'logo', $this->administrateurNational());

        $this->assertArrayNotHasKey('chemin', $image->toArray());
    }

    public function test_le_compte_deposant_n_est_pas_expose_sur_une_fiche_publique(): void
    {
        // Trouvé au G2 : `depose_par` sortait dans la réponse publique. La diffusion des fiches
        // n'exige aucune identité — savoir quel compte a mis en ligne la photo d'un bloc opératoire
        // ne regarde personne au-dehors. L'information reste en base pour l'imputabilité.
        $structure = $this->structure();
        $image = $this->service()->deposer($this->image(), $structure, 'logo', $this->administrateurNational());

        $this->assertArrayNotHasKey('depose_par', $image->toArray());
        $this->assertDatabaseHas('etablissement_images', ['id' => $image->id, 'depose_par' => $image->depose_par]);
    }

    public function test_l_url_servie_est_relative(): void
    {
        $structure = $this->structure();
        $image = $this->service()->deposer($this->image(), $structure, 'logo', $this->administrateurNational());

        // Une URL absolue serait bâtie sur APP_URL, qui vaut l'URL Ngrok : mise en cache par le
        // mobile, elle deviendrait fausse au prochain redémarrage du tunnel.
        $this->assertSame("/api/v1/structures/{$structure->id}/images/{$image->id}", $image->url);
    }

    public function test_la_diffusion_est_publique_et_porte_le_bon_type(): void
    {
        $structure = $this->structure();
        $image = $this->service()->deposer($this->image(), $structure, 'logo', $this->administrateurNational());

        $reponse = $this->get($image->url);

        $reponse->assertOk();
        $reponse->assertHeader('Content-Type', 'image/png');
        $reponse->assertHeader('ETag', '"'.$image->empreinte.'"');
    }

    public function test_une_image_deja_connue_du_client_repond_304(): void
    {
        $structure = $this->structure();
        $image = $this->service()->deposer($this->image(), $structure, 'logo', $this->administrateurNational());

        $this->get($image->url, ['If-None-Match' => '"'.$image->empreinte.'"'])->assertStatus(304);
    }

    public function test_une_image_reclamee_sous_un_autre_etablissement_repond_404(): void
    {
        $structure = $this->structure('A');
        $autre = $this->structure('B');
        $image = $this->service()->deposer($this->image(), $structure, 'logo', $this->administrateurNational());

        $this->get("/api/v1/structures/{$autre->id}/images/{$image->id}")->assertNotFound();
    }

    public function test_la_fiche_expose_les_images_et_la_liste_seulement_le_logo(): void
    {
        $structure = $this->structure();
        $acteur = $this->administrateurNational();
        $this->service()->deposer($this->image(), $structure, 'logo', $acteur);
        $this->service()->deposer($this->image(60, 60, 'orange'), $structure, 'accueil', $acteur);

        $fiche = $this->getJson("/api/v1/structures/{$structure->id}")->json('structure.images');
        $liste = $this->getJson('/api/v1/structures')->json('structures.0.images');

        $this->assertCount(2, $fiche);
        $this->assertCount(1, $liste, 'La carte de résultat ne montre que le logo.');
        $this->assertSame('logo', $liste[0]['categorie_code']);
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // Suppression
    // ─────────────────────────────────────────────────────────────────────────────

    public function test_la_suppression_efface_le_blob_et_la_ligne(): void
    {
        $structure = $this->structure();
        $image = $this->service()->deposer($this->image(), $structure, 'logo', $this->administrateurNational());
        $chemin = $image->chemin;

        $this->service()->supprimer($image, $this->administrateurNational());

        Storage::disk(ImagesEtablissement::DISK)->assertMissing($chemin);
        $this->assertDatabaseMissing('etablissement_images', ['id' => $image->id]);
    }

    public function test_un_compte_sans_habilitation_ne_supprime_pas(): void
    {
        $image = $this->service()->deposer($this->image(), $this->structure(), 'logo', $this->administrateurNational());

        $this->assertRefus(403, fn () => $this->service()->supprimer($image, User::factory()->create()));
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // LE VECTEUR CENTRAL — la sensibilité du référentiel, dans les deux sens (I3)
    // ─────────────────────────────────────────────────────────────────────────────

    public function test_deposer_une_image_fait_diverger_le_referentiel(): void
    {
        $structure = $this->structure();
        $avant = $this->empreinteDuReferentiel();

        $this->service()->deposer($this->image(), $structure, 'logo', $this->administrateurNational());

        $this->assertNotSame(
            $avant,
            $this->empreinteDuReferentiel(),
            'Le propriétaire a placé les images dans le référentiel gouverné : un dépôt doit diverger.',
        );
    }

    public function test_redeposer_la_meme_image_ne_fait_pas_diverger(): void
    {
        $structure = $this->structure();
        $acteur = $this->administrateurNational();

        $premiere = $this->service()->deposer($this->image(), $structure, 'logo', $acteur);
        $empreinteAvec = $this->empreinteDuReferentiel();
        $cheminInitial = $premiere->chemin;

        $this->service()->supprimer($premiere, $acteur);
        $seconde = $this->service()->deposer($this->image(), $structure, 'logo', $acteur);

        $this->assertNotSame($cheminInitial, $seconde->chemin, 'Le chemin de stockage change bien.');
        $this->assertSame(
            $empreinteAvec,
            $this->empreinteDuReferentiel(),
            "Le référentiel porte l'empreinte du CONTENU, pas le chemin : la même image ne diverge pas.",
        );
    }

    public function test_l_ordre_de_depot_ne_change_pas_l_empreinte(): void
    {
        $acteur = $this->administrateurNational();

        $a = $this->structure('A');
        $this->service()->deposer($this->image(), $a, 'accueil', $acteur);
        $this->service()->deposer($this->image(60, 60, 'orange'), $a, 'salle_attente', $acteur);
        $ordreDirect = $this->projection($a);

        EtablissementImage::query()->delete();

        $this->service()->deposer($this->image(60, 60, 'orange'), $a, 'salle_attente', $acteur);
        $this->service()->deposer($this->image(), $a, 'accueil', $acteur);

        $this->assertSame(
            $ordreDirect,
            $this->projection($a),
            "Sans tri, deux ensembles identiques produiraient deux empreintes selon l'ordre d'insertion.",
        );
    }

    public function test_le_referentiel_ne_publie_ni_chemin_ni_octets(): void
    {
        $structure = $this->structure();
        $this->service()->deposer($this->image(), $structure, 'logo', $this->administrateurNational());

        $projetee = $this->projection($structure);

        $this->assertSame(['categorie', 'empreinte'], array_keys($projetee[0]));
    }

    /** @return list<array<string, mixed>> */
    private function projection(StructureSanitaire $structure): array
    {
        $entree = collect((new SourceEtablissements)->extraire())
            ->firstWhere('nom_officiel', $structure->nom);

        return $entree['images'];
    }

    private function empreinteDuReferentiel(): string
    {
        return EmpreinteReferentiel::duContenu((new SourceEtablissements)->extraire());
    }
}
