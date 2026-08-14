<?php

namespace Tests\Feature;

use App\Models\Analyse;
use App\Models\LaboratoireAnalyse;
use App\Models\Medecin;
use App\Models\MembreFamille;
use App\Models\ResultatAnalyse;
use App\Models\ServiceEtablissement;
use App\Models\StructureSanitaire;
use App\Models\User;
use App\Services\Analyse\AttributeurCodeAnalyse;
use App\Services\Analyse\CatalogueDuLaboratoire;
use App\Services\Referentiel\EmpreinteReferentiel;
use App\Services\Referentiel\SourceEtablissements;
use App\Support\Analyses;
use Database\Seeders\PortailRolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * P6.7b — Référentiel des laboratoires (§7.1/§7.2) et liens d'un résultat.
 *
 * ═══ CE QUE CETTE SUITE PROTÈGE AVANT TOUT ═══
 *
 * **La correction de P6.7a.** P6.7a réécrivait `medecin_prescripteur` avec le nom du soignant qui
 * consignait le résultat, en le présentant comme le miroir de `ordonnances.medecin_nom`. C'était
 * faux : celui qui consigne un résultat n'est pas forcément celui qui l'a prescrit, et le serveur
 * inscrivait alors le nom du mauvais médecin. Le premier vecteur ci-dessous existe pour que cette
 * régression ne puisse pas revenir.
 *
 * Le reste : les deux liens vers des TIERS (prescripteur, laboratoire) vérifiés et figés ; la
 * typologie §7.1 dans la projection gouvernée alors que les données d'exploitation en sont exclues ;
 * l'habilitation à deux chemins sur les analyses d'un laboratoire.
 */
class ReferentielLaboratoiresTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private MembreFamille $membre;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->membre = MembreFamille::factory()->for($this->user)->create();

        Sanctum::actingAs($this->user);
    }

    private function structure(string $type = 'laboratoire', string $nom = 'Labo Central'): StructureSanitaire
    {
        return StructureSanitaire::create([
            'nom' => $nom, 'type' => $type, 'adresse' => 'Abidjan', 'commune' => 'Cocody',
            'latitude' => 5.35, 'longitude' => -3.98, 'actif' => true,
        ]);
    }

    private function analyse(): Analyse
    {
        $a = Analyse::create(['libelle' => 'Hémoglobine', 'unite' => 'g/dL', 'milieu_preleve' => 'sang_veineux']);
        app(AttributeurCodeAnalyse::class)->attribuer($a);

        return $a->fresh();
    }

    private function url(): string
    {
        return "/api/v1/membres/{$this->membre->id}/resultats-analyses";
    }

    /** @return array{0: User, 1: Medecin} */
    private function soignantAvecFiche(StructureSanitaire $structure): array
    {
        $this->seed(PortailRolesSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $service = ServiceEtablissement::create([
            'structure_id' => $structure->id, 'nom_service' => 'Laboratoire',
            'specialite' => 'biologie', 'actif' => true,
        ]);

        $compte = User::factory()->create(['structure_id' => $structure->id]);
        $compte->givePermissionTo('dossier.ecrire');

        $fiche = Medecin::create([
            'structure_id' => $structure->id, 'service_id' => $service->id, 'user_id' => $compte->id,
            'titre' => 'Dr', 'prenom' => 'Aya', 'nom' => 'Koffi',
            'specialite' => 'Biologie', 'profession' => 'biologiste', 'actif' => true,
        ]);

        return [$compte->fresh(), $fiche->fresh()];
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // LA CORRECTION DE P6.7a — le vecteur qui empêche la régression de revenir
    // ─────────────────────────────────────────────────────────────────────────────

    public function test_le_soignant_ne_REMPLACE_PLUS_le_prescripteur_declare(): void
    {
        // P6.7a écrasait ce champ avec le nom de celui qui consigne. Un biologiste classant un
        // résultat prescrit par un généraliste de ville voyait donc SON nom inscrit comme
        // prescripteur — une affirmation fausse, portée par le serveur.
        $structure = $this->structure('chu', 'CHU de Cocody');
        [$compte] = $this->soignantAvecFiche($structure);

        $entree = app(\App\Services\EcritureSoignantService::class)->ecrire(
            $compte, $this->membre, 'qr_scan', 'resultats-analyses',
            [
                'type_analyse'         => 'biologique',
                'intitule'             => 'NFS',
                'date_analyse'         => '2026-08-14',
                'medecin_prescripteur' => 'Dr Konan, généraliste de ville',
            ],
        );

        $this->assertSame(
            'Dr Konan, généraliste de ville',
            $entree->medecin_prescripteur,
            'Le serveur a de nouveau remplacé le prescripteur par celui qui consigne.',
        );
    }

    public function test_le_soignant_reste_reecrit_sur_une_ORDONNANCE(): void
    {
        // Non-régression de P6.5b : là, celui qui écrit EST le prescripteur. La correction de
        // P6.7a ne doit pas avoir rouvert cette porte-là.
        $structure = $this->structure('chu', 'CHU de Cocody');
        [$compte] = $this->soignantAvecFiche($structure);

        $entree = app(\App\Services\EcritureSoignantService::class)->ecrire(
            $compte, $this->membre, 'qr_scan', 'ordonnances',
            [
                'medecin_nom'         => 'Dr Quelqu\'un d\'Autre',
                'structure_sanitaire' => 'Ailleurs',
                'date_prescription'   => '2026-08-14',
                'medicaments_json'    => [['nom' => 'Paracétamol']],
            ],
        );

        $this->assertSame('Dr Aya Koffi', $entree->medecin_nom);
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // Les deux liens vers des tiers
    // ─────────────────────────────────────────────────────────────────────────────

    public function test_un_resultat_sans_lien_reste_accepte(): void
    {
        $this->postJson($this->url(), [
            'type_analyse'         => 'biologique',
            'intitule'             => 'NFS',
            'date_analyse'         => '2026-08-14',
            'laboratoire'          => 'Labo du quartier (papier)',
            'medecin_prescripteur' => 'Dr Konan',
        ])->assertCreated();

        $entree = ResultatAnalyse::first();

        $this->assertNull($entree->laboratoire_id);
        $this->assertNull($entree->medecin_prescripteur_id);
        $this->assertSame('Labo du quartier (papier)', $entree->laboratoire);
    }

    public function test_le_lien_laboratoire_est_verifie_et_fige(): void
    {
        $labo = $this->structure();
        $labo->forceFill(['identifiant_national' => 'ETS000042'])->save();

        $this->postJson($this->url(), [
            'type_analyse'    => 'biologique',
            'intitule'        => 'NFS',
            'date_analyse'    => '2026-08-14',
            'laboratoire'     => 'Nom invente par le client',
            'laboratoire_id'  => $labo->id,
            'laboratoire_nom' => 'Faux nom',
        ])->assertCreated();

        $entree = ResultatAnalyse::first();

        $this->assertSame('Labo Central', $entree->laboratoire_nom);
        $this->assertSame('ETS000042', $entree->laboratoire_code);
        // Le texte libre est ALIGNÉ : garder deux noms différents laisserait choisir lequel croire.
        $this->assertSame('Labo Central', $entree->laboratoire);
    }

    public function test_un_etablissement_qui_n_est_PAS_un_laboratoire_est_refuse(): void
    {
        // Sans ce contrôle, « laboratoire » deviendrait « établissement », et le référentiel des
        // laboratoires ne voudrait plus rien dire.
        $pharmacie = $this->structure('pharmacie', 'Pharmacie du Plateau');

        $this->postJson($this->url(), [
            'type_analyse'   => 'biologique',
            'intitule'       => 'NFS',
            'date_analyse'   => '2026-08-14',
            'laboratoire_id' => $pharmacie->id,
        ])->assertStatus(422)->assertJsonValidationErrors('laboratoire_id');

        $this->assertSame(0, ResultatAnalyse::count());
    }

    public function test_le_lien_prescripteur_est_verifie_et_fige(): void
    {
        $structure = $this->structure('chu', 'CHU');
        [, $fiche] = $this->soignantAvecFiche($structure);

        $this->postJson($this->url(), [
            'type_analyse'            => 'biologique',
            'intitule'                => 'NFS',
            'date_analyse'            => '2026-08-14',
            'medecin_prescripteur'    => 'Nom invente',
            'medecin_prescripteur_id' => $fiche->id,
        ])->assertCreated();

        $entree = ResultatAnalyse::first();

        $this->assertSame('Dr Aya Koffi', $entree->medecin_prescripteur_nom);
        $this->assertSame('Dr Aya Koffi', $entree->medecin_prescripteur);
    }

    public function test_un_prescripteur_inconnu_est_refuse_avec_un_message_qui_le_nomme(): void
    {
        $this->postJson($this->url(), [
            'type_analyse'            => 'biologique',
            'intitule'                => 'NFS',
            'date_analyse'            => '2026-08-14',
            'medecin_prescripteur_id' => 4242,
        ])->assertStatus(422)->assertJsonValidationErrors('medecin_prescripteur_id');
    }

    public function test_les_noms_figes_ne_bougent_plus_quand_le_referentiel_change(): void
    {
        $labo = $this->structure();

        $this->postJson($this->url(), [
            'type_analyse'   => 'biologique',
            'intitule'       => 'NFS',
            'date_analyse'   => '2026-08-14',
            'laboratoire_id' => $labo->id,
        ])->assertCreated();

        $labo->update(['nom' => 'Labo Central (renommé)']);

        $this->assertSame('Labo Central', ResultatAnalyse::first()->fresh()->laboratoire_nom);
    }

    public function test_le_client_ne_peut_pas_declarer_les_noms_figes(): void
    {
        // Sans lien, aucun nom figé ne doit apparaître : il ne serait adossé à rien.
        $this->postJson($this->url(), [
            'type_analyse'             => 'biologique',
            'intitule'                 => 'NFS',
            'date_analyse'             => '2026-08-14',
            'laboratoire_nom'          => 'Faux labo',
            'laboratoire_code'         => 'ETS999999',
            'medecin_prescripteur_nom' => 'Faux medecin',
        ])->assertCreated();

        $entree = ResultatAnalyse::first();

        $this->assertNull($entree->laboratoire_nom);
        $this->assertNull($entree->laboratoire_code);
        $this->assertNull($entree->medecin_prescripteur_nom);
    }

    public function test_le_service_ecarte_les_noms_figes_meme_appele_directement(): void
    {
        // Une couche, un vecteur : `validate()` écarte déjà ces clés, donc la garde du service se
        // prouve en appel direct — leçon de P6.6b.
        $resolu = app(\App\Services\Analyse\ServiceLienResultat::class)->resoudre([
            'intitule'                 => 'NFS',
            'laboratoire_nom'          => 'Faux labo',
            'laboratoire_code'         => 'ETS999999',
            'medecin_prescripteur_nom' => 'Faux medecin',
        ]);

        $this->assertArrayNotHasKey('laboratoire_nom', $resolu);
        $this->assertArrayNotHasKey('laboratoire_code', $resolu);
        $this->assertArrayNotHasKey('medecin_prescripteur_nom', $resolu);
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // §7.1 / §7.2 — ce qui entre dans la projection gouvernée, et ce qui n'y entre pas
    // ─────────────────────────────────────────────────────────────────────────────

    public function test_la_typologie_du_laboratoire_FAIT_diverger_le_referentiel(): void
    {
        $labo = $this->structure();
        $labo->forceFill(['identifiant_national' => 'ETS000001'])->save();

        $source = new SourceEtablissements();
        $avant = EmpreinteReferentiel::duContenu($source->extraire());

        $labo->update(['type_laboratoire' => 'sante_publique']);

        $this->assertNotSame($avant, EmpreinteReferentiel::duContenu($source->extraire()));
    }

    public function test_les_donnees_d_exploitation_ne_font_PAS_diverger_le_referentiel(): void
    {
        // Le miroir : équipements, délai de rendu, responsable scientifique changent avec le
        // personnel et les automates. Les gouverner ferait de l'arrivée d'un appareil une décision
        // ministérielle — même critère que `directeur`, déjà exclu en P6.4a.
        $labo = $this->structure();
        $labo->forceFill(['identifiant_national' => 'ETS000001'])->save();

        $source = new SourceEtablissements();
        $avant = EmpreinteReferentiel::duContenu($source->extraire());

        $labo->update([
            'responsable_scientifique' => 'Dr Biologiste',
            'equipements'              => 'Automate XYZ',
            'delai_rendu_moyen_heures' => 24,
            'connecte_si_national'     => true,
        ]);

        $this->assertSame($avant, EmpreinteReferentiel::duContenu($source->extraire()));
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // Les analyses d'un laboratoire
    // ─────────────────────────────────────────────────────────────────────────────

    private function admin(): User
    {
        $this->seed(PortailRolesSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $u = User::factory()->create();
        $u->givePermissionTo('etablissement.manage');

        return $u->fresh();
    }

    public function test_un_laboratoire_declare_les_analyses_qu_il_realise(): void
    {
        $labo = $this->structure();
        $analyse = $this->analyse();

        $ligne = app(CatalogueDuLaboratoire::class)->declarer($labo, $analyse, $this->admin(), 6, 'Cytométrie');

        $this->assertSame($labo->id, $ligne->structure_id);
        $this->assertSame(6, $ligne->delai_rendu_heures);
    }

    public function test_un_etablissement_qui_n_est_pas_un_laboratoire_ne_declare_rien(): void
    {
        $chu = $this->structure('chu', 'CHU');
        $analyse = $this->analyse();

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);

        app(CatalogueDuLaboratoire::class)->declarer($chu, $analyse, $this->admin());
    }

    public function test_la_meme_analyse_ne_se_declare_pas_deux_fois(): void
    {
        $labo = $this->structure();
        $analyse = $this->analyse();
        $admin = $this->admin();

        app(CatalogueDuLaboratoire::class)->declarer($labo, $analyse, $admin);

        try {
            app(CatalogueDuLaboratoire::class)->declarer($labo, $analyse, $admin);
            $this->fail('Un laboratoire a pu déclarer deux fois la même analyse.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('analyse_id', $e->errors());
        }

        $this->assertSame(1, LaboratoireAnalyse::count());
    }

    public function test_le_delai_du_laboratoire_prime_mais_les_deux_sont_dits(): void
    {
        $labo = $this->structure();
        $analyse = $this->analyse();
        $analyse->update(['delai_rendu_heures' => 48]);

        app(CatalogueDuLaboratoire::class)->declarer($labo, $analyse->fresh(), $this->admin(), 6);

        $vue = app(CatalogueDuLaboratoire::class)->analysesDe($labo)->first();

        $this->assertSame(6, $vue['delai_applique']);
        $this->assertSame('laboratoire', $vue['delai_source']);
        // Les DEUX sont portés : on ne remplace jamais silencieusement.
        $this->assertSame(48, $vue['delai_catalogue']);
    }

    public function test_le_delai_du_catalogue_s_applique_a_defaut(): void
    {
        $labo = $this->structure();
        $analyse = $this->analyse();
        $analyse->update(['delai_rendu_heures' => 48]);

        app(CatalogueDuLaboratoire::class)->declarer($labo, $analyse->fresh(), $this->admin());

        $vue = app(CatalogueDuLaboratoire::class)->analysesDe($labo)->first();

        $this->assertSame(48, $vue['delai_applique']);
        $this->assertSame('catalogue', $vue['delai_source']);
    }

    public function test_un_gestionnaire_declare_pour_SON_laboratoire_seulement(): void
    {
        $this->seed(PortailRolesSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $sien = $this->structure('laboratoire', 'Mon labo');
        $autre = $this->structure('laboratoire', 'Labo du concurrent');
        $analyse = $this->analyse();

        $gestionnaire = User::factory()->create(['structure_id' => $sien->id]);
        $gestionnaire->assignRole('gestionnaire_etablissement');

        // Son laboratoire : accepté.
        app(CatalogueDuLaboratoire::class)->declarer($sien, $analyse, $gestionnaire->fresh());
        $this->assertSame(1, LaboratoireAnalyse::count());

        // Celui du concurrent : refusé.
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        app(CatalogueDuLaboratoire::class)->declarer($autre, $analyse, $gestionnaire->fresh());
    }

    public function test_la_fiche_du_portail_montre_les_analyses_d_un_laboratoire_seulement(): void
    {
        $admin = $this->admin();
        $labo = $this->structure();
        $chu = $this->structure('chu', 'CHU de Cocody');
        $analyse = $this->analyse();

        app(CatalogueDuLaboratoire::class)->declarer($labo, $analyse, $admin, 6);

        $this->actingAs($admin, 'web')
            ->get(route('portail.etablissements.edit', $labo))
            ->assertOk()
            ->assertSee('Analyses réalisées')
            ->assertSee('Hémoglobine');

        // Sur un CHU, le bloc n'apparaît pas : l'afficher laisserait croire qu'un hôpital déclare
        // des analyses au titre du §7.2.
        $this->actingAs($admin, 'web')
            ->get(route('portail.etablissements.edit', $chu))
            ->assertOk()
            ->assertDontSee('Analyses réalisées');
    }

    public function test_les_champs_du_7_2_sont_effaces_sur_une_categorie_qui_n_est_pas_un_laboratoire(): void
    {
        // Un CHU qui porterait `type_laboratoire = 'prive'` produirait une statistique fausse — et
        // le champ resterait invisible au formulaire, donc impossible à corriger.
        $admin = $this->admin();
        $chu = $this->structure('chu', 'CHU de Cocody');

        $this->actingAs($admin, 'web')
            ->put(route('portail.etablissements.update', $chu), [
                'nom'              => 'CHU de Cocody',
                'type'             => 'chu',
                'adresse'          => 'Abidjan',
                'commune'          => 'Cocody',
                'latitude'         => 5.35,
                'longitude'        => -3.98,
                'type_laboratoire' => 'prive',
                'responsable_scientifique' => 'Dr Biologiste',
                'equipements'      => 'Automate',
            ])
            ->assertRedirect();

        $frais = $chu->fresh();

        $this->assertNull($frais->type_laboratoire);
        $this->assertNull($frais->responsable_scientifique);
        $this->assertNull($frais->equipements);
    }

    public function test_la_typologie_est_une_source_unique(): void
    {
        // Convention de P6.5a : la migration recopie sa liste, ce test est le prix de la
        // duplication.
        foreach (Analyses::typesLaboratoire() as $type) {
            $labo = $this->structure('laboratoire', "Labo {$type}");
            $labo->update(['type_laboratoire' => $type]);
            $this->assertSame($type, $labo->fresh()->type_laboratoire);
        }
    }
}
