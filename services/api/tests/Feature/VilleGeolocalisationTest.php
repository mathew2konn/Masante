<?php

namespace Tests\Feature;

use App\Models\StructureSanitaire;
use App\Models\Ville;
use App\Services\Etablissement\LocalisateurVille;
use Database\Seeders\VilleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * P6.4b — Villes couvertes et géolocalisation.
 *
 * CE QUE CETTE SUITE PROTÈGE. Deux règles que le front ne doit jamais reprendre à son compte :
 *
 *  · « dans quelle ville suis-je ? » est un CALCUL, donc du backend. Si le mobile le refaisait,
 *    ouvrir une quatrième ville exigerait de publier une nouvelle version de l'application, et
 *    deux versions installées répondraient différemment à la même question.
 *  · « Abidjan affiche des communes, pas les autres » est une DONNÉE. Écrite en dur, ce serait
 *    un `if ville === 'Abidjan'`, la règle métier codée en dur que CDC_04 §20 interdit.
 *
 * Et une garantie d'honnêteté : hors des villes couvertes, on ne rattache PAS à la plus proche.
 * Un utilisateur à Man serait déclaré « à Bouaké », à 300 km.
 */
class VilleGeolocalisationTest extends TestCase
{
    use RefreshDatabase;

    /** Quelques points réels, pour que les vecteurs veuillent dire quelque chose. */
    private const PLATEAU_ABIDJAN = [5.3200, -4.0200];

    private const YAMOUSSOUKRO_CENTRE = [6.8276, -5.2893];

    private const BOUAKE_CENTRE = [7.6906, -5.0300];

    private const MAN = [7.4125, -7.5539];          // ~280 km à l'ouest de Yamoussoukro

