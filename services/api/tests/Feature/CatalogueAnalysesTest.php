<?php

namespace Tests\Feature;

use App\Models\Analyse;
use App\Models\MembreFamille;
use App\Models\ResultatAnalyse;
use App\Models\User;
use App\Services\Analyse\AttributeurCodeAnalyse;
use App\Services\Analyse\GenerateurCodeAnalyse;
use App\Services\Analyse\ReglesIntervalleReference;
use App\Services\Referentiel\SourceAnalyses;
use App\Support\Analyses;
use App\Support\RegistreReferentiels;
use Database\Seeders\PortailRolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * P6.7a — Catalogue national des analyses (CDC_09 §7.3).
 *
 * Ce que ces vecteurs doivent tenir :
 *
 *  · une analyse est identifiée par son **milieu** autant que par son nom — « glycémie » n'est pas
 *    une analyse ;
 *  · les valeurs de référence sont **stratifiées**, et la résolution renvoie **toutes** les strates
 *    applicables sans en choisir aucune — c'est la décision « afficher, ne pas conclure » ;
 *  · **aucun endpoint ne qualifie un résultat** ;
 *  · un intervalle **sans source** empêche la publication ;
 *  · le lien résultat → catalogue fige code, libellé et **unité**, sur les trois chemins d'écriture ;
 *  · le prescripteur d'un résultat n'est **jamais réécrit** : celui qui consigne n'est pas
 *    forcément celui qui a prescrit (correction de P6.7a, décidée en P6.7b).
 */
class CatalogueAnalysesTest extends TestCase
{
    use RefreshDatabase;

    private const ADULTE = 6570;   // 18 ans, en jours

    private User $user;

    private MembreFamille $membre;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->membre = MembreFamille::factory()->for($this->user)->create();

