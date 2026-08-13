<?php

namespace Tests\Feature;

use App\Models\CategorieImageEtablissement;
use App\Models\DistrictSanitaire;
use App\Models\EtablissementImage;
use App\Models\Region;
use App\Models\StructureSanitaire;
use App\Models\User;
use App\Models\Ville;
use App\Services\Etablissement\ImagesEtablissement;
use Database\Seeders\CategoriesImageEtablissementSeeder;
use Database\Seeders\PortailRolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * P6.4d — Formulaires du portail (CDC_09 §4.2, CDC_11 §3.1).
 *
 * CE QUE CETTE SUITE PROTÈGE.
 *
 *  · **Le formulaire couvre enfin le schéma** : les champs ajoutés par P6.4a, la ville de P6.4b et
 *    la forme juridique de P6.4d s'enregistrent réellement — un formulaire qui affiche un champ
 *    sans le persister est pire que pas de champ du tout.
 *  · **Le district doit appartenir à la région déclarée, ET LE FORMULAIRE LE REFUSE.** C'est
 *    l'anomalie la plus sournoise du lot : `exists:` accepte les deux références séparément, seule
 *    leur combinaison est fausse. P6.4a la détecte après coup ; ici on l'empêche.
 *  · **Ce qui ne doit PAS être saisissable** : `identifiant_national` et `pays_code`, attribués par
 *    le backfill, jamais choisis par un client.
 *  · **`specialites` a disparu du formulaire** sans que la colonne ni les données existantes soient
 *    touchées.
 */
class FormulaireEtablissementTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PortailRolesSeeder::class);
        $this->seed(CategoriesImageEtablissementSeeder::class);

        $this->admin = User::factory()->create();
        $this->admin->givePermissionTo('etablissement.manage');
    }

    private function region(string $code, string $nom): Region
    {
        return Region::create(['pays_code' => 'CI', 'code' => $code, 'nom' => $nom]);
    }

    private function district(Region $region, string $code, string $nom): DistrictSanitaire
    {
        return DistrictSanitaire::create([
            'pays_code' => 'CI', 'code' => $code, 'nom' => $nom, 'region_id' => $region->id,
        ]);
    }

    private function ville(): Ville
    {
        return Ville::create([
            'pays_code' => 'CI', 'code' => 'ABJ', 'nom' => 'Abidjan',
            'latitude' => 5.36, 'longitude' => -4.0083, 'rayon_km' => 35,
            'affiche_communes' => true, 'ordre' => 1, 'actif' => true,
        ]);
    }

    /** @return array<string, mixed> */
    private function champsValides(array $surcharge = []): array
    {
        return array_merge([
            'nom'                 => 'Clinique du Plateau',
            'type'                => 'clinique_privee',
            'adresse'             => 'Boulevard de la République',
            'commune'             => 'Plateau',
            'latitude'            => 5.32,
            'longitude'           => -4.02,
            'gestionnaire_nom'    => 'Kouassi',
            'gestionnaire_prenom' => 'Aya',
            'gestionnaire_email'  => 'aya.kouassi@clinique.ci',
        ], $surcharge);
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // Le formulaire couvre le schéma (lève M3, M6, N5)
    // ─────────────────────────────────────────────────────────────────────────────

    public function test_les_champs_ajoutes_par_p6_4_sont_reellement_enregistres(): void
    {
        $region = $this->region('ABJ', 'Abidjan');
        $district = $this->district($region, 'ABJ-CB', 'Cocody-Bingerville');
        $ville = $this->ville();

        $this->actingAs($this->admin)
            ->post(route('portail.etablissements.store'), $this->champsValides([
                'nom_officiel'         => 'Clinique privée du Plateau',
                'statut_juridique'     => 'prive',
                'forme_juridique'      => 'SARL',
                'niveau_soins'         => 'secondaire',
                'region_id'            => $region->id,
                'district_id'          => $district->id,
                'ville_id'             => $ville->id,
                'quartier'             => 'Cité administrative',
                'email'                => 'contact@clinique.ci',
                'site_web'             => 'https://clinique.ci',
                'directeur'            => 'Dr Yao',
                'capacite_accueil'     => 120,
                'nombre_lits'          => 40,
                'numero_autorisation'  => 'AUT-2024-118',
                'autorite_tutelle'     => 'Ministère de la Santé',
                'date_creation'        => '2010-06-01',
                'agrements'            => 'Agrément CNAM, Convention CMU',
                'certifications'       => 'ISO 9001',
                'description'          => 'Établissement de proximité.',
            ]))
            ->assertRedirect(route('portail.etablissements.index'));

        $structure = StructureSanitaire::firstWhere('nom', 'Clinique du Plateau');

        $this->assertSame('Clinique privée du Plateau', $structure->nom_officiel);
        $this->assertSame('prive', $structure->statut_juridique);
        $this->assertSame('SARL', $structure->forme_juridique, 'La forme juridique lève la limite M6.');
        $this->assertSame('secondaire', $structure->niveau_soins);
        $this->assertSame($district->id, $structure->district_id);
        $this->assertSame($ville->id, $structure->ville_id, 'La ville lève la limite N5.');
        $this->assertSame(40, $structure->nombre_lits);
        $this->assertSame(['Agrément CNAM', 'Convention CMU'], $structure->agrements_json);
        $this->assertSame(['ISO 9001'], $structure->certifications_json);
    }

    public function test_les_deux_axes_juridiques_sont_distincts(): void
    {
        // « Qui possède » et « sous quelle forme de droit » ne sont pas la même question : une
        // clinique privée peut être une SARL ou une SA. Les fondre rendrait impossible la
        // statistique « combien de SARL parmi les cliniques privées ? » (§4.4).
        $this->actingAs($this->admin)->post(route('portail.etablissements.store'), $this->champsValides([
            'statut_juridique' => 'prive',
            'forme_juridique'  => 'SA',
        ]));

        $structure = StructureSanitaire::firstWhere('nom', 'Clinique du Plateau');

        $this->assertSame('prive', $structure->statut_juridique);
        $this->assertSame('SA', $structure->forme_juridique);
    }

    public function test_l_edition_conserve_les_champs_enrichis(): void
    {
        $structure = StructureSanitaire::create([
            'nom' => 'CHU', 'type' => 'chu', 'adresse' => 'A', 'commune' => 'Treichville',
            'latitude' => 5.29, 'longitude' => -4.0, 'actif' => true, 'forme_juridique' => 'EPN',
        ]);

        $this->actingAs($this->admin)
            ->put(route('portail.etablissements.update', $structure), [
                'nom' => 'CHU de Treichville', 'type' => 'chu', 'adresse' => 'A',
                'commune' => 'Treichville', 'latitude' => 5.29, 'longitude' => -4.0,
                'forme_juridique' => 'EPN', 'niveau_soins' => 'tertiaire',
            ])
            ->assertRedirect();

        $structure->refresh();
        $this->assertSame('CHU de Treichville', $structure->nom);
        $this->assertSame('tertiaire', $structure->niveau_soins);
        $this->assertSame('EPN', $structure->forme_juridique);
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // LE VECTEUR CENTRAL — le district doit appartenir à la région déclarée
    // ─────────────────────────────────────────────────────────────────────────────

    public function test_un_district_hors_de_sa_region_est_refuse_au_formulaire(): void
    {
        $abidjan = $this->region('ABJ', 'Abidjan');
        $bouake = $this->region('BKE', 'Gbêkê');
        $districtDAbidjan = $this->district($abidjan, 'ABJ-CB', 'Cocody-Bingerville');

        // Les DEUX références existent et sont valides prises séparément : `exists:` les accepte.
        // C'est leur COMBINAISON qui est fausse, et rien d'autre que ce contrôle ne la voit.
        $this->actingAs($this->admin)
            ->post(route('portail.etablissements.store'), $this->champsValides([
                'region_id'   => $bouake->id,
                'district_id' => $districtDAbidjan->id,
            ]))
            ->assertSessionHasErrors('district_id');

        $this->assertDatabaseMissing('structures_sanitaires', ['nom' => 'Clinique du Plateau']);
    }

    public function test_le_couple_coherent_est_accepte(): void
    {
        // La moitié qui manque au vecteur précédent : un contrôle qui refuserait tout serait aussi
        // inutilisable qu'un contrôle qui n'attrape rien.
        $region = $this->region('ABJ', 'Abidjan');
        $district = $this->district($region, 'ABJ-CB', 'Cocody-Bingerville');

        $this->actingAs($this->admin)
            ->post(route('portail.etablissements.store'), $this->champsValides([
                'region_id'   => $region->id,
                'district_id' => $district->id,
            ]))
            ->assertSessionHasNoErrors();

        $this->assertSame($district->id, StructureSanitaire::firstWhere('nom', 'Clinique du Plateau')->district_id);
    }

    public function test_un_district_sans_region_est_refuse(): void
    {
        $region = $this->region('ABJ', 'Abidjan');
        $district = $this->district($region, 'ABJ-CB', 'Cocody-Bingerville');

        $this->actingAs($this->admin)
            ->post(route('portail.etablissements.store'), $this->champsValides(['district_id' => $district->id]))
            ->assertSessionHasErrors('region_id');
    }

    public function test_une_region_sans_district_reste_acceptee(): void
    {
        // L'inverse est licite : on peut connaître la région sans avoir encore le district.
        // L'absence se dit, elle ne se comble pas (ADR-026 §3.4).
        $region = $this->region('ABJ', 'Abidjan');

        $this->actingAs($this->admin)
            ->post(route('portail.etablissements.store'), $this->champsValides(['region_id' => $region->id]))
            ->assertSessionHasNoErrors();
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // Ce qui n'est pas saisissable
    // ─────────────────────────────────────────────────────────────────────────────

    public function test_l_identifiant_national_ne_peut_pas_venir_du_formulaire(): void
    {
        $this->actingAs($this->admin)->post(route('portail.etablissements.store'), $this->champsValides([
            'identifiant_national' => 'ETS999999',
            'pays_code'            => 'SN',
        ]));

        $structure = StructureSanitaire::firstWhere('nom', 'Clinique du Plateau');

        $this->assertNull($structure->identifiant_national, "L'identifiant est attribué, jamais choisi.");
        $this->assertSame('CI', $structure->pays_code);
    }

    public function test_le_nombre_de_lits_ne_depasse_pas_la_capacite(): void
    {
        $this->actingAs($this->admin)
            ->post(route('portail.etablissements.store'), $this->champsValides([
                'capacite_accueil' => 50,
                'nombre_lits'      => 80,
            ]))
            ->assertSessionHasErrors('nombre_lits');
    }

    public function test_une_categorie_ajoutee_par_p6_4a_est_acceptee(): void
    {
        // `centre_dialyse` fait partie des six catégories ajoutées par P6.4a. Le formulaire les
        // servait déjà via la source unique `TypesEtablissement` ; ce vecteur l'empêche de régresser.
        $this->actingAs($this->admin)
            ->post(route('portail.etablissements.store'), $this->champsValides(['type' => 'centre_dialyse']))
            ->assertSessionHasNoErrors();
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // `specialites` retiré du formulaire (décision K2)
    // ─────────────────────────────────────────────────────────────────────────────

    public function test_le_formulaire_n_affiche_plus_le_champ_specialites(): void
    {
        $this->actingAs($this->admin)
            ->get(route('portail.etablissements.create'))
            ->assertOk()
            ->assertDontSee('name="specialites"', false)
            ->assertSee('name="forme_juridique"', false)
            ->assertSee('name="ville_id"', false);
    }

    public function test_les_specialites_deja_saisies_ne_sont_pas_effacees(): void
    {
        // La colonne est conservée : on cesse de faire SAISIR une donnée morte, on ne détruit pas
        // celle qui existe déjà. Une migration destructive aurait perdu de l'information réelle
        // pour un gain nul.
        $structure = StructureSanitaire::create([
            'nom' => 'Labo', 'type' => 'laboratoire', 'adresse' => 'A', 'commune' => 'Cocody',
            'latitude' => 5.35, 'longitude' => -3.98, 'actif' => true,
            'specialites_json' => ['Biologie', 'Hématologie'],
        ]);

        $this->actingAs($this->admin)->put(route('portail.etablissements.update', $structure), [
            'nom' => 'Laboratoire central', 'type' => 'laboratoire', 'adresse' => 'A',
            'commune' => 'Cocody', 'latitude' => 5.35, 'longitude' => -3.98,
        ]);

        $this->assertSame(['Biologie', 'Hématologie'], $structure->refresh()->specialites_json);
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // Images depuis le portail (lève O1 côté Blade)
    // ─────────────────────────────────────────────────────────────────────────────

    public function test_le_portail_depose_une_image_par_le_meme_service_que_l_api(): void
    {
        Storage::fake(ImagesEtablissement::DISK);
        $structure = StructureSanitaire::create([
            'nom' => 'CHU', 'type' => 'chu', 'adresse' => 'A', 'commune' => 'Treichville',
            'latitude' => 5.29, 'longitude' => -4.0, 'actif' => true,
        ]);

        $this->actingAs($this->admin)
            ->post(route('portail.etablissements.images.store', $structure), [
                'categorie' => 'logo',
                'image'     => UploadedFile::fake()->createWithContent('logo.png', $this->octetsPng()),
            ])
            ->assertRedirect();

        $this->assertDatabaseCount('etablissement_images', 1);
    }

    public function test_un_refus_du_service_ne_fait_pas_perdre_la_page(): void
    {
        // Le service refuse en `abort()`. Rendue brute, une page 422 au milieu d'un formulaire
        // ferait perdre la saisie : on la traduit en message d'écran.
        Storage::fake(ImagesEtablissement::DISK);
        $structure = StructureSanitaire::create([
            'nom' => 'CHU', 'type' => 'chu', 'adresse' => 'A', 'commune' => 'Treichville',
            'latitude' => 5.29, 'longitude' => -4.0, 'actif' => true,
        ]);

        $this->actingAs($this->admin)
            ->post(route('portail.etablissements.images.store', $structure), [
                'categorie' => 'logo',
                'image'     => UploadedFile::fake()->createWithContent('logo.png', 'pas une image'),
            ])
            ->assertRedirect()
            ->assertSessionHas('erreur');

        $this->assertDatabaseCount('etablissement_images', 0);
    }

    public function test_une_image_d_un_autre_etablissement_ne_se_supprime_pas(): void
    {
        Storage::fake(ImagesEtablissement::DISK);
        $a = StructureSanitaire::create(['nom' => 'A', 'type' => 'chu', 'adresse' => 'A', 'commune' => 'C', 'latitude' => 5.3, 'longitude' => -4.0, 'actif' => true]);
        $b = StructureSanitaire::create(['nom' => 'B', 'type' => 'chu', 'adresse' => 'B', 'commune' => 'C', 'latitude' => 5.3, 'longitude' => -4.0, 'actif' => true]);

        $image = app(ImagesEtablissement::class)->deposer(
            UploadedFile::fake()->createWithContent('logo.png', $this->octetsPng()),
            $a,
            'logo',
            $this->admin,
        );

        $this->actingAs($this->admin)
            ->delete(route('portail.etablissements.images.destroy', [$b, $image]))
            ->assertNotFound();

        $this->assertDatabaseHas('etablissement_images', ['id' => $image->id]);
    }

    public function test_les_categories_d_image_sont_servies_a_la_page_d_edition(): void
    {
        $structure = StructureSanitaire::create([
            'nom' => 'CHU', 'type' => 'chu', 'adresse' => 'A', 'commune' => 'Treichville',
            'latitude' => 5.29, 'longitude' => -4.0, 'actif' => true,
        ]);

        $this->actingAs($this->admin)
            ->get(route('portail.etablissements.edit', $structure))
            ->assertOk()
            ->assertSee('Salle d\'attente')
            ->assertSee('Bloc opératoire');
    }

    private function octetsPng(): string
    {
        $gd = imagecreatetruecolor(20, 20);
        imagefill($gd, 0, 0, imagecolorallocate($gd, 0, 90, 200));
        ob_start();
        imagepng($gd);

        return (string) ob_get_clean();
    }
}
