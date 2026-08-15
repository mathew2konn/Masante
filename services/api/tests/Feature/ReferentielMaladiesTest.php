<?php

namespace Tests\Feature;

use App\Models\AlerteEpidemique;
use App\Models\Antecedent;
use App\Models\LibelleMaladie;
use App\Models\Maladie;
use App\Models\MembreFamille;
use App\Models\StructureSanitaire;
use App\Models\SurveillanceMaladie;
use App\Models\User;
use App\Models\Vaccin;
use App\Services\FicheVitaleService;
use App\Services\Maladie\ServiceLienMaladie;
use App\Services\Referentiel\EmpreinteReferentiel;
use App\Services\Referentiel\SourceMaladies;
use App\Services\Referentiel\SourceVaccins;
use Database\Seeders\PortailRolesSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\GouverneUnReferentiel;
use Tests\TestCase;

/**
 * P6.8c — Référentiel national des maladies (CDC_09 §8, étape 8 du §14).
 *
 * Ce que ces vecteurs doivent tenir :
 *
 *  · une maladie N'APPARTIENT À AUCUN PAYS — le code est unique globalement (décision E2), et ce
 *    qui est national est la SURVEILLANCE, dans sa propre table ;
 *  · le libellé officiel vit sur la ligne et NULLE PART AILLEURS — le moteur refuse un libellé
 *    alternatif qui le recopie, et le contrôle qualité attrape le sens inverse ;
 *  · le serveur NE DEVINE JAMAIS une maladie depuis un texte libre : ce serait un diagnostic posé
 *    par une machine (CDC_00 §4) ;
 *  · `antecedents.description` N'EST JAMAIS RÉÉCRITE — le lien s'ajoute à côté des mots du patient
 *    (leçon P6.7b, où la réécriture du prescripteur inscrivait le nom du mauvais médecin) ;
 *  · les valeurs figées viennent de la VERSION PUBLIÉE, jamais de la table ni du client ;
 *  · trois vecteurs en miroir sur l'empreinte, aucun ne suffisant seul : une alerte ne fait PAS
 *    diverger le référentiel des maladies, un libellé corrigé SI, et un vaccin rattaché fait
 *    changer l'empreinte du référentiel des VACCINS — conséquence annoncée avant d'avoir codé ;
 *  · l'alerte épidémique garde une porte ouverte (décision E4) et l'écart est COMPTÉ.
 */
class ReferentielMaladiesTest extends TestCase
{
    use GouverneUnReferentiel;
    use RefreshDatabase;

    private function source(): SourceMaladies
    {
        return new SourceMaladies();
    }

    /** Une maladie du contenu de travail, avec son code national posé de force. */
    private function maladie(array $remplacements = []): Maladie
    {
        $maladie = new Maladie();
        $maladie->fill(array_merge([
            'libelle' => 'Paludisme',
            'source'  => 'demonstration',
            'actif'   => true,
        ], array_diff_key($remplacements, array_flip(['code']))));

        $maladie->forceFill(['code' => $remplacements['code'] ?? 'MAL000001'])->save();

        return $maladie->fresh();
    }

    /** Un compte portant la permission nationale, accordée nominativement. */
    private function autorite(): User
    {
        $this->seed(PortailRolesSeeder::class);

        $user = User::factory()->create();
        $user->assignRole('gestionnaire_etablissement');
        $user->givePermissionTo('maladie.referentiel');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user->fresh();
    }

    private function membre(): MembreFamille
    {
        return MembreFamille::factory()->for(User::factory()->create())->create([
            'nom' => 'Kouassi', 'prenom' => 'Aya', 'date_naissance' => '1990-05-04', 'sexe' => 'F',
        ]);
    }