        Sanctum::actingAs($this->user);
    }

    private function analyse(array $remplacements = []): Analyse
    {
        $a = Analyse::create(array_merge([
            'libelle'        => 'Hémoglobine',
            'categorie'      => 'hematologie',
            'milieu_preleve' => 'sang_veineux',
            'unite'          => 'g/dL',
        ], $remplacements));

        app(AttributeurCodeAnalyse::class)->attribuer($a);

        return $a->fresh();
    }

    private function strate(Analyse $analyse, array $remplacements = []): void
    {
        $analyse->references()->create(array_merge([
            'sexe'               => 'tous',
            'age_min_jours'      => null,
            'age_max_jours'      => null,
            'etat_physiologique' => 'standard',
            'valeur_min'         => 12.0,
            'valeur_max'         => 16.0,
            'libelle_strate'     => 'Adulte',
            'source'             => 'demonstration',
        ], $remplacements));
    }

    private function source(): SourceAnalyses
    {
        return new SourceAnalyses();
    }

    private function regles(): ReglesIntervalleReference
    {
        return new ReglesIntervalleReference();
    }

    private function url(): string
    {
        return "/api/v1/membres/{$this->membre->id}/resultats-analyses";
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // Le code national et l'identité d'une analyse
    // ─────────────────────────────────────────────────────────────────────────────

    public function test_le_code_national_est_litteral_sans_cle(): void
    {
        $this->assertSame('ANA000001', $this->analyse()->code);
        $this->assertTrue(GenerateurCodeAnalyse::formeValide('ANA000458'));
        $this->assertFalse(GenerateurCodeAnalyse::formeValide('ANA1'));
    }

    public function test_deux_pays_peuvent_partager_le_meme_code(): void
    {
        $ci = $this->analyse();

        $sn = Analyse::create(['libelle' => 'Hémoglobine SN', 'unite' => 'g/dL']);
        $sn->forceFill(['pays_code' => 'SN'])->save();
        app(AttributeurCodeAnalyse::class)->attribuer($sn->fresh());

        $this->assertSame('ANA000001', $ci->code);
        $this->assertSame('ANA000001', $sn->fresh()->code);
    }

    public function test_le_milieu_fait_partie_de_l_identite(): void
    {
        // « Glycémie » n'est pas une analyse : deux milieux = deux entrées du catalogue, avec des
        // références différentes. Les fondre reproduirait l'incohérence que §7.3 combat.
        $plasma = $this->analyse(['libelle' => 'Glycémie à jeun', 'milieu_preleve' => 'plasma', 'unite' => 'g/L']);
        $capillaire = $this->analyse(['libelle' => 'Glycémie à jeun', 'milieu_preleve' => 'sang_capillaire', 'unite' => 'g/L']);

        $this->assertNotSame($plasma->code, $capillaire->code);
        $this->assertSame('Glycémie à jeun (Plasma)', $plasma->designation);
        $this->assertSame('Glycémie à jeun (Sang capillaire)', $capillaire->designation);
    }

    public function test_le_code_ne_peut_pas_etre_choisi_par_un_client(): void
    {
        $a = Analyse::create(['libelle' => 'X', 'unite' => 'g/L', 'code' => 'ANA999999', 'pays_code' => 'ZZ']);

        $this->assertNull($a->code);
        $this->assertSame('CI', $a->fresh()->pays_code);
    }

    public function test_le_backfill_est_rejouable(): void
    {
        Analyse::create(['libelle' => 'A', 'unite' => 'g/L']);
        Analyse::create(['libelle' => 'B', 'unite' => 'g/L']);

        $this->artisan('masante:analyses:backfill --dry-run')
            ->expectsOutputToContain('2 analyse(s) recevraient')->assertSuccessful();
        $this->assertSame(2, Analyse::whereNull('code')->count());

        $this->artisan('masante:analyses:backfill')->assertSuccessful();
        $codes = Analyse::orderBy('id')->pluck('code')->all();

        $this->artisan('masante:analyses:backfill')->expectsOutputToContain('rien à faire')->assertSuccessful();
        $this->assertSame($codes, Analyse::orderBy('id')->pluck('code')->all());
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // La résolution des strates — LE CŒUR DE LA DÉCISION « AFFICHER, NE PAS CONCLURE »
    // ─────────────────────────────────────────────────────────────────────────────

    public function test_la_resolution_renvoie_TOUTES_les_strates_applicables(): void
    {
        // Une femme adulte reçoit sa strate standard ET les strates de grossesse. La plateforme ne
        // choisit pas : décider laquelle la concerne serait un jugement clinique.
        $strates = [
            ['sexe' => 'F', 'age_min_jours' => self::ADULTE, 'age_max_jours' => null,
                'etat_physiologique' => 'standard', 'libelle_strate' => 'Femme adulte'],
            ['sexe' => 'F', 'age_min_jours' => self::ADULTE, 'age_max_jours' => null,
                'etat_physiologique' => 'grossesse_t3', 'libelle_strate' => 'Grossesse T3'],
            ['sexe' => 'M', 'age_min_jours' => self::ADULTE, 'age_max_jours' => null,
                'etat_physiologique' => 'standard', 'libelle_strate' => 'Homme adulte'],
        ];

        $retenues = $this->regles()->applicables($strates, 'F', 12000);

        $this->assertCount(2, $retenues);
        // Le standard d'abord, la conditionnelle ensuite.
        $this->assertSame('Femme adulte', $retenues[0]['libelle_strate']);
        $this->assertFalse($retenues[0]['conditionnelle']);
        $this->assertSame('Grossesse T3', $retenues[1]['libelle_strate']);
        $this->assertTrue($retenues[1]['conditionnelle']);
    }

    public function test_la_resolution_ne_renvoie_AUCUN_verdict(): void
    {
        // Vecteur de non-régression sur la décision du propriétaire : la classe pure ne compare
        // jamais un résultat à une plage, donc aucune clé de statut ne peut apparaître.
        $retenues = $this->regles()->applicables(
            [['sexe' => 'tous', 'age_min_jours' => null, 'age_max_jours' => null,
                'etat_physiologique' => 'standard', 'libelle_strate' => 'Tous', 'valeur_min' => 12.0, 'valeur_max' => 16.0]],
            'F',
            12000,
        );

        foreach (['statut', 'statut_norme', 'anormal', 'interpretation', 'verdict'] as $interdit) {
            $this->assertArrayNotHasKey($interdit, $retenues[0], "La résolution a produit une clé « {$interdit} ».");
        }
    }

    public function test_un_age_inconnu_ecarte_les_strates_bornees_en_age(): void
    {
        // Présenter la référence d'un nouveau-né à quelqu'un dont on ignore l'âge serait pire qu'un
        // silence.
        $strates = [
            ['sexe' => 'tous', 'age_min_jours' => 0, 'age_max_jours' => 28,
                'etat_physiologique' => 'nouveau_ne', 'libelle_strate' => 'Nouveau-né'],
            ['sexe' => 'tous', 'age_min_jours' => null, 'age_max_jours' => null,
                'etat_physiologique' => 'standard', 'libelle_strate' => 'Tous âges'],
        ];

        $retenues = $this->regles()->applicables($strates, null, null);

        $this->assertCount(1, $retenues);
        $this->assertSame('Tous âges', $retenues[0]['libelle_strate']);
    }

    public function test_un_sexe_inconnu_ne_garde_que_les_strates_communes(): void
    {
        $strates = [
            ['sexe' => 'M', 'age_min_jours' => null, 'age_max_jours' => null,
                'etat_physiologique' => 'standard', 'libelle_strate' => 'Homme'],
            ['sexe' => 'F', 'age_min_jours' => null, 'age_max_jours' => null,
                'etat_physiologique' => 'standard', 'libelle_strate' => 'Femme'],
            ['sexe' => 'tous', 'age_min_jours' => null, 'age_max_jours' => null,
                'etat_physiologique' => 'standard', 'libelle_strate' => 'Commune'],
        ];

        $retenues = $this->regles()->applicables($strates, null, 12000);

        $this->assertCount(1, $retenues);
        $this->assertSame('Commune', $retenues[0]['libelle_strate']);
    }

    public function test_les_bornes_d_age_sont_inclusives_et_ne_laissent_pas_de_trou(): void
    {
        $strates = [
            ['sexe' => 'tous', 'age_min_jours' => 0, 'age_max_jours' => 28,
                'etat_physiologique' => 'nouveau_ne', 'libelle_strate' => 'Nouveau-né'],
            ['sexe' => 'tous', 'age_min_jours' => 29, 'age_max_jours' => 365,
                'etat_physiologique' => 'standard', 'libelle_strate' => 'Nourrisson'],
        ];

        $this->assertSame('Nouveau-né', $this->regles()->applicables($strates, null, 28)[0]['libelle_strate']);
        $this->assertSame('Nourrisson', $this->regles()->applicables($strates, null, 29)[0]['libelle_strate']);
    }

    public function test_deux_strates_du_meme_etat_qui_se_recouvrent_sont_detectees(): void
    {
        $a = ['sexe' => 'tous', 'age_min_jours' => 0, 'age_max_jours' => 100, 'etat_physiologique' => 'standard'];
        $b = ['sexe' => 'tous', 'age_min_jours' => 50, 'age_max_jours' => 200, 'etat_physiologique' => 'standard'];

        $this->assertTrue($this->regles()->seChevauchent($a, $b));
    }

    public function test_une_strate_standard_et_une_de_grossesse_ne_se_chevauchent_PAS(): void
    {
        // C'est le fonctionnement NORMAL : une femme adulte a une référence standard et une de
        // grossesse. Les signaler comme un conflit rendrait tout catalogue impubliable.
        $a = ['sexe' => 'F', 'age_min_jours' => self::ADULTE, 'age_max_jours' => null, 'etat_physiologique' => 'standard'];
        $b = ['sexe' => 'F', 'age_min_jours' => self::ADULTE, 'age_max_jours' => null, 'etat_physiologique' => 'grossesse_t3'];

        $this->assertFalse($this->regles()->seChevauchent($a, $b));
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // L'API publique — elle montre, elle ne conclut pas
    // ─────────────────────────────────────────────────────────────────────────────

    public function test_l_api_sert_les_references_sans_qualifier(): void
    {
        $analyse = $this->analyse();
        $this->strate($analyse, ['sexe' => 'F', 'age_min_jours' => self::ADULTE, 'libelle_strate' => 'Femme adulte']);

        $reponse = $this->getJson("/api/v1/analyses/{$analyse->id}/references?age_jours=12000&sexe=F")->assertOk();

        $this->assertCount(1, $reponse->json('references'));
        $this->assertSame('12 – 16', $reponse->json('references.0.plage'));
        $this->assertStringContainsString('ne qualifie pas ce résultat', $reponse->json('avertissement'));
        // Aucune clé de verdict dans la réponse entière.
        $this->assertStringNotContainsString('"statut"', $reponse->getContent());
    }

    public function test_l_api_dit_ce_qu_elle_ne_sait_pas(): void
    {
        $analyse = $this->analyse();
        $this->strate($analyse, ['sexe' => 'F', 'age_min_jours' => self::ADULTE, 'libelle_strate' => 'Femme adulte']);

        $reponse = $this->getJson("/api/v1/analyses/{$analyse->id}/references")->assertOk();

        // Ni âge ni sexe : la strate n'est pas renvoyée, et l'écran sait POURQUOI.
        $this->assertCount(0, $reponse->json('references'));
        $this->assertCount(2, $reponse->json('incertitude'));
    }

    public function test_l_api_expose_la_provenance_de_chaque_strate(): void
    {
        $analyse = $this->analyse();
        $this->strate($analyse);

        $reponse = $this->getJson("/api/v1/analyses/{$analyse->id}/references?age_jours=12000&sexe=F")->assertOk();

        $this->assertSame('demonstration', $reponse->json('references.0.source'));
        $this->assertStringContainsString('NON validées', $reponse->json('references.0.source_libelle'));
    }

    public function test_les_enumerations_viennent_du_serveur(): void
    {
        $this->analyse();

        $reponse = $this->getJson('/api/v1/analyses')->assertOk();

        $this->assertSame(Analyses::milieux(), array_column($reponse->json('enumerations.milieux'), 'valeur'));
        $this->assertNotEmpty($reponse->json('enumerations.sources_reference'));
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // Le lien résultat → catalogue
    // ─────────────────────────────────────────────────────────────────────────────

    public function test_un_resultat_sans_lien_reste_accepte(): void
    {
        $this->postJson($this->url(), [
            'type_analyse'   => 'biologique',
            'intitule'       => 'NFS',
            'date_analyse'   => '2026-08-14',
            'resultats_json' => [['parametre' => 'Hémoglobine', 'valeur' => '13,2', 'unite' => 'g/dL']],
        ])->assertCreated();

        $ligne = ResultatAnalyse::first()->resultats_json[0];

        $this->assertArrayNotHasKey('analyse_id', $ligne);
        $this->assertArrayNotHasKey('code_national', $ligne);
    }

    public function test_avec_lien_le_serveur_fige_code_libelle_et_unite(): void
    {
        $analyse = $this->analyse();

        $this->postJson($this->url(), [
            'type_analyse'   => 'biologique',
            'intitule'       => 'NFS',
            'date_analyse'   => '2026-08-14',
            'resultats_json' => [[
                'parametre' => 'Hb', 'valeur' => '13,2', 'analyse_id' => $analyse->id,
                'code_national' => 'ANA999999', 'unite_catalogue' => 'mmol/L',
            ]],
        ])->assertCreated();

        $ligne = ResultatAnalyse::first()->resultats_json[0];

        $this->assertSame('ANA000001', $ligne['code_national']);
        $this->assertSame('Hémoglobine', $ligne['libelle_catalogue']);
        // L'UNITÉ EST LE POINT CRITIQUE : une unité qui changerait après coup rendrait le résultat
        // faux d'un facteur 10 ou 100 sans que rien ne le signale.
        $this->assertSame('g/dL', $ligne['unite_catalogue']);
        $this->assertSame('Hb', $ligne['parametre']);
    }

    public function test_une_analyse_inconnue_est_refusee_avec_un_message_qui_la_nomme(): void
    {
        $this->postJson($this->url(), [
            'type_analyse'   => 'biologique',
            'intitule'       => 'NFS',
            'date_analyse'   => '2026-08-14',
            'resultats_json' => [['parametre' => 'X', 'valeur' => '1', 'analyse_id' => 4242]],
        ])->assertStatus(422)->assertJsonValidationErrors('resultats_json');

        $this->assertSame(0, ResultatAnalyse::count());
    }

    public function test_le_service_ecarte_les_cles_derivees_meme_appele_directement(): void
    {
        // Une couche, un vecteur — leçon de P6.6b : les clés sont déjà écartées par `validate()`,
        // donc c'est ici, en appel direct, que la garde du service doit être éprouvée.
        $resolu = app(\App\Services\Analyse\ServiceLienAnalyse::class)->resoudre([
            ['parametre' => 'X', 'valeur' => '1', 'code_national' => 'ANA999999',
                'libelle_catalogue' => 'Faux', 'unite_catalogue' => 'mmol/L'],
        ]);

        $this->assertArrayNotHasKey('code_national', $resolu[0]);
        $this->assertArrayNotHasKey('libelle_catalogue', $resolu[0]);
        $this->assertArrayNotHasKey('unite_catalogue', $resolu[0]);
    }

    public function test_les_valeurs_figees_ne_bougent_plus(): void
    {
        $analyse = $this->analyse();

        $this->postJson($this->url(), [
            'type_analyse'   => 'biologique',
            'intitule'       => 'NFS',
            'date_analyse'   => '2026-08-14',
            'resultats_json' => [['parametre' => 'Hb', 'valeur' => '13,2', 'analyse_id' => $analyse->id]],
        ])->assertCreated();

        $analyse->update(['libelle' => 'Hémoglobine (révisée)', 'unite' => 'mmol/L']);

        $ligne = ResultatAnalyse::first()->fresh()->resultats_json[0];

        $this->assertSame('Hémoglobine', $ligne['libelle_catalogue']);
        $this->assertSame('g/dL', $ligne['unite_catalogue']);
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // LE PRESCRIPTEUR D'UN RÉSULTAT — CE VECTEUR A ÉTÉ RÉÉCRIT, ET IL FAUT DIRE POURQUOI
    // ─────────────────────────────────────────────────────────────────────────────

    public function test_le_soignant_ne_REMPLACE_PAS_le_prescripteur_declare(): void
    {
        // ═══ CE VECTEUR AFFIRMAIT L'INVERSE, ET IL AVAIT TORT ═══
        //
        // P6.7a le présentait comme le miroir de `ordonnances.medecin_nom` : le soignant qui
        // consigne serait le prescripteur, donc le serveur devait écraser le champ. **C'était
        // faux.** Pour une ordonnance, celui qui écrit EST le prescripteur — rédiger l'ordonnance
        // est l'acte de prescrire. Pour un résultat, celui qui consigne est souvent quelqu'un
        // d'autre : un biologiste, ou un médecin hospitalier qui classe un résultat prescrit par un
        // généraliste de ville.
        //
        // Le serveur inscrivait alors le nom du MAUVAIS médecin — et une affirmation fausse portée
        // par le système est plus difficile à contester qu'une saisie humaine non vérifiée.
        //
        // Le vecteur est donc réécrit pour dire la garantie JUSTE, et non corrigé pour passer
        // (précédent P6.4d). La vérifiabilité passe désormais par un lien au référentiel, éprouvé
        // dans `ReferentielLaboratoiresTest`.
        $this->seed(PortailRolesSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $structure = \App\Models\StructureSanitaire::create([
            'nom' => 'CHU de Cocody', 'type' => 'chu', 'adresse' => 'Abidjan', 'commune' => 'Cocody',
            'latitude' => 5.35, 'longitude' => -3.98, 'actif' => true,
        ]);

        $service = \App\Models\ServiceEtablissement::create([
            'structure_id' => $structure->id, 'nom_service' => 'Laboratoire',
            'specialite' => 'biologie', 'actif' => true,
        ]);

        $compte = User::factory()->create(['structure_id' => $structure->id]);
        $compte->givePermissionTo('dossier.ecrire');

        \App\Models\Medecin::create([
            'structure_id' => $structure->id, 'service_id' => $service->id, 'user_id' => $compte->id,
            'titre' => 'Dr', 'prenom' => 'Aya', 'nom' => 'Koffi',
            'specialite' => 'Biologie', 'profession' => 'biologiste', 'actif' => true,
        ]);

        $entree = app(\App\Services\EcritureSoignantService::class)->ecrire(
            $compte->fresh(),
            $this->membre,
            'qr_scan',
            'resultats-analyses',
            [
                'type_analyse'         => 'biologique',
                'intitule'             => 'NFS',
                'date_analyse'         => '2026-08-14',
                'medecin_prescripteur' => 'Dr Konan, generaliste de ville',
            ],
        );

        $this->assertSame(
            'Dr Konan, generaliste de ville',
            $entree->medecin_prescripteur,
            'Le serveur a remplacé le prescripteur par celui qui consigne — la régression de P6.7a.',
        );
    }

    public function test_le_soignant_voit_aussi_son_lien_au_catalogue_resolu(): void
    {
        // La résolution vaut sur les TROIS chemins : ici celui du soignant.
        $this->seed(PortailRolesSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $analyse = $this->analyse();

        $structure = \App\Models\StructureSanitaire::create([
            'nom' => 'CHU de Cocody', 'type' => 'chu', 'adresse' => 'Abidjan', 'commune' => 'Cocody',
            'latitude' => 5.35, 'longitude' => -3.98, 'actif' => true,
        ]);
        $compte = User::factory()->create(['structure_id' => $structure->id]);
        $compte->givePermissionTo('dossier.ecrire');

        $entree = app(\App\Services\EcritureSoignantService::class)->ecrire(
            $compte->fresh(),
            $this->membre,
            'qr_scan',
            'resultats-analyses',
            [
                'type_analyse'   => 'biologique',
                'intitule'       => 'NFS',
                'date_analyse'   => '2026-08-14',
                'resultats_json' => [['parametre' => 'Hb', 'valeur' => '13,2', 'analyse_id' => $analyse->id]],
            ],
        );

        $this->assertSame('ANA000001', $entree->resultats_json[0]['code_national']);
        $this->assertSame('g/dL', $entree->resultats_json[0]['unite_catalogue']);
    }

    public function test_le_chemin_du_patient_garde_le_prescripteur_saisi(): void
    {
        // Un patient qui recopie un compte rendu papier nomme le médecin qui le lui a remis. Le lui
        // imposer depuis un compte qu'il n'a pas serait absurde.
        $this->postJson($this->url(), [
            'type_analyse'         => 'biologique',
            'intitule'             => 'NFS',
            'date_analyse'         => '2026-08-14',
            'medecin_prescripteur' => 'Dr Untel',
        ])->assertCreated();

        $this->assertSame('Dr Untel', ResultatAnalyse::first()->medecin_prescripteur);
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // Gouvernance et contrôles qualité
    // ─────────────────────────────────────────────────────────────────────────────

    public function test_le_catalogue_est_inscrit_au_registre(): void
    {
        $this->assertTrue(RegistreReferentiels::existe(SourceAnalyses::CODE));
    }

    public function test_l_instantane_porte_les_strates_par_code_national(): void
    {
        $analyse = $this->analyse();
        $this->strate($analyse);

        $strate = collect($this->source()->extraire())->firstWhere('type', 'reference');

        $this->assertNotNull($strate);
        $this->assertSame('ANA000001', $strate['analyse']);
    }

    public function test_une_strate_sans_source_empeche_la_publication(): void
    {
        // LA GARDE CENTRALE : un intervalle biologique sans provenance est une rumeur.
        $analyse = $this->analyse();
        $this->strate($analyse);

        $contenu = $this->source()->extraire();
        $contenu = array_map(static function (array $l): array {
            if (($l['type'] ?? null) === 'reference') {
                $l['source'] = null;
            }

            return $l;
        }, $contenu);

        $erreurs = $this->source()->controlerQualite($contenu);

        $this->assertStringContainsString('source absente ou inconnue', implode(' ', $erreurs));
    }

    public function test_une_analyse_sans_unite_empeche_la_publication(): void
    {
        $analyse = $this->analyse();
        $analyse->forceFill(['unite' => ''])->save();

        $erreurs = $this->source()->controlerQualite($this->source()->extraire());

        $this->assertStringContainsString('unité de mesure absente', implode(' ', $erreurs));
    }

    public function test_deux_strates_qui_se_chevauchent_empechent_la_publication(): void
    {
        $analyse = $this->analyse();
        $this->strate($analyse, ['age_min_jours' => 0, 'age_max_jours' => 100, 'libelle_strate' => 'A']);
        $this->strate($analyse, ['age_min_jours' => 50, 'age_max_jours' => 200, 'libelle_strate' => 'B']);

        $erreurs = $this->source()->controlerQualite($this->source()->extraire());

        $this->assertStringContainsString('se chevauchent', implode(' ', $erreurs));
    }

    public function test_un_catalogue_complet_ne_produit_aucune_erreur(): void
    {
        $analyse = $this->analyse();
        $this->strate($analyse, ['sexe' => 'F', 'age_min_jours' => self::ADULTE, 'libelle_strate' => 'Femme adulte']);
        $this->strate($analyse, ['sexe' => 'M', 'age_min_jours' => self::ADULTE, 'libelle_strate' => 'Homme adulte',
            'valeur_min' => 13.0, 'valeur_max' => 17.0]);

        $this->assertSame([], $this->source()->controlerQualite($this->source()->extraire()));
    }

    public function test_le_moteur_refuse_une_borne_basse_superieure_a_la_borne_haute(): void
    {
        // Le contrôle qualité ne joue qu'à la publication ; une strate incohérente pourrait donc
        // s'afficher pendant des semaines à côté d'un résultat réel. C'est le moteur qui refuse.
        $analyse = $this->analyse();

        $this->expectException(\Illuminate\Database\QueryException::class);

        \DB::table('analyse_references')->insert([
            'analyse_id' => $analyse->id, 'sexe' => 'tous',
            'etat_physiologique' => 'standard', 'valeur_min' => 20.0, 'valeur_max' => 5.0,
            'libelle_strate' => 'Incohérente', 'source' => 'demonstration',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_les_enumerations_de_la_base_et_la_source_unique_ne_divergent_pas(): void
    {
        // Convention de P6.5a : la migration recopie ses listes, ce test est le prix de la
        // duplication — sans lui, ajouter un milieu dans `Analyses` sans migration produirait une
        // valeur que le formulaire propose et que la base refuse.
        foreach (Analyses::milieux() as $milieu) {
            $a = $this->analyse(['libelle' => "Milieu {$milieu}", 'milieu_preleve' => $milieu]);
            $this->assertSame($milieu, $a->milieu_preleve);
        }

        foreach (Analyses::categories() as $categorie) {
            $a = $this->analyse(['libelle' => "Cat {$categorie}", 'categorie' => $categorie]);
            $this->assertSame($categorie, $a->categorie);
        }

        $analyse = $this->analyse(['libelle' => 'Strates']);

        foreach (Analyses::etats() as $etat) {
            $this->strate($analyse, ['etat_physiologique' => $etat, 'libelle_strate' => "E {$etat}"]);
        }

        foreach (Analyses::sourcesReference() as $source) {
            $this->strate($analyse, ['source' => $source, 'age_min_jours' => random_int(1, 40000),
                'libelle_strate' => "S {$source}"]);
        }

        $this->assertSame(
            count(Analyses::etats()) + count(Analyses::sourcesReference()),
            $analyse->references()->count(),
        );
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // La garde d'écriture du catalogue national
    // ─────────────────────────────────────────────────────────────────────────────

    public function test_un_gestionnaire_d_etablissement_n_ouvre_PAS_le_catalogue(): void
    {
        $this->seed(PortailRolesSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        // Un rôle réel, jamais `admin_ivoirsante` qui reçoit toutes les permissions — un vecteur
        // bâti sur lui aurait été vert quoi qu'il arrive (leçon de P6.6a).
        $agent = User::factory()->create();
        $agent->assignRole('gestionnaire_etablissement');

        $this->actingAs($agent->fresh(), 'web')
            ->get(route('portail.analyses.index'))
            ->assertForbidden();
    }

    public function test_la_permission_du_catalogue_ouvre_l_ecran(): void
    {
        $this->seed(PortailRolesSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->analyse();

        $agent = User::factory()->create();
        $agent->givePermissionTo('analyse.referentiel');

        $this->actingAs($agent->fresh(), 'web')
            ->get(route('portail.analyses.index'))
            ->assertOk();
    }

    public function test_le_portail_refuse_une_strate_sans_borne(): void
    {
        $this->seed(PortailRolesSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $analyse = $this->analyse();

        $agent = User::factory()->create();
        $agent->givePermissionTo('analyse.referentiel');

        $this->actingAs($agent->fresh(), 'web')
            ->post(route('portail.analyses.strates.ajouter', $analyse), [
                'sexe' => 'tous', 'etat_physiologique' => 'standard',
                'libelle_strate' => 'Vide', 'source' => 'demonstration',
            ])
            ->assertSessionHasErrors('valeur_min');

        $this->assertSame(0, $analyse->references()->count());
    }

    public function test_le_seeder_de_demonstration_etiquette_toutes_ses_strates(): void
    {
        $this->seed(\Database\Seeders\CatalogueAnalysesSeeder::class);

        $this->assertGreaterThan(0, Analyse::count());
        // AUCUNE strate ne doit prétendre venir d'une autorité.
        $this->assertSame(
            0,
            \App\Models\AnalyseReference::where('source', '!=', 'demonstration')->count(),
            'Une strate du jeu de démonstration prétend venir d\'une autre source.',
        );
        $this->assertSame([], $this->source()->controlerQualite($this->source()->extraire()));
    }
}
