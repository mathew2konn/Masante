<?php

namespace Tests\Feature;

use App\Models\DistrictSanitaire;
use App\Models\Referentiel;
use App\Models\Region;
use App\Models\StructureSanitaire;
use App\Models\User;
use App\Services\Etablissement\AttributeurIdentifiantEtablissement;
use App\Services\Etablissement\GenerateurIdentifiantEtablissement;
use App\Services\Referentiel\EmpreinteReferentiel;
use App\Services\Referentiel\ServiceGouvernanceReferentiel;
use App\Services\Referentiel\SourceEtablissements;
use Database\Seeders\DecoupageSanitaireSeeder;
use Database\Seeders\PortailRolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * P6.4a — Référentiel national des établissements (CDC_09 §4).
 *
 * CE QUE CETTE SUITE PROTÈGE EN PRIORITÉ — la décision G1 D1 : le référentiel gouverne une
 * **projection d'identité administrative**, pas la table entière. Deux vecteurs la prouvent, en
 * miroir l'un de l'autre : un avis déposé par un citoyen **ne doit pas** faire diverger le
 * référentiel publié ; un changement de statut juridique **doit** le faire diverger. Sans ces
 * deux-là, on ne saurait pas si la projection sépare vraiment l'identité de l'état.
 *
 * Écrite dans les deux sens : chaque contrôle qualité a son vecteur qui échoue ET le contenu sain
 * qui n'en déclenche aucun — des contrôles qui refuseraient tout seraient aussi inutilisables que
 * des contrôles qui n'attrapent rien.
 */
class ReferentielEtablissementsTest extends TestCase
{
    use RefreshDatabase;