    private const HAUTE_MER = [0.0, 0.0];           // golfe de Guinée

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(VilleSeeder::class);
    }

    private function localisateur(): LocalisateurVille
    {
        return app(LocalisateurVille::class);
    }

    private function structure(string $nom, string $commune, ?Ville $ville = null): StructureSanitaire
    {
        $structure = StructureSanitaire::create([
            'nom' => $nom, 'type' => 'centre_sante', 'adresse' => 'Adresse', 'commune' => $commune,
            'latitude' => 5.35, 'longitude' => -3.98, 'actif' => true,
        ]);

        if ($ville !== null) {
            $structure->forceFill(['ville_id' => $ville->id])->save();
        }

        return $structure->fresh();
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // Le seeder
    // ─────────────────────────────────────────────────────────────────────────────

    public function test_les_trois_villes_demandees_sont_couvertes(): void
    {
        $this->assertSame(
            ['Abidjan', 'Yamoussoukro', 'Bouaké'],
            Ville::orderBy('ordre')->pluck('nom')->all(),
        );
    }

    public function test_seule_abidjan_affiche_des_communes(): void
    {
        // La règle du propriétaire, portée par une DONNÉE et non par un `if` du front.
        $this->assertTrue(Ville::where('code', 'ABJ')->value('affiche_communes'));
        $this->assertFalse(Ville::where('code', 'YAM')->value('affiche_communes'));
        $this->assertFalse(Ville::where('code', 'BKE')->value('affiche_communes'));
    }

    public function test_le_seeder_est_idempotent_et_ne_reecrit_pas_un_rattachement(): void
    {
        $bouake = Ville::where('code', 'BKE')->firstOrFail();
        // Une structure de Cocody rattachée À LA MAIN à Bouaké : correctif volontaire, absurde
        // mais délibéré — le seeder ne doit pas le « corriger ».
        $structure = $this->structure('Cas particulier', 'Cocody', $bouake);

        $this->seed(VilleSeeder::class);

        $this->assertSame(3, Ville::count());
        $this->assertSame($bouake->id, $structure->fresh()->ville_id);
    }

    public function test_le_seeder_rattache_les_structures_abidjanaises(): void
    {
        $this->structure('Clinique de Cocody', 'Cocody');
        $this->seed(VilleSeeder::class);

        $this->assertSame(
            'ABJ',
            StructureSanitaire::where('nom', 'Clinique de Cocody')->firstOrFail()->ville->code,
        );
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // La localisation
    // ─────────────────────────────────────────────────────────────────────────────

    public function test_une_position_abidjanaise_donne_abidjan(): void
    {
        $resultat = $this->localisateur()->localiser(...self::PLATEAU_ABIDJAN);

        $this->assertSame('ABJ', $resultat['ville']->code);
        $this->assertFalse($resultat['hors_zone']);
    }

    public function test_yamoussoukro_et_bouake_sont_distinguees(): void
    {
        // Elles sont à ~95 km l'une de l'autre : un rayon mal calibré les confondrait.
        $this->assertSame('YAM', $this->localisateur()->localiser(...self::YAMOUSSOUKRO_CENTRE)['ville']->code);
        $this->assertSame('BKE', $this->localisateur()->localiser(...self::BOUAKE_CENTRE)['ville']->code);
    }

    public function test_hors_zone_on_ne_rattache_a_aucune_ville(): void
    {
        $resultat = $this->localisateur()->localiser(...self::MAN);

        $this->assertNull($resultat['ville'], 'Un utilisateur à Man ne doit pas être déclaré « à Bouaké ».');
        $this->assertTrue($resultat['hors_zone']);
        $this->assertSame([], $resultat['communes']);
    }

    public function test_hors_zone_les_villes_sont_ordonnees_par_proximite(): void
    {
        // Décision V6 : on le dit, puis on montre tout « en commençant par la ville la plus proche ».
        $proximite = $this->localisateur()->localiser(...self::MAN)['villes_par_proximite'];

        $this->assertSame('YAM', $proximite[0]['code'], 'Man est plus proche de Yamoussoukro.');
        $this->assertGreaterThan(0, $proximite[0]['distance_km']);

        $distances = array_column($proximite, 'distance_km');
        $triees = $distances;
        sort($triees);
        $this->assertSame($triees, $distances, 'Les villes doivent être ordonnées par distance croissante.');
    }

    public function test_une_position_absurde_ne_casse_rien(): void
    {
        $resultat = $this->localisateur()->localiser(...self::HAUTE_MER);

        $this->assertTrue($resultat['hors_zone']);
        $this->assertCount(3, $resultat['villes_par_proximite']);
    }

    public function test_une_ville_desactivee_n_est_plus_proposee(): void
    {
        Ville::where('code', 'BKE')->update(['actif' => false]);

        $resultat = $this->localisateur()->localiser(...self::BOUAKE_CENTRE);

        $this->assertTrue($resultat['hors_zone']);
        $this->assertCount(2, $resultat['villes_par_proximite']);
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // Les communes, dérivées des données
    // ─────────────────────────────────────────────────────────────────────────────

    public function test_les_communes_sont_derivees_des_structures(): void
    {
        $abidjan = Ville::where('code', 'ABJ')->firstOrFail();
        $this->structure('A', 'Cocody', $abidjan);
        $this->structure('B', 'Marcory', $abidjan);
        $this->structure('C', 'Cocody', $abidjan);

        // Dérivée, donc jamais divergente de la base — c'est tout l'intérêt par rapport à la
        // constante `COMMUNES` qui vivait en dur dans le mobile.
        $this->assertSame(['Cocody', 'Marcory'], $abidjan->fresh()->communes());
    }

    public function test_une_ville_sans_communes_n_en_renvoie_aucune(): void
    {
        $bouake = Ville::where('code', 'BKE')->firstOrFail();
        $this->structure('CHU de Bouaké', 'Bouaké', $bouake);

        // `affiche_communes` est faux : même si des valeurs de commune existent en base, l'écran
        // ne doit proposer aucun filtre. La décision est portée par la donnée, pas par le front.
        $this->assertSame([], $bouake->fresh()->communes());
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // Surface HTTP
    // ─────────────────────────────────────────────────────────────────────────────

    public function test_la_liste_des_villes_est_publique(): void
    {
        // L'écran en a besoin AVANT toute connexion, pour le sélecteur de repli (décision V5).
        $this->getJson('/api/v1/villes')
            ->assertOk()
            ->assertJsonPath('villes.0.code', 'ABJ')
            ->assertJsonPath('villes.0.affiche_communes', true)
            ->assertJsonPath('villes.1.affiche_communes', false);
    }

    public function test_localiser_repond_tout_ce_dont_l_ecran_a_besoin(): void
    {
        $abidjan = Ville::where('code', 'ABJ')->firstOrFail();
        $this->structure('Clinique', 'Cocody', $abidjan);

        $this->getJson('/api/v1/villes/localiser?lat='.self::PLATEAU_ABIDJAN[0].'&lng='.self::PLATEAU_ABIDJAN[1])
            ->assertOk()
            ->assertJsonPath('ville.code', 'ABJ')
            ->assertJsonPath('ville.affiche_communes', true)
            ->assertJsonPath('hors_zone', false)
            ->assertJsonPath('communes.0', 'Cocody');
    }

    public function test_localiser_hors_zone_annonce_le_hors_zone(): void
    {
        $this->getJson('/api/v1/villes/localiser?lat='.self::MAN[0].'&lng='.self::MAN[1])
            ->assertOk()
            ->assertJsonPath('ville', null)
            ->assertJsonPath('hors_zone', true)
            ->assertJsonPath('villes_par_proximite.0.code', 'YAM');
    }

    public function test_une_position_invalide_est_refusee(): void
    {
        $this->getJson('/api/v1/villes/localiser?lat=999&lng=0')->assertStatus(422);
        $this->getJson('/api/v1/villes/localiser')->assertStatus(422);
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // Le filtre par ville
    // ─────────────────────────────────────────────────────────────────────────────

    public function test_l_annuaire_se_filtre_par_code_de_ville(): void
    {
        $abidjan = Ville::where('code', 'ABJ')->firstOrFail();
        $bouake = Ville::where('code', 'BKE')->firstOrFail();
        $this->structure('Clinique Abidjan', 'Cocody', $abidjan);
        $this->structure('CHU Bouaké', 'Bouaké', $bouake);

        // Le mobile reçoit des CODES de `/villes/localiser` : il n'a pas à connaître les clés
        // primaires, et le contrat `?commune=` de P3 reste servi tel quel.
        $noms = array_column($this->getJson('/api/v1/structures?ville=BKE')->assertOk()->json('structures'), 'nom');

        $this->assertContains('CHU Bouaké', $noms);
        $this->assertNotContains('Clinique Abidjan', $noms);
    }

    public function test_le_contrat_commune_de_p3_est_intact(): void
    {
        $abidjan = Ville::where('code', 'ABJ')->firstOrFail();
        $this->structure('Clinique Cocody', 'Cocody', $abidjan);
        $this->structure('Clinique Marcory', 'Marcory', $abidjan);

        $noms = array_column($this->getJson('/api/v1/structures?commune=Cocody')->assertOk()->json('structures'), 'nom');

        $this->assertSame(['Clinique Cocody'], $noms);
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // Source unique des catégories — le défaut G-a, dans ses deux moitiés
    // ─────────────────────────────────────────────────────────────────────────────

    public function test_les_categories_ajoutees_par_p6_4a_sont_filtrables(): void
    {
        // Trouvé par cette suite : la règle `in:` de `StructureController` recopiait les 7
        // catégories historiques et refusait en **422** les six ajoutées par P6.4a — la base
        // acceptait la valeur, l'API la rejetait.
        StructureSanitaire::create([
            'nom' => 'Centre de dialyse du Plateau', 'type' => 'centre_dialyse',
            'adresse' => 'Plateau', 'commune' => 'Plateau',
            'latitude' => 5.32, 'longitude' => -4.02, 'actif' => true,
        ]);

        $noms = array_column(
            $this->getJson('/api/v1/structures?type=centre_dialyse')->assertOk()->json('structures'),
            'nom',
        );

        $this->assertSame(['Centre de dialyse du Plateau'], $noms);
    }

    public function test_l_api_publie_les_libelles_de_categorie(): void
    {
        // L'autre moitié : le mobile doit pouvoir CONSOMMER la liste au lieu de la recopier.
        // `LIBELLE_TYPE` n'en connaissait que 7 sur 13 et aurait affiché « undefined ».
        $reponse = $this->getJson('/api/v1/villes')->assertOk();

        $codes = array_column($reponse->json('types_etablissement'), 'code');

        $this->assertCount(13, $codes);
        $this->assertContains('centre_dialyse', $codes);
        $this->assertContains('chu', $codes);
        $this->assertSame(
            'Centre de dialyse',
            collect($reponse->json('types_etablissement'))->firstWhere('code', 'centre_dialyse')['libelle'],
        );
    }
}