    /** Met en vigueur le référentiel des maladies et renvoie le service de lien, neuf. */
    private function publierEtLier(): ServiceLienMaladie
    {
        $this->publierReferentiel(SourceMaladies::CODE);

        return app(ServiceLienMaladie::class);
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // E2 — une maladie n'appartient à aucun pays
    // ─────────────────────────────────────────────────────────────────────────────

    public function test_le_code_est_unique_globalement(): void
    {
        $this->maladie(['code' => 'MAL000001']);

        // Rupture assumée avec ETS/PRO/MED/ANA/VAC : il n'y a pas de couple `(pays, code)` à
        // partager, parce qu'une maladie n'est pas un objet national.
        $this->expectException(QueryException::class);
        $this->maladie(['code' => 'MAL000001', 'libelle' => 'Choléra']);
    }

    public function test_deux_maladies_ne_peuvent_pas_porter_le_meme_libelle(): void
    {
        $this->maladie(['libelle' => 'Paludisme']);

        // Elles seraient indiscernables dans la liste d'une alerte, et le rattachement par égalité
        // exacte du backfill deviendrait ambigu.
        $this->expectException(QueryException::class);
        $this->maladie(['code' => 'MAL000002', 'libelle' => 'Paludisme']);
    }

    public function test_la_surveillance_est_nationale_et_unique_par_pays(): void
    {
        $maladie = $this->maladie();

        $maladie->surveillances()->create([
            'pays_code' => 'CI', 'declaration_obligatoire' => true,
            'surveillance_prioritaire' => true, 'source' => 'demonstration',
        ]);
        $maladie->surveillances()->create([
            'pays_code' => 'SN', 'declaration_obligatoire' => false,
            'surveillance_prioritaire' => true, 'source' => 'demonstration',
        ]);

        $this->assertSame(2, $maladie->surveillances()->count());

        // Deux lignes pour un même pays diraient deux choses sur la même question de santé publique.
        $this->expectException(QueryException::class);
        $maladie->surveillances()->create([
            'pays_code' => 'CI', 'declaration_obligatoire' => false,
            'surveillance_prioritaire' => false, 'source' => 'demonstration',
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // Le libellé officiel vit sur la ligne, et nulle part ailleurs
    // ─────────────────────────────────────────────────────────────────────────────

    public function test_le_moteur_refuse_un_libelle_alternatif_identique_au_libelle_officiel(): void
    {
        $maladie = $this->maladie(['libelle' => 'Paludisme']);

        // La même chaîne stockée à deux endroits, c'est un second endroit à corriger au premier
        // renommage — et c'est celui-là qu'on oublie. Le déclencheur le rend impossible.
        $this->expectException(QueryException::class);
        $maladie->libelles()->create([
            'langue' => 'fr', 'libelle' => 'Paludisme', 'principal' => false,
            'source' => 'demonstration',
        ]);
    }

    public function test_un_libelle_etranger_proche_du_libelle_officiel_est_accepte(): void
    {
        $maladie = $this->maladie(['libelle' => 'Choléra']);

        // ═══ CE VECTEUR VIENT D'UN DÉFAUT TROUVÉ AU G2 LIVE, PAS ICI ═══
        //
        // Écrit en `=` simple, le déclencheur comparait avec la COLLATION de la colonne — insensible
        // aux accents sous MySQL 8. « Cholera » et « Choléra » y étaient ÉGAUX, et le seeder de
        // démonstration s'arrêtait sur `ERROR 1644` en enregistrant le libellé anglais.
        //
        // SQLite compare octet à octet : ce vecteur passait AVANT comme APRÈS le correctif. Il est
        // gardé pour dire l'intention — la preuve, elle, est au G2 live, et c'est écrit tel quel
        // dans le guide plutôt que présenté comme une couverture de test.
        $maladie->libelles()->create([
            'langue' => 'en', 'libelle' => 'Cholera', 'principal' => true, 'source' => 'demonstration',
        ]);

        $this->assertSame(1, $maladie->libelles()->count());
        $this->assertSame([], $this->source()->controlerQualite($this->source()->extraire()));
    }

    public function test_un_synonyme_dans_une_autre_casse_est_accepte_et_le_controle_qualite_l_attrape(): void
    {
        $maladie = $this->maladie(['libelle' => 'Paludisme']);

        // Le déclencheur compare à l'octet près : « paludisme » passe. C'est le contrôle qualité qui
        // refuse de le PUBLIER — deux gardes, deux publics, aucune ne rattrape l'autre.
        $maladie->libelles()->create([
            'langue' => 'fr', 'libelle' => 'paludisme', 'principal' => false,
            'source' => 'demonstration',
        ]);

        $erreurs = $this->source()->controlerQualite($this->source()->extraire());

        $this->assertNotEmpty(array_filter(
            $erreurs,
            static fn (string $e): bool => str_contains($e, 'recopie le libellé officiel'),
        ));
    }

    public function test_un_synonyme_en_langue_pivot_ne_peut_pas_etre_principal(): void
    {
        $maladie = $this->maladie();
        $maladie->libelles()->create([
            'langue' => 'fr', 'libelle' => 'palu', 'principal' => true, 'source' => 'demonstration',
        ]);

        $erreurs = $this->source()->controlerQualite($this->source()->extraire());

        // Afficher « palu » à la place de « Paludisme » rétablirait la concurrence de libellés que
        // le schéma évite.
        $this->assertNotEmpty(array_filter(
            $erreurs,
            static fn (string $e): bool => str_contains($e, 'langue pivot'),
        ));
    }

    public function test_une_langue_sans_principal_ou_avec_deux_principaux_est_refusee(): void
    {
        $sans = $this->maladie(['code' => 'MAL000001', 'libelle' => 'Paludisme']);
        $sans->libelles()->create([
            'langue' => 'en', 'libelle' => 'Malaria', 'principal' => false, 'source' => 'demonstration',
        ]);

        $deux = $this->maladie(['code' => 'MAL000002', 'libelle' => 'Choléra']);
        $deux->libelles()->create([
            'langue' => 'en', 'libelle' => 'Cholera', 'principal' => true, 'source' => 'demonstration',
        ]);
        $deux->libelles()->create([
            'langue' => 'en', 'libelle' => 'Asiatic cholera', 'principal' => true, 'source' => 'demonstration',
        ]);

        $erreurs = $this->source()->controlerQualite($this->source()->extraire());

        $this->assertNotEmpty(array_filter($erreurs, static fn ($e) => str_contains($e, 'aucun libellé principal')));
        $this->assertNotEmpty(array_filter($erreurs, static fn ($e) => str_contains($e, '2 libellés principaux')));
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // Contrôles qualité
    // ─────────────────────────────────────────────────────────────────────────────

    public function test_le_controle_qualite_accepte_le_jeu_de_demonstration(): void
    {
        $this->seed(\Database\Seeders\MaladieSeeder::class);
        $this->artisan('masante:maladies:backfill')->assertSuccessful();

        $this->assertSame([], $this->source()->controlerQualite($this->source()->extraire()));
    }

    public function test_le_controle_qualite_n_exige_aucun_code_cim(): void
    {
        $this->maladie();

        // L'exiger rendrait le référentiel impubliable dès le premier jour : aucun code CIM n'a été
        // chargé, et aucun n'a été inventé. L'absence est comptée à l'écran, pas transformée en mur.
        $erreurs = $this->source()->controlerQualite($this->source()->extraire());

        $this->assertSame([], $erreurs);
        $this->assertNull(Maladie::first()->code_cim10);
    }

    public function test_une_maladie_sans_code_national_ne_peut_pas_etre_publiee(): void
    {
        $maladie = new Maladie();
        $maladie->fill(['libelle' => 'Paludisme', 'source' => 'demonstration', 'actif' => true])->save();

        $erreurs = $this->source()->controlerQualite($this->source()->extraire());

        $this->assertNotEmpty(array_filter($erreurs, static fn ($e) => str_contains($e, 'code national absent')));
    }

    public function test_une_surveillance_sans_provenance_connue_est_refusee(): void
    {
        $maladie = $this->maladie();
        $contenu = $this->source()->extraire();

        $contenu[0]['surveillance'] = [[
            'pays_code' => 'CI', 'declaration_obligatoire' => true,
            'surveillance_prioritaire' => true, 'source' => 'ouï-dire',
        ]];

        // Une déclaration obligatoire est une obligation LÉGALE : la publier sans dire d'où elle
        // vient exposerait un professionnel à une obligation dont personne ne peut citer la source.
        $this->assertNotEmpty(array_filter(
            $this->source()->controlerQualite($contenu),
            static fn ($e) => str_contains($e, 'provenance'),
        ));
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // Le backfill
    // ─────────────────────────────────────────────────────────────────────────────

    public function test_le_backfill_attribue_les_codes_et_le_rejeu_n_en_attribue_aucun(): void
    {
        $this->seed(\Database\Seeders\MaladieSeeder::class);

        $total = Maladie::count();
        $this->assertSame($total, Maladie::whereNull('code')->count());

        // L'aperçu ANNONCE ce que fera le passage réel, et n'écrit rien (leçon du G2 de P6.8a, où
        // un `--dry-run` annonçait 0 avant que le passage réel n'en rattache 28).
        $this->artisan('masante:maladies:backfill --dry-run')
            ->expectsOutputToContain("{$total} maladie(s) recevraient un code national.")
            ->assertSuccessful();
        $this->assertSame($total, Maladie::whereNull('code')->count());

        $this->artisan('masante:maladies:backfill')->assertSuccessful();
        $this->assertSame(0, Maladie::whereNull('code')->count());
        $this->assertSame('MAL000001', Maladie::orderBy('id')->first()->code);

        $codes = Maladie::pluck('code');
        $this->artisan('masante:maladies:backfill')->assertSuccessful();
        $this->assertSame($codes->all(), Maladie::pluck('code')->all());
    }

    public function test_le_backfill_rattache_une_alerte_par_egalite_exacte_et_jamais_par_ressemblance(): void
    {
        $this->maladie(['libelle' => 'Choléra', 'code' => null]);

        $exacte = AlerteEpidemique::create([
            'commune' => 'Cocody', 'titre' => 'A', 'description' => 'D', 'maladie' => 'choléra',
            'niveau_alerte' => 'alerte', 'source' => 'MSCI', 'date_debut' => now()->toDateString(),
        ]);
        $voisine = AlerteEpidemique::create([
            'commune' => 'Cocody', 'titre' => 'B', 'description' => 'D', 'maladie' => 'Cholécystite',
            'niveau_alerte' => 'alerte', 'source' => 'MSCI', 'date_debut' => now()->toDateString(),
        ]);

        $this->artisan('masante:maladies:backfill')->assertSuccessful();

        // « choléra » ≠ « Choléra » à l'octet près, mais c'est la MÊME chaîne une fois la casse et
        // les accents normalisés : c'est une résolution d'identité.
        $this->assertNotNull($exacte->fresh()->maladie_id);
        // « Cholécystite » ressemble à « Choléra ». Mesurer cette distance pour décider laquelle
        // l'agent voulait dire serait DEVINER une maladie.
        $this->assertNull($voisine->fresh()->maladie_id);
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // Les trois vecteurs en miroir — aucun ne suffit seul
    // ─────────────────────────────────────────────────────────────────────────────

    public function test_publier_une_alerte_epidemique_ne_fait_PAS_diverger_le_referentiel(): void
    {
        $this->maladie();
        $avant = EmpreinteReferentiel::duContenu($this->source()->extraire());

        AlerteEpidemique::create([
            'commune' => 'Cocody', 'titre' => 'Recrudescence', 'description' => 'D',
            'maladie' => 'Paludisme', 'niveau_alerte' => 'alerte', 'source' => 'MSCI',
            'date_debut' => now()->toDateString(),
        ]);

        // C'est ce qui autorise la projection à prendre la LIGNE ENTIÈRE : rien n'écrit
        // automatiquement dans `maladies` (question reposée, pas recopiée de P6.4a).
        $this->assertSame($avant, EmpreinteReferentiel::duContenu($this->source()->extraire()));
    }

    public function test_corriger_le_libelle_officiel_fait_diverger_le_referentiel(): void
    {
        $maladie = $this->maladie(['libelle' => 'Fievre typhoide']);
        $avant   = EmpreinteReferentiel::duContenu($this->source()->extraire());

        $maladie->update(['libelle' => 'Fièvre typhoïde']);

        $this->assertNotSame($avant, EmpreinteReferentiel::duContenu($this->source()->extraire()));
    }

    public function test_rattacher_un_vaccin_fait_changer_l_empreinte_du_referentiel_des_vaccins(): void
    {
        $vaccin = Vaccin::create([
            'libelle' => 'BCG', 'nb_doses' => 1, 'statut_marche' => 'disponible', 'actif' => true,
        ]);
        $vaccin->forceFill(['code' => 'VAC000001', 'pays_code' => 'CI'])->save();

        $sourceVaccins = new SourceVaccins();
        $avant = EmpreinteReferentiel::duContenu($sourceVaccins->extraire());

        $vaccin->maladies()->attach($this->maladie(['libelle' => 'Tuberculose'])->id);

        // CONSÉQUENCE ASSUMÉE ET DITE AVANT DE CODER : les codes des maladies entrent dans la
        // projection des vaccins. Ce n'est pas une dérive — même cas que `forme_juridique` en P6.4d.
        $this->assertNotSame($avant, EmpreinteReferentiel::duContenu($sourceVaccins->extraire()));
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // Le lien de l'alerte épidémique (décision E4)
    // ─────────────────────────────────────────────────────────────────────────────

    public function test_le_libelle_d_une_alerte_liee_est_repris_du_referentiel_et_non_du_client(): void
    {
        $maladie = $this->maladie(['libelle' => 'Paludisme']);
        $lien    = $this->publierEtLier();

        $resolu = $lien->resoudreAlerte([
            'maladie_id' => $maladie->id,
            'maladie'    => 'Ce que je veux',
            'maladie_code' => 'MAL999999',
        ]);

        $this->assertSame('Paludisme', $resolu['maladie']);
        $this->assertSame('MAL000001', $resolu['maladie_code']);
    }

    public function test_une_alerte_peut_nommer_une_maladie_absente_du_referentiel(): void
    {
        $this->maladie();
        $lien = $this->publierEtLier();

        // Décision E4 : une maladie émergente n'est dans aucune nomenclature au moment où elle
        // émerge. Bloquer coûterait plus que la faute de frappe qu'on évite.
        $resolu = $lien->resoudreAlerte(['maladie' => 'Pneumonie atypique d\'origine inconnue']);

        $this->assertNull($resolu['maladie_id']);
        $this->assertSame('Pneumonie atypique d\'origine inconnue', $resolu['maladie']);
    }

    public function test_une_maladie_non_publiee_est_refusee_bruyamment_au_lien(): void
    {
        $maladie = $this->maladie();

        // Aucune version en vigueur : accepter le lien en lisant la table rendrait la gouvernance
        // décorative ; l'ignorer en silence ferait croire à un rattachement qui n'a pas eu lieu.
        $this->expectException(ValidationException::class);
        app(ServiceLienMaladie::class)->resoudreAlerte(['maladie_id' => $maladie->id]);
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // Le lien de l'antécédent — le consommateur clinique
    // ─────────────────────────────────────────────────────────────────────────────

    public function test_le_serveur_ne_devine_jamais_une_maladie_depuis_la_description(): void
    {
        $this->maladie(['libelle' => 'Diabète sucré']);
        $lien = $this->publierEtLier();

        $resolu = $lien->resoudreAntecedent([
            'type' => 'maladie_chronique',
            'description' => 'Diabète sucré',
        ]);

        // Le libellé est EXACTEMENT celui du référentiel, et pourtant rien n'est rattaché :
        // rapprocher serait un diagnostic posé par une machine (CDC_00 §4).
        $this->assertArrayNotHasKey('maladie_code', $resolu);
        $this->assertArrayNotHasKey('maladie_id', $resolu);
    }

    public function test_la_description_du_patient_n_est_jamais_reecrite(): void
    {
        $maladie = $this->maladie(['libelle' => 'Diabète sucré']);
        $lien    = $this->publierEtLier();

        $resolu = $lien->resoudreAntecedent([
            'type' => 'maladie_chronique',
            'description' => 'DT2 découvert en 2019, suivi au CHU',
            'maladie_id' => $maladie->id,
        ]);

        // LEÇON DE P6.7b : le lien s'ajoute À CÔTÉ des mots du patient. Les remplacer ferait dire
        // au carnet quelque chose que personne n'a écrit.
        $this->assertSame('DT2 découvert en 2019, suivi au CHU', $resolu['description']);
        $this->assertSame('MAL000001', $resolu['maladie_code']);
        $this->assertSame('Diabète sucré', $resolu['maladie_libelle']);
    }

    public function test_le_client_ne_peut_pas_declarer_les_valeurs_figees_par_le_validateur(): void
    {
        $maladie = $this->maladie();
        $this->publierEtLier();
        $membre = $this->membre();

        $this->actingAs($membre->user, 'sanctum')
            ->postJson("/api/v1/membres/{$membre->id}/antecedents", [
                'type' => 'maladie_chronique',
                'description' => 'Paludisme à répétition',
                'maladie_id' => $maladie->id,
                'maladie_code' => 'MAL999999',
                'maladie_libelle' => 'Ce que je veux',
            ])
            ->assertCreated();

        $antecedent = Antecedent::first();
        $this->assertSame('MAL000001', $antecedent->maladie_code);
        $this->assertSame('Paludisme', $antecedent->maladie_libelle);
    }

    public function test_un_code_declare_SANS_lien_est_efface_par_le_service(): void
    {
        $this->maladie();
        $lien = $this->publierEtLier();

        // ═══ CE VECTEUR EXISTE PARCE QU'UNE MUTATION A MONTRÉ QUE L'AUTRE NE PROUVAIT RIEN ═══
        //
        // Le vecteur ci-dessus envoie AUSSI `maladie_id`, donc le service réécrit les deux valeurs
        // de toute façon : retirer l'effacement du service le laissait vert. Il testait le chemin
        // nominal, pas la garde.
        //
        // LE CAS QUE LA GARDE COUVRE RÉELLEMENT est celui-ci : un code déclaré SANS lien. Sans
        // l'effacement, la ligne porterait un code national pointant vers rien, sans `maladie_id`
        // pour le contredire — un rattachement qui a l'air vrai et que personne n'a vérifié.
        $resolu = $lien->resoudreAntecedent([
            'type' => 'maladie_chronique',
            'description' => 'Paludisme à répétition',
            'maladie_code' => 'MAL999999',
            'maladie_libelle' => 'Ce que je veux',
        ]);

        $this->assertArrayNotHasKey('maladie_code', $resolu);
        $this->assertArrayNotHasKey('maladie_libelle', $resolu);
    }

    public function test_un_code_declare_SANS_lien_est_efface_aussi_sur_une_alerte(): void
    {
        $this->maladie();
        $lien = $this->publierEtLier();

        $resolu = $lien->resoudreAlerte([
            'maladie'      => 'Pneumonie atypique',
            'maladie_code' => 'MAL999999',
        ]);

        $this->assertNull($resolu['maladie_id']);
        $this->assertArrayNotHasKey('maladie_code', $resolu);
    }

    public function test_le_client_ne_peut_pas_declarer_les_valeurs_figees_par_le_service(): void
    {
        $maladie = $this->maladie();
        $lien    = $this->publierEtLier();

        // VECTEUR DÉDOUBLÉ, leçon de la mutation de P6.6b : le vecteur du validateur reste vert même
        // si le service cesse d'écraser ces clés, parce que `validate()` écarte déjà ce qui n'est pas
        // déclaré. Celui-ci appelle le service DIRECTEMENT, comme le ferait un import.
        $resolu = $lien->resoudreAntecedent([
            'type' => 'maladie_chronique',
            'description' => 'x',
            'maladie_id' => $maladie->id,
            'maladie_code' => 'MAL999999',
            'maladie_libelle' => 'Ce que je veux',
        ]);

        $this->assertSame('MAL000001', $resolu['maladie_code']);
        $this->assertSame('Paludisme', $resolu['maladie_libelle']);
    }

    public function test_corriger_le_referentiel_ne_reecrit_pas_un_antecedent_deja_lie(): void
    {
        $maladie = $this->maladie(['libelle' => 'Diabète sucré']);
        $lien    = $this->publierEtLier();
        $membre  = $this->membre();

        $antecedent = $membre->antecedents()->create($lien->resoudreAntecedent([
            'type' => 'maladie_chronique', 'description' => 'suivi CHU', 'maladie_id' => $maladie->id,
        ]));

        $maladie->update(['libelle' => 'Diabète sucré de type 2']);
        $this->republierReferentiel(SourceMaladies::CODE, 'Précision du libellé officiel.');

        // FIGÉ veut dire figé : une correction du référentiel ne réécrit pas ce qui a été inscrit
        // au carnet (précédents P6.6b, P6.7b, P6.8b).
        $this->assertSame('Diabète sucré', $antecedent->fresh()->maladie_libelle);
    }

    public function test_le_put_repasse_par_la_verification_du_lien(): void
    {
        $maladie = $this->maladie();
        $this->publierEtLier();
        $membre = $this->membre();

        $antecedent = $membre->antecedents()->create([
            'type' => 'maladie_chronique', 'description' => 'x',
        ]);

        // LE DÉFAUT TROUVÉ EN PASSANT PAR P6.8b : une garantie qui ne vaut qu'à la création n'en
        // est pas une — un `PUT` pouvait poser un lien sans le vérifier.
        $this->actingAs($membre->user, 'sanctum')
            ->putJson("/api/v1/membres/{$membre->id}/antecedents/{$antecedent->id}", [
                'type' => 'maladie_chronique',
                'description' => 'x',
                'maladie_id' => $maladie->id,
                'maladie_code' => 'MAL999999',
            ])
            ->assertOk();

        $this->assertSame('MAL000001', $antecedent->fresh()->maladie_code);
    }

    public function test_une_maladie_desactivee_est_signalee_jamais_refusee(): void
    {
        $maladie = $this->maladie(['actif' => false]);
        // Une entrée active à côté : publier un référentiel entièrement désactivé est refusé par le
        // contrôle qualité — il ne permettrait plus de rattacher quoi que ce soit.
        $this->maladie(['code' => 'MAL000002', 'libelle' => 'Choléra']);
        $lien = $this->publierEtLier();

        // Refuser d'enregistrer un antécédent RÉEL parce que l'entrée a été retirée du référentiel
        // effacerait un fait médical (CDC_00 §4). Décision identique aux médicaments et vaccins.
        $resolu = $lien->resoudreAntecedent([
            'type' => 'maladie_chronique', 'description' => 'x', 'maladie_id' => $maladie->id,
        ]);

        $this->assertSame('MAL000001', $resolu['maladie_code']);
        $this->assertNotEmpty($lien->avertissements('MAL000001'));
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // La fiche vitale
    // ─────────────────────────────────────────────────────────────────────────────

    public function test_la_fiche_vitale_joint_le_code_national_sans_remplacer_les_mots_du_patient(): void
    {
        $maladie = $this->maladie(['libelle' => 'Drépanocytose']);
        $lien    = $this->publierEtLier();
        $membre  = $this->membre();

        $membre->antecedents()->create($lien->resoudreAntecedent([
            'type' => 'maladie_chronique',
            'description' => 'drépano SS, transfusions régulières',
            'maladie_id' => $maladie->id,
        ]));

        $fiche = app(FicheVitaleService::class)->pour($membre->fresh());
        $ligne = $fiche['maladies_chroniques'][0];

        $this->assertSame('drépano SS, transfusions régulières', $ligne['libelle']);
        $this->assertSame('MAL000001', $ligne['code_national']);
        $this->assertSame('Drépanocytose', $ligne['libelle_reference']);
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // La diffusion
    // ─────────────────────────────────────────────────────────────────────────────

    public function test_l_api_refuse_bruyamment_avant_la_premiere_publication(): void
    {
        $this->maladie();

        // Un repli sur la table laisserait un oubli de publication INVISIBLE : tout fonctionnerait,
        // et personne ne saurait la garantie inactive (décision L1).
        $this->getJson('/api/v1/maladies')->assertStatus(503);
    }

    public function test_l_api_sert_la_version_publiee_et_la_cite(): void
    {
        $this->maladie();
        $version = $this->publierReferentiel(SourceMaladies::CODE);

        $this->getJson('/api/v1/maladies')
            ->assertOk()
            ->assertJsonPath('version', $version)
            ->assertJsonPath('maladies.0.libelle', 'Paludisme')
            ->assertJsonPath('maladies.0.code', 'MAL000001');
    }

    public function test_un_synonyme_retrouve_la_maladie(): void
    {
        $maladie = $this->maladie(['libelle' => 'Paludisme']);
        $maladie->libelles()->create([
            'langue' => 'fr', 'libelle' => 'palu', 'principal' => false, 'source' => 'demonstration',
        ]);
        $this->publierReferentiel(SourceMaladies::CODE);

        // C'est le service que rend le multilingue du §8 : l'agent tape ce qu'il dit, pas ce que le
        // référentiel écrit.
        $this->getJson('/api/v1/maladies?q=palu')
            ->assertOk()
            ->assertJsonPath('maladies.0.libelle', 'Paludisme');

        // Et sans accent ni casse : « Fièvre » se tape rarement avec son accent grave.
        $this->getJson('/api/v1/maladies?q=PALUDISME')->assertJsonCount(1, 'maladies');
    }

    public function test_un_update_direct_reste_sans_effet_avant_publication(): void
    {
        $maladie = $this->maladie(['libelle' => 'Paludisme']);
        $this->publierReferentiel(SourceMaladies::CODE);

        \DB::table('maladies')->where('id', $maladie->id)->update(['libelle' => 'Autre chose']);
        $this->simulerNouvelleRequete();

        // C'est le but du §1.2.4, et la leçon de L1+L2 : corriger par un `UPDATE` direct n'a aucun
        // effet avant publication.
        $this->getJson('/api/v1/maladies')->assertJsonPath('maladies.0.libelle', 'Paludisme');
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // Le portail
    // ─────────────────────────────────────────────────────────────────────────────

    public function test_l_ecriture_du_referentiel_est_fermee_a_qui_publie_les_alertes(): void
    {
        $this->seed(PortailRolesSeeder::class);

        $user = User::factory()->create([
            'structure_id' => StructureSanitaire::create([
                'nom' => 'CHU', 'type' => 'chu', 'adresse' => 'A', 'commune' => 'Cocody',
                'latitude' => 5.35, 'longitude' => -3.98, 'actif' => true,
            ])->id,
        ]);
        $user->assignRole('gestionnaire_etablissement');
        $user->givePermissionTo('sante_publique.manage');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        // L'auteur d'une alerte ne décide pas de ce QU'EST une maladie : ce sont deux autorités
        // différentes, et les confondre ferait du vocabulaire national la somme des bulletins.
        $this->actingAs($user->fresh())->get('/portail/maladies')->assertForbidden();
    }

    public function test_l_autorite_habilitee_accede_et_le_code_national_n_est_jamais_choisi(): void
    {
        $autorite = $this->autorite();

        $this->actingAs($autorite)->get('/portail/maladies')->assertOk();

        $this->actingAs($autorite)->post('/portail/maladies', [
            'libelle' => 'Fièvre jaune',
            'source'  => 'autorite_nationale',
            'code'    => 'MAL999999',
        ])->assertRedirect();

        $maladie = Maladie::where('libelle', 'Fièvre jaune')->first();
        $this->assertNotNull($maladie);
        // Hors `$fillable` : un client ne choisit pas un code national, il le reçoit.
        $this->assertNull($maladie->code);
    }

    public function test_le_formulaire_refuse_un_libelle_alternatif_identique_a_l_officiel(): void
    {
        $autorite = $this->autorite();
        $maladie  = $this->maladie(['libelle' => 'Paludisme']);

        // Le moteur le rend impossible ; l'écran, lui, doit NOMMER le problème à l'agent — sans
        // quoi il verrait une erreur 500 sans savoir quoi corriger.
        $this->actingAs($autorite)
            ->post("/portail/maladies/{$maladie->id}/libelles", [
                'langue' => 'fr', 'libelle' => 'Paludisme', 'source' => 'demonstration',
            ])
            ->assertSessionHasErrors('libelle');

        $this->assertSame(0, LibelleMaladie::count());
    }

    public function test_la_surveillance_se_declare_par_pays(): void
    {
        $autorite = $this->autorite();
        $maladie  = $this->maladie();

        $this->actingAs($autorite)
            ->post("/portail/maladies/{$maladie->id}/surveillance", [
                'pays_code' => 'ci', 'declaration_obligatoire' => '1', 'source' => 'autorite_nationale',
            ])
            ->assertRedirect();

        $surveillance = SurveillanceMaladie::first();
        $this->assertSame('CI', $surveillance->pays_code);
        $this->assertTrue($surveillance->declaration_obligatoire);
        // Non cochée = non déclarée : l'absence se dit, elle ne se suppose pas.
        $this->assertFalse($surveillance->surveillance_prioritaire);
    }
}