    private const CODE = SourceEtablissements::CODE;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PortailRolesSeeder::class);
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // Fixtures
    // ─────────────────────────────────────────────────────────────────────────────

    private function region(string $code = 'ABJ'): Region
    {
        return Region::firstOrCreate(['pays_code' => 'CI', 'code' => $code], ['nom' => 'Région '.$code]);
    }

    private function district(Region $region, string $code = 'ABJ-CB'): DistrictSanitaire
    {
        return DistrictSanitaire::firstOrCreate(
            ['pays_code' => 'CI', 'code' => $code],
            ['region_id' => $region->id, 'nom' => 'District '.$code],
        );
    }

    /** Un établissement complet et cohérent — le contenu « sain » de référence. */
    private function etablissement(array $remplacements = []): StructureSanitaire
    {
        $region = $this->region();
        $district = $this->district($region);

        $structure = StructureSanitaire::create(array_merge([
            'nom'              => 'CHU de Cocody',
            'nom_officiel'     => 'Centre Hospitalier Universitaire de Cocody',
            'type'             => 'chu',
            'statut_juridique' => 'public',
            'niveau_soins'     => 'tertiaire',
            'adresse'          => 'Boulevard de France',
            'commune'          => 'Cocody',
            'region_id'        => $region->id,
            'district_id'      => $district->id,
            'latitude'         => 5.35,
            'longitude'        => -3.98,
            'capacite_accueil' => 900,
            'nombre_lits'      => 850,
            'actif'            => true,
        ], $remplacements));

        app(AttributeurIdentifiantEtablissement::class)->attribuer($structure);

        return $structure->fresh();
    }

    private function source(): SourceEtablissements
    {
        return app(SourceEtablissements::class);
    }

    private function agent(string ...$permissions): User
    {
        $user = User::factory()->create();

        foreach ($permissions as $permission) {
            $user->givePermissionTo($permission);
        }

        return $user->fresh();
    }

    /** Enregistre puis publie le référentiel des établissements. */
    private function publier(): Referentiel
    {
        $gouvernance = app(ServiceGouvernanceReferentiel::class);
        $gouvernance->enregistrer(self::CODE);

        $gouvernance->proposer(
            self::CODE, 'CI',
            $this->agent(ServiceGouvernanceReferentiel::PERMISSION_PROPOSER),
            'Première mise en vigueur du référentiel des établissements.',
        );
        $gouvernance->publier(
            self::CODE, 'CI',
            $this->agent(ServiceGouvernanceReferentiel::PERMISSION_PUBLIER),
            'Contrôles conformes, publication nationale.',
        );

        return Referentiel::where('code', self::CODE)->firstOrFail();
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // Identifiant national (§4.3)
    // ─────────────────────────────────────────────────────────────────────────────

    public function test_l_identifiant_suit_la_forme_imposee_par_le_cdc(): void
    {
        $etablissement = $this->etablissement();

        // §4.3 montre `ETS000152` : trois lettres, six chiffres, aucune clé de contrôle.
        $this->assertSame('ETS000001', $etablissement->identifiant_national);
        $this->assertTrue(GenerateurIdentifiantEtablissement::formeValide($etablissement->identifiant_national));
    }

    public function test_l_exemple_impose_du_cdc_est_une_forme_valide(): void
    {
        // Le vecteur qui vérifie qu'on n'a pas inventé un format rendant le corpus invalide.
        $this->assertTrue(GenerateurIdentifiantEtablissement::formeValide('ETS000152'));
        $this->assertSame('ETS000152', GenerateurIdentifiantEtablissement::composer(152));
    }

    public function test_les_formes_invalides_sont_refusees(): void
    {
        foreach (['ETS12', 'ETS0001523', 'ETSABCDEF', '000152', 'ets000152', ''] as $mauvais) {
            $this->assertFalse(
                GenerateurIdentifiantEtablissement::formeValide($mauvais),
                "« {$mauvais} » aurait dû être refusé.",
            );
        }
    }

    public function test_la_sequence_est_croissante_et_sans_trou(): void
    {
        $a = $this->etablissement(['nom' => 'A', 'nom_officiel' => 'A']);
        $b = $this->etablissement(['nom' => 'B', 'nom_officiel' => 'B']);
        $c = $this->etablissement(['nom' => 'C', 'nom_officiel' => 'C']);

        $this->assertSame(
            ['ETS000001', 'ETS000002', 'ETS000003'],
            [$a->identifiant_national, $b->identifiant_national, $c->identifiant_national],
        );
    }

    public function test_l_attribution_est_idempotente_et_ne_consomme_pas_la_sequence(): void
    {
        $etablissement = $this->etablissement();
        $attributeur = app(AttributeurIdentifiantEtablissement::class);

        $this->assertSame('ETS000001', $attributeur->attribuer($etablissement));
        $this->assertSame('ETS000001', $attributeur->attribuer($etablissement->fresh()));

        // La séquence n'a pas bougé : le suivant est bien le 2, pas le 4.
        $this->assertSame(1, (int) DB::table('etablissement_compteurs')->where('pays_code', 'CI')->value('dernier'));
    }

    public function test_deux_pays_peuvent_porter_le_meme_identifiant(): void
    {
        $this->etablissement();

        // Le pays QUALIFIE l'identifiant, il ne s'écrit pas dedans (décision G1 D2) : l'unicité
        // porte sur le couple, donc `ETS000001` peut exister deux fois, une fois par pays.
        $senegal = StructureSanitaire::create([
            'nom' => 'Hôpital Principal de Dakar', 'type' => 'chu', 'adresse' => 'Dakar',
            'commune' => 'Dakar', 'latitude' => 14.66, 'longitude' => -17.43, 'actif' => true,
        ]);
        $senegal->forceFill(['pays_code' => 'SN'])->save();

        $this->assertSame('ETS000001', app(AttributeurIdentifiantEtablissement::class)->attribuer($senegal));
        $this->assertSame(2, StructureSanitaire::where('identifiant_national', 'ETS000001')->count());
    }

    public function test_le_meme_identifiant_deux_fois_dans_un_pays_est_refuse_par_la_base(): void
    {
        $this->etablissement();
        $autre = StructureSanitaire::create([
            'nom' => 'Clinique X', 'type' => 'clinique_privee', 'adresse' => 'Abidjan',
            'commune' => 'Cocody', 'latitude' => 5.35, 'longitude' => -3.98, 'actif' => true,
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);

        // Écriture SQL directe : c'est l'UNIQUE qui doit refuser, pas la discipline du code.
        DB::table('structures_sanitaires')->where('id', $autre->id)
            ->update(['identifiant_national' => 'ETS000001', 'pays_code' => 'CI']);
    }

    public function test_l_identifiant_ne_peut_pas_venir_d_un_formulaire(): void
    {
        // Hors `$fillable` à dessein : le laisser assignable en masse permettrait à un client
        // de choisir son propre numéro national.
        $structure = StructureSanitaire::create([
            'nom' => 'Clinique Y', 'type' => 'clinique_privee', 'adresse' => 'Abidjan',
            'commune' => 'Cocody', 'latitude' => 5.35, 'longitude' => -3.98, 'actif' => true,
            'identifiant_national' => 'ETS999999',
            'pays_code' => 'XX',
        ]);

        $this->assertNull($structure->fresh()->identifiant_national);
        $this->assertSame('CI', $structure->fresh()->pays_code);
    }

    public function test_la_commande_de_backfill_est_idempotente(): void
    {
        StructureSanitaire::create([
            'nom' => 'Ancienne structure', 'type' => 'centre_sante', 'adresse' => 'Abidjan',
            'commune' => 'Cocody', 'latitude' => 5.35, 'longitude' => -3.98, 'actif' => true,
        ]);

        $this->artisan('masante:etablissement:backfill', ['--dry-run' => true])->assertSuccessful();
        $this->assertSame(1, StructureSanitaire::whereNull('identifiant_national')->count());

        $this->artisan('masante:etablissement:backfill')->assertSuccessful();
        $this->assertSame(0, StructureSanitaire::whereNull('identifiant_national')->count());

        // Rejeu : rien à faire, et surtout aucune réattribution.
        $avant = StructureSanitaire::pluck('identifiant_national', 'id')->all();
        $this->artisan('masante:etablissement:backfill')->assertSuccessful();
        $this->assertSame($avant, StructureSanitaire::pluck('identifiant_national', 'id')->all());
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // Découpage sanitaire
    // ─────────────────────────────────────────────────────────────────────────────

    public function test_le_seeder_de_decoupage_est_idempotent_et_rattache_par_commune(): void
    {
        StructureSanitaire::create([
            'nom' => 'CHU de Treichville', 'type' => 'chu', 'adresse' => 'Abidjan',
            'commune' => 'Treichville', 'latitude' => 5.29, 'longitude' => -4.00, 'actif' => true,
        ]);

        $this->seed(DecoupageSanitaireSeeder::class);
        $this->seed(DecoupageSanitaireSeeder::class);

        $this->assertSame(1, Region::count());
        $this->assertSame(5, DistrictSanitaire::count());

        $structure = StructureSanitaire::where('commune', 'Treichville')->firstOrFail();
        $this->assertSame('ABJ-TM', $structure->district->code);
        $this->assertSame('ABJ', $structure->region->code);
    }

    public function test_un_rattachement_deja_pose_n_est_jamais_reecrit(): void
    {
        $region = $this->region();
        $autreDistrict = $this->district($region, 'ABJ-AB');

        $structure = StructureSanitaire::create([
            'nom' => 'Cas particulier', 'type' => 'centre_sante', 'adresse' => 'Abidjan',
            'commune' => 'Cocody', 'latitude' => 5.35, 'longitude' => -3.98, 'actif' => true,
            'region_id' => $region->id, 'district_id' => $autreDistrict->id,
        ]);

        $this->seed(DecoupageSanitaireSeeder::class);

        // Un correctif du ministère prime sur la table de correspondance approximative.
        $this->assertSame($autreDistrict->id, $structure->fresh()->district_id);
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // La projection — décision G1 D1
    // ─────────────────────────────────────────────────────────────────────────────

    public function test_la_projection_n_expose_que_l_identite_administrative(): void
    {
        $this->etablissement([
            'telephone' => '+2250101010101', 'note_moyenne' => 4.2, 'nb_avis' => 37,
            'horaires_json' => ['lundi' => '08:00-18:00'], 'tarif_min_cfa' => 5000,
        ]);

        $ligne = $this->source()->extraire()[0];

        foreach (['identifiant_national', 'nom_officiel', 'categorie', 'statut_juridique',
            'niveau_soins', 'region_code', 'district_code'] as $attendu) {
            $this->assertArrayHasKey($attendu, $ligne);
        }

        // Ce qui doit EN ÊTRE ABSENT : l'état opérationnel. Sa présence recréerait la divergence
        // permanente que la projection existe pour éviter.
        foreach (['note_moyenne', 'nb_avis', 'horaires_json', 'telephone', 'tarif_min_cfa',
            'latitude', 'longitude', 'description'] as $exclu) {
            $this->assertArrayNotHasKey($exclu, $ligne, "« {$exclu} » ne doit pas entrer dans le référentiel.");
        }
    }

    public function test_un_avis_depose_ne_fait_pas_diverger_le_referentiel(): void
    {
        $etablissement = $this->etablissement();
        $referentiel = $this->publier();
        $publiee = $referentiel->versionPubliee();

        // Ce que fait `NoteStructureService` à chaque avis déposé par un citoyen.
        StructureSanitaire::whereKey($etablissement->id)->update(['note_moyenne' => 4.6, 'nb_avis' => 38]);

        $this->assertTrue(
            hash_equals($publiee->empreinte, EmpreinteReferentiel::duContenu($this->source()->extraire())),
            'Un avis citoyen a fait diverger le référentiel national : la projection ne sépare pas '
            .'l\'identité administrative de l\'état opérationnel (décision G1 D1).',
        );
    }

    public function test_un_changement_de_statut_juridique_fait_diverger_le_referentiel(): void
    {
        $etablissement = $this->etablissement();
        $referentiel = $this->publier();
        $publiee = $referentiel->versionPubliee();

        // Le miroir du vecteur précédent : la projection doit rester SENSIBLE à ce qui engage
        // une autorité. Insensible à tout, elle ne servirait à rien.
        StructureSanitaire::whereKey($etablissement->id)->update(['statut_juridique' => 'prive']);

        $this->assertFalse(
            hash_equals($publiee->empreinte, EmpreinteReferentiel::duContenu($this->source()->extraire())),
            'Un changement de statut juridique doit faire diverger le référentiel.',
        );
    }

    public function test_le_cycle_de_gouvernance_fonctionne_sur_les_etablissements(): void
    {
        $this->etablissement();
        $referentiel = $this->publier();

        $this->assertSame(1, $referentiel->version_publiee_numero);
        $this->assertSame(1, $referentiel->versionPubliee()->nb_entrees);
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // Contrôles qualité §10
    // ─────────────────────────────────────────────────────────────────────────────

    public function test_un_contenu_sain_ne_produit_aucune_anomalie(): void
    {
        $this->etablissement();

        $this->assertSame([], $this->source()->controlerQualite($this->source()->extraire()));
    }

    public function test_un_referentiel_vide_est_refuse(): void
    {
        $this->assertNotEmpty($this->source()->controlerQualite([]));
    }

    public function test_un_district_hors_de_sa_region_est_detecte(): void
    {
        // L'anomalie la plus sournoise : les deux références sont valides prises séparément,
        // c'est leur COMBINAISON qui est fausse. Une statistique par région la propagerait
        // sans que rien ne la signale.
        $autreRegion = Region::create(['pays_code' => 'CI', 'code' => 'BKE', 'nom' => 'Bouaké']);
        $etablissement = $this->etablissement();
        StructureSanitaire::whereKey($etablissement->id)->update(['region_id' => $autreRegion->id]);

        $erreurs = $this->source()->controlerQualite($this->source()->extraire());

        $this->assertNotEmpty($erreurs);
        $this->assertStringContainsString("n'appartient pas à la région", implode(' ', $erreurs));
    }

    public function test_les_anomalies_de_completude_et_les_valeurs_aberrantes_sont_detectees(): void
    {
        $source = $this->source();

        $saine = [
            'identifiant_national' => 'ETS000001', 'pays_code' => 'CI',
            'nom_officiel' => 'CHU de Cocody', 'categorie' => 'chu',
            'statut_juridique' => 'public', 'niveau_soins' => 'tertiaire',
            'region_code' => 'ABJ', 'district_code' => 'ABJ-CB', 'region_du_district' => 'ABJ',
            'commune' => 'Cocody', 'capacite_accueil' => 900, 'nombre_lits' => 850,
            'numero_autorisation' => null, 'autorite_tutelle' => null,
            'agrements' => null, 'certifications' => null, 'actif' => true,
        ];

        $this->assertSame([], $source->controlerQualite([$saine]));

        // Une anomalie par vecteur : on saurait laquelle a échoué.
        $cas = [
            'identifiant absent'        => ['identifiant_national' => null],
            'identifiant mal formé'     => ['identifiant_national' => 'ETS12'],
            'nom absent'                => ['nom_officiel' => '  '],
            'statut juridique absent'   => ['statut_juridique' => null],
            'niveau de soins absent'    => ['niveau_soins' => null],
            'district sans région'      => ['region_code' => null, 'region_du_district' => null],
            'lits > capacité'           => ['nombre_lits' => 2000],
        ];

        foreach ($cas as $libelle => $modification) {
            $this->assertNotEmpty(
                $source->controlerQualite([array_merge($saine, $modification)]),
                "Anomalie non détectée : {$libelle}.",
            );
        }
    }

    public function test_le_niveau_de_soins_n_est_exige_que_des_etablissements_hospitaliers(): void
    {
        $source = $this->source();

        $pharmacie = [
            'identifiant_national' => 'ETS000002', 'pays_code' => 'CI',
            'nom_officiel' => 'Pharmacie de la Paix', 'categorie' => 'pharmacie',
            'statut_juridique' => 'prive', 'niveau_soins' => null,
            'region_code' => 'ABJ', 'district_code' => 'ABJ-CB', 'region_du_district' => 'ABJ',
            'commune' => 'Cocody', 'capacite_accueil' => null, 'nombre_lits' => null,
            'numero_autorisation' => null, 'autorite_tutelle' => null,
            'agrements' => null, 'certifications' => null, 'actif' => true,
        ];

        // Une pharmacie n'a pas de niveau de soins : l'exiger produirait une anomalie permanente
        // et rendrait le contrôle inutilisable.
        $this->assertSame([], $source->controlerQualite([$pharmacie]));
    }

    public function test_les_doublons_sont_detectes(): void
    {
        $source = $this->source();

        $base = [
            'identifiant_national' => 'ETS000001', 'pays_code' => 'CI',
            'nom_officiel' => 'Clinique Farah', 'categorie' => 'clinique_privee',
            'statut_juridique' => 'prive', 'niveau_soins' => null,
            'region_code' => 'ABJ', 'district_code' => 'ABJ-TM', 'region_du_district' => 'ABJ',
            'commune' => 'Marcory', 'capacite_accueil' => null, 'nombre_lits' => null,
            'numero_autorisation' => null, 'autorite_tutelle' => null,
            'agrements' => null, 'certifications' => null, 'actif' => true,
        ];

        // Doublon d'identifiant national.
        $this->assertStringContainsString(
            'Doublon',
            implode(' ', $source->controlerQualite([$base, $base])),
        );

        // Doublon métier : même nom, même district, identifiants distincts.
        $jumeau = array_merge($base, ['identifiant_national' => 'ETS000002']);
        $this->assertStringContainsString(
            'Doublon probable',
            implode(' ', $source->controlerQualite([$base, $jumeau])),
        );
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // Non-régression : P3 et P4 sont validés G5
    // ─────────────────────────────────────────────────────────────────────────────

    public function test_les_types_historiques_restent_acceptes(): void
    {
        foreach (['chu', 'chr', 'clinique_privee', 'cabinet', 'pharmacie', 'laboratoire', 'centre_sante'] as $type) {
            $structure = StructureSanitaire::create([
                'nom' => 'Structure '.$type, 'type' => $type, 'adresse' => 'Abidjan',
                'commune' => 'Cocody', 'latitude' => 5.35, 'longitude' => -3.98, 'actif' => true,
            ]);

            $this->assertSame($type, $structure->fresh()->type);
        }
    }

    public function test_les_types_du_perimetre_cdc_sont_desormais_acceptes(): void
    {
        // §4.1 nomme explicitement les centres d'imagerie, de dialyse et de vaccination.
        foreach (['hopital_general', 'centre_sante_urbain', 'centre_sante_rural',
            'centre_imagerie', 'centre_dialyse', 'centre_vaccination'] as $type) {
            $structure = StructureSanitaire::create([
                'nom' => 'Structure '.$type, 'type' => $type, 'adresse' => 'Abidjan',
                'commune' => 'Cocody', 'latitude' => 5.35, 'longitude' => -3.98, 'actif' => true,
            ]);

            $this->assertSame($type, $structure->fresh()->type);
        }
    }

    public function test_l_annuaire_public_repond_toujours(): void
    {
        $this->etablissement();

        // Le contrat de P3 sérialise le modèle entier : les colonnes neuves s'ajoutent, rien ne
        // disparaît. C'est ce qui rend l'enrichissement sûr (ADR-024).
        $reponse = $this->getJson('/api/v1/structures')->assertOk();

        $this->assertNotEmpty($reponse->json());
    }
}
