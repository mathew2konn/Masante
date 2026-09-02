<?php

namespace Tests\Feature;

use App\Models\Medecin;
use App\Models\ServiceEtablissement;
use App\Models\SpecialiteMedicale;
use App\Models\StructureSanitaire;
use App\Models\User;
use App\Services\Referentiel\EmpreinteReferentiel;
use App\Services\Referentiel\SourceProfessionnels;
use App\Services\Referentiel\SourceSpecialites;
use App\Support\RegistreReferentiels;
use Database\Seeders\PortailRolesSeeder;
use Database\Seeders\SpecialiteMedicaleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * P6.8a — Vocabulaire national des spécialités (CDC_09 §8, étape 8 du §14).
 *
 * Ce que ces vecteurs doivent tenir :
 *
 *  · le vocabulaire est FERMÉ au formulaire — le portail ne laisse plus taper un code libre, et
 *    c'est le renversement détection → interdiction qui donne son sens au référentiel ;
 *  · le code est ADOPTÉ et IMMUABLE — le contrat `?specialite=orl` de P3 (validé G5) survit, et
 *    renommer un code laisserait les services existants désigner un terme disparu ;
 *  · le rattachement est RÉSOLU PAR LE SERVEUR — un `specialite_id` envoyé par un client est écarté ;
 *  · deux vecteurs en miroir sur l'empreinte, aucun ne suffisant seul : le tarif d'un praticien ne
 *    fait PAS diverger le vocabulaire, le libellé d'un terme SI ;
 *  · la conséquence assumée et annoncée avant de coder : rattacher `medecins.specialite` fait
 *    changer l'empreinte du référentiel des PROFESSIONNELS ;
 *  · l'écriture du vocabulaire est fermée à `service.manage` — la permission d'un établissement sur
 *    SES services n'est pas celle de l'autorité sur la liste nationale.
 */
class ReferentielSpecialitesTest extends TestCase
{
    use RefreshDatabase;

    private function source(): SourceSpecialites
    {
        return new SourceSpecialites();
    }

    private function structure(string $nom = 'CHU Test'): StructureSanitaire
    {
        return StructureSanitaire::create([
            'nom' => $nom, 'type' => 'chu', 'adresse' => 'Abidjan', 'commune' => 'Cocody',
            'latitude' => 5.35, 'longitude' => -3.98, 'actif' => true,
        ]);
    }

    private function gestionnaire(StructureSanitaire $structure): User
    {
        $user = User::factory()->create(['structure_id' => $structure->id]);
        $user->assignRole('gestionnaire_etablissement');

        return $user->fresh();
    }

    /** Un service d'établissement — `medecins.service_id` est NOT NULL depuis le Module 3. */
    private function service(StructureSanitaire $structure, string $code = 'cardiologie'): ServiceEtablissement
    {
        return ServiceEtablissement::create([
            'structure_id' => $structure->id, 'nom_service' => 'Service', 'specialite' => $code,
            'specialite_id' => SpecialiteMedicale::parCode($code)?->id, 'actif' => true,
        ]);
    }

    /** Un compte portant la permission nationale, accordée nominativement. */
    private function autorite(): User
    {
        $user = User::factory()->create();
        $user->assignRole('gestionnaire_etablissement');
        $user->givePermissionTo('specialite.referentiel');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user->fresh();
    }

    private function terme(array $remplacements = []): SpecialiteMedicale
    {
        $terme = new SpecialiteMedicale();
        $terme->fill(array_merge([
            'libelle' => 'Cardiologie', 'nature' => 'specialite_medicale',
            'profession' => 'medecin_specialiste', 'ordre' => 10, 'actif' => true,
        ], array_diff_key($remplacements, array_flip(['code', 'pays_code']))));
        $terme->forceFill([
            'code'      => $remplacements['code'] ?? 'cardiologie',
            'pays_code' => $remplacements['pays_code'] ?? 'CI',
        ])->save();

        return $terme;
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // Le vocabulaire lui-même
    // ─────────────────────────────────────────────────────────────────────────────

    public function test_deux_pays_partagent_le_meme_code(): void
    {
        $this->terme(['code' => 'cardiologie', 'pays_code' => 'CI']);
        $this->terme(['code' => 'cardiologie', 'pays_code' => 'SN']);

        // Le pays QUALIFIE, il ne s'écrit pas dans le code : `cardiologie` reste `cardiologie`
        // d'un pays à l'autre (décision P6.4a, rejouée ici).
        $this->assertSame(2, SpecialiteMedicale::where('code', 'cardiologie')->count());
    }

    public function test_le_meme_code_deux_fois_dans_un_pays_est_refuse_par_le_moteur(): void
    {
        $this->terme(['code' => 'cardiologie']);

        $this->expectException(\Illuminate\Database\QueryException::class);
        $this->terme(['code' => 'cardiologie', 'libelle' => 'Cardio']);
    }

    public function test_le_controle_qualite_accepte_le_vocabulaire_seede(): void
    {
        $this->seed(SpecialiteMedicaleSeeder::class);

        $this->assertSame([], $this->source()->controlerQualite($this->source()->extraire()));
    }

    public function test_le_controle_qualite_refuse_un_doublon_de_code(): void
    {
        $erreurs = $this->source()->controlerQualite([
            $this->ligne(['code' => 'orl']),
            $this->ligne(['code' => 'orl', 'libelle' => 'ORL bis']),
        ]);

        $this->assertNotEmpty($erreurs);
        $this->assertStringContainsString('Doublon', implode(' ', $erreurs));
    }

    public function test_le_controle_qualite_n_est_pas_plus_strict_que_le_moteur_sur_le_pays(): void
    {
        // LE VECTEUR QUI A MANQUÉ EN P6.5a, et que son G2 live avait attrapé : l'index est
        // `UNIQUE(pays_code, code)`. Un contrôle qui ignorerait le pays signalerait un doublon là
        // où le moteur accepte, et le référentiel deviendrait impubliable dès le second pays.
        $erreurs = $this->source()->controlerQualite([
            $this->ligne(['code' => 'orl', 'pays_code' => 'CI']),
            $this->ligne(['code' => 'orl', 'pays_code' => 'SN']),
        ]);

        $this->assertSame([], $erreurs);
    }

    public function test_le_controle_qualite_refuse_un_code_mal_forme(): void
    {
        $erreurs = $this->source()->controlerQualite([$this->ligne(['code' => 'ORL-Cardio'])]);

        $this->assertNotEmpty($erreurs);
        $this->assertStringContainsString('mal formé', implode(' ', $erreurs));
    }

    public function test_le_controle_qualite_refuse_deux_libelles_identiques(): void
    {
        // Deux termes au même libellé sont indiscernables à l'écran : le patient qui choisit
        // « Cardiologie » dans une liste qui en contient deux ne sait pas laquelle il désigne.
        $erreurs = $this->source()->controlerQualite([
            $this->ligne(['code' => 'cardiologie', 'libelle' => 'Cardiologie']),
            $this->ligne(['code' => 'cardio_pediatrie', 'libelle' => 'cardiologie']),
        ]);

        $this->assertNotEmpty($erreurs);
        $this->assertStringContainsString('indiscernables', implode(' ', $erreurs));
    }

    public function test_le_controle_qualite_refuse_une_profession_inconnue(): void
    {
        $erreurs = $this->source()->controlerQualite([$this->ligne(['profession' => 'astronaute'])]);

        $this->assertNotEmpty($erreurs);
        $this->assertStringContainsString('profession inconnue', implode(' ', $erreurs));
    }

    public function test_le_controle_qualite_refuse_un_vocabulaire_entierement_inactif(): void
    {
        $erreurs = $this->source()->controlerQualite([$this->ligne(['actif' => false])]);

        $this->assertNotEmpty($erreurs);
        $this->assertStringContainsString('Aucun terme actif', implode(' ', $erreurs));
    }

    public function test_le_referentiel_est_au_registre_gouverne(): void
    {
        $this->assertTrue(RegistreReferentiels::existe(SourceSpecialites::CODE));
        $this->assertSame('ministere', $this->source()->roleResponsable());
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // Les deux vecteurs en miroir — aucun ne suffit seul
    // ─────────────────────────────────────────────────────────────────────────────

    public function test_renommer_un_libelle_fait_diverger_le_referentiel(): void
    {
        $terme = $this->terme();
        $avant = EmpreinteReferentiel::duContenu($this->source()->extraire());

        $terme->update(['libelle' => 'Cardiologie et maladies vasculaires']);

        $apres = EmpreinteReferentiel::duContenu($this->source()->extraire());
        $this->assertNotSame($avant, $apres, 'Un libellé gouverné doit faire diverger le référentiel.');
    }

    public function test_le_tarif_d_un_praticien_ne_fait_pas_diverger_le_vocabulaire(): void
    {
        $terme = $this->terme();
        $structure = $this->structure();
        $praticien = Medecin::create([
            'structure_id' => $structure->id, 'service_id' => $this->service($structure)->id,
            'titre' => 'Dr', 'nom' => 'Koffi', 'prenom' => 'Aya',
            'specialite' => 'Cardiologie', 'specialite_id' => $terme->id, 'actif' => true,
        ]);

        $avant = EmpreinteReferentiel::duContenu($this->source()->extraire());
        $praticien->update(['tarif_consultation' => 25000]);
        $apres = EmpreinteReferentiel::duContenu($this->source()->extraire());

        // Le vocabulaire ne porte AUCUNE valeur dérivée de l'usage — c'est ce qui permet à sa
        // projection de prendre la ligne entière (raisonnement de P6.6a, reposé et non recopié).
        $this->assertSame($avant, $apres);
    }

    public function test_le_rattachement_fait_changer_l_empreinte_des_professionnels(): void
    {
        // CONSÉQUENCE ASSUMÉE ET ÉCRITE AVANT DE CODER (plan G1 §4.3) : `specialite` figure déjà
        // dans la projection du référentiel des professionnels. Écrire le libellé d'après le
        // vocabulaire la fait donc changer. Ce n'est pas une dérive — même cas que
        // `forme_juridique` en P6.4d — mais cela devait être prouvé, pas supposé.
        $terme = $this->terme(['libelle' => 'Cardiologie']);
        $structure = $this->structure();
        $praticien = Medecin::create([
            'structure_id' => $structure->id, 'service_id' => $this->service($structure)->id,
            'titre' => 'Dr', 'nom' => 'Koffi', 'prenom' => 'Aya',
            'specialite' => 'Cardio.', 'actif' => true,
        ]);

        $avant = EmpreinteReferentiel::duContenu((new SourceProfessionnels())->extraire());
        $praticien->update(['specialite' => $terme->libelle, 'specialite_id' => $terme->id]);
        $apres = EmpreinteReferentiel::duContenu((new SourceProfessionnels())->extraire());

        $this->assertNotSame($avant, $apres);
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // LA GARDE CENTRALE : le formulaire ferme le vocabulaire
    // ─────────────────────────────────────────────────────────────────────────────

    public function test_un_service_avec_un_code_hors_vocabulaire_est_refuse(): void
    {
        $this->seed(PortailRolesSeeder::class);
        $this->seed(SpecialiteMedicaleSeeder::class);
        $structure = $this->structure();

        $this->actingAs($this->gestionnaire($structure))
            ->post('/portail/services', ['nom_service' => 'Cardio', 'specialite' => 'cardio'])
            ->assertSessionHasErrors('specialite');

        // Rien créé : refuser sans empêcher l'écriture ne serait qu'un message.
        $this->assertSame(0, ServiceEtablissement::count());
    }

    public function test_un_service_avec_un_code_du_vocabulaire_est_cree_et_rattache(): void
    {
        $this->seed(PortailRolesSeeder::class);
        $this->seed(SpecialiteMedicaleSeeder::class);
        $structure = $this->structure();

        $this->actingAs($this->gestionnaire($structure))
            ->post('/portail/services', ['nom_service' => 'Cardiologie', 'specialite' => 'cardiologie'])
            ->assertRedirect(route('portail.services.index'));

        $service = ServiceEtablissement::sole();
        $this->assertSame('cardiologie', $service->specialite);
        $this->assertSame(SpecialiteMedicale::parCode('cardiologie')->id, $service->specialite_id);
    }

    public function test_un_terme_desactive_ne_peut_plus_etre_rattache(): void
    {
        $this->seed(PortailRolesSeeder::class);
        $this->seed(SpecialiteMedicaleSeeder::class);
        SpecialiteMedicale::parCode('orl')->update(['actif' => false]);
        $structure = $this->structure();

        $this->actingAs($this->gestionnaire($structure))
            ->post('/portail/services', ['nom_service' => 'ORL', 'specialite' => 'orl'])
            ->assertSessionHasErrors('specialite');
    }

    public function test_le_rattachement_envoye_par_le_client_est_ignore(): void
    {
        // Le serveur RÉSOUT le terme, il ne croit pas le client. `specialite_id` est `fillable`
        // (le chemin d'écriture est une assignation de masse — piège de P6.7b) : la garantie tient
        // aux règles de validation, qui ne le déclarent pas, et à la résolution du contrôleur.
        $this->seed(PortailRolesSeeder::class);
        $this->seed(SpecialiteMedicaleSeeder::class);
        $structure = $this->structure();
        $orl = SpecialiteMedicale::parCode('orl');
        $cardio = SpecialiteMedicale::parCode('cardiologie');

        $this->actingAs($this->gestionnaire($structure))
            ->post('/portail/services', [
                'nom_service' => 'ORL', 'specialite' => 'orl',
                'specialite_id' => $cardio->id,
            ])
            ->assertRedirect();

        $this->assertSame($orl->id, ServiceEtablissement::sole()->specialite_id);
    }

    public function test_le_libelle_d_un_praticien_vient_du_referentiel(): void
    {
        $this->seed(PortailRolesSeeder::class);
        $this->seed(SpecialiteMedicaleSeeder::class);
        $structure = $this->structure();
        $service = ServiceEtablissement::create([
            'structure_id' => $structure->id, 'nom_service' => 'ORL', 'specialite' => 'orl',
            'specialite_id' => SpecialiteMedicale::parCode('orl')->id, 'actif' => true,
        ]);

        $this->actingAs($this->gestionnaire($structure))
            ->post('/portail/medecins', [
                'titre' => 'Dr', 'prenom' => 'Aya', 'nom' => 'Koffi',
                // Le client envoie AUSSI un libellé de son cru : il ne doit pas survivre.
                'specialite' => 'Oreilles',
                'specialite_code' => 'orl',
                'service_id' => $service->id,
            ])
            ->assertRedirect(route('portail.medecins.index'));

        $fiche = Medecin::sole();
        $this->assertSame('Oto-rhino-laryngologie (ORL)', $fiche->specialite);
        $this->assertSame(SpecialiteMedicale::parCode('orl')->id, $fiche->specialite_id);
    }

    public function test_une_specialite_de_praticien_hors_vocabulaire_est_refusee(): void
    {
        $this->seed(PortailRolesSeeder::class);
        $this->seed(SpecialiteMedicaleSeeder::class);
        $structure = $this->structure();
        $service = ServiceEtablissement::create([
            'structure_id' => $structure->id, 'nom_service' => 'ORL', 'specialite' => 'orl', 'actif' => true,
        ]);

        $this->actingAs($this->gestionnaire($structure))
            ->post('/portail/medecins', [
                'titre' => 'Dr', 'prenom' => 'Aya', 'nom' => 'Koffi',
                'specialite_code' => 'chirurgie_esthetique', 'service_id' => $service->id,
            ])
            ->assertSessionHasErrors('specialite_code');

        $this->assertSame(0, Medecin::count());
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // L'habilitation : le vocabulaire n'appartient pas aux établissements
    // ─────────────────────────────────────────────────────────────────────────────

    public function test_la_permission_n_est_portee_par_aucun_role_metier(): void
    {
        $this->seed(PortailRolesSeeder::class);

        foreach (['gestionnaire_etablissement', 'personnel_accueil', 'medecin'] as $role) {
            $this->assertFalse(
                \Spatie\Permission\Models\Role::findByName($role, 'web')->hasPermissionTo('specialite.referentiel'),
                "Le rôle {$role} ne doit pas porter `specialite.referentiel`.",
            );
        }
    }

    public function test_un_gestionnaire_n_ouvre_pas_l_ecran_du_vocabulaire(): void
    {
        $this->seed(PortailRolesSeeder::class);
        $this->seed(SpecialiteMedicaleSeeder::class);

        $this->actingAs($this->gestionnaire($this->structure()))
            ->get('/portail/specialites')
            ->assertForbidden();
    }

    public function test_un_compte_habilite_ouvre_l_ecran_et_ajoute_un_terme(): void
    {
        $this->seed(PortailRolesSeeder::class);

        $this->actingAs($this->autorite())->get('/portail/specialites')->assertOk();

        $this->actingAs($this->autorite())
            ->post('/portail/specialites', [
                'code' => 'neurologie', 'libelle' => 'Neurologie',
                'nature' => 'specialite_medicale', 'profession' => 'medecin_specialiste',
            ])
            ->assertRedirect(route('portail.specialites.index'));

        $this->assertNotNull(SpecialiteMedicale::parCode('neurologie'));
    }

    public function test_un_code_mal_forme_est_refuse_a_la_creation(): void
    {
        $this->seed(PortailRolesSeeder::class);

        $this->actingAs($this->autorite())
            ->post('/portail/specialites', [
                'code' => 'Neuro-Logie', 'libelle' => 'Neurologie', 'nature' => 'specialite_medicale',
            ])
            ->assertSessionHasErrors('code');

        $this->assertSame(0, SpecialiteMedicale::count());
    }

    public function test_le_code_n_est_pas_modifiable(): void
    {
        // Renommer un code laisserait TOUS les services existants désigner un terme disparu :
        // `services_etablissement.specialite` le porte en texte, et le filtre public de P3 compare
        // dessus en égalité exacte. Un terme qui ne convient plus se désactive.
        $this->seed(PortailRolesSeeder::class);
        $terme = $this->terme(['code' => 'orl', 'libelle' => 'ORL']);

        $this->actingAs($this->autorite())
            ->put("/portail/specialites/{$terme->id}", [
                'code' => 'oto_rhino_laryngologie',
                'libelle' => 'Oto-rhino-laryngologie', 'nature' => 'specialite_medicale', 'actif' => '1',
            ])
            ->assertRedirect();

        $this->assertSame('orl', $terme->fresh()->code);
        $this->assertSame('Oto-rhino-laryngologie', $terme->fresh()->libelle);
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // Diffusion publique et contrat P3 (validé G5) intact
    // ─────────────────────────────────────────────────────────────────────────────

    public function test_le_vocabulaire_est_lisible_sans_jeton(): void
    {
        $this->seed(SpecialiteMedicaleSeeder::class);

        $reponse = $this->getJson('/api/v1/specialites')->assertOk();

        $codes = array_column($reponse->json('specialites'), 'code');
        $this->assertContains('cardiologie', $codes);
        // Le code que le mobile portait EN DUR est désormais servi par le backend.
        $this->assertContains('don_sang', $codes);
    }

    public function test_un_terme_inactif_n_est_pas_diffuse(): void
    {
        $this->seed(SpecialiteMedicaleSeeder::class);
        SpecialiteMedicale::parCode('orl')->update(['actif' => false]);

        $codes = array_column($this->getJson('/api/v1/specialites')->json('specialites'), 'code');
        $this->assertNotContains('orl', $codes);
    }

    public function test_le_filtre_par_nature_separe_les_activites(): void
    {
        $this->seed(SpecialiteMedicaleSeeder::class);

        $codes = array_column(
            $this->getJson('/api/v1/specialites?nature=activite')->json('specialites'), 'code',
        );

        // Un écran qui demande « choisissez une spécialité » n'a pas à proposer « Collecte de sang ».
        $this->assertContains('don_sang', $codes);
        $this->assertNotContains('cardiologie', $codes);
    }

    public function test_le_filtre_specialite_de_l_annuaire_repond_toujours(): void
    {
        // LE CONTRAT DE P3, VALIDÉ G5. Les codes ont été ADOPTÉS et non réinventés précisément pour
        // que cette requête continue de répondre sans qu'une ligne de P3 ne soit touchée.
        $this->seed(SpecialiteMedicaleSeeder::class);
        $structure = $this->structure();
        ServiceEtablissement::create([
            'structure_id' => $structure->id, 'nom_service' => 'ORL', 'specialite' => 'orl',
            'specialite_id' => SpecialiteMedicale::parCode('orl')->id, 'actif' => true,
        ]);

        $reponse = $this->getJson('/api/v1/structures?specialite=orl')->assertOk();

        $this->assertNotEmpty($reponse->json('structures') ?? $reponse->json('data') ?? []);
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // Le backfill
    // ─────────────────────────────────────────────────────────────────────────────

    public function test_le_backfill_rattache_services_et_praticiens_et_reste_idempotent(): void
    {
        $this->seed(SpecialiteMedicaleSeeder::class);
        $structure = $this->structure();

        // État d'avant P6.8a : un code posé sans rattachement possible.
        $service = ServiceEtablissement::create([
            'structure_id' => $structure->id, 'nom_service' => 'Maternité',
            'specialite' => 'gynecologie', 'actif' => true,
        ]);
        $praticien = Medecin::create([
            'structure_id' => $structure->id, 'service_id' => $service->id,
            'titre' => 'Dr', 'nom' => 'Yao', 'prenom' => 'Koffi',
            'specialite' => 'Maternité', 'actif' => true,
        ]);

        $this->artisan('masante:specialites:backfill --dry-run')->assertSuccessful();
        $this->assertNull($service->fresh()->specialite_id, 'Le dry-run n\'écrit rien.');

        $this->artisan('masante:specialites:backfill')->assertSuccessful();

        $gyneco = SpecialiteMedicale::parCode('gynecologie');
        $this->assertSame($gyneco->id, $service->fresh()->specialite_id);
        // Le praticien est rattaché PAR SON SERVICE, jamais par ressemblance de libellé : son
        // libellé est « Maternité », que nul rapprochement textuel ne mènerait à « gynecologie ».
        $this->assertSame($gyneco->id, $praticien->fresh()->specialite_id);
        // Et le libellé n'est PAS réécrit : c'est ce que l'établissement affiche (leçon de P6.7b).
        $this->assertSame('Maternité', $praticien->fresh()->specialite);

        $this->artisan('masante:specialites:backfill')->assertSuccessful();
        $this->assertSame($gyneco->id, $service->fresh()->specialite_id);
    }

    public function test_le_dry_run_annonce_ce_que_le_passage_reel_fera(): void
    {
        // DÉFAUT TROUVÉ AU G2 LIVE, PAS PAR LES TESTS : en simulation, le service n'est pas écrit,
        // si bien qu'en lisant son `specialite_id` la commande annonçait « 0 praticien » avant d'en
        // rattacher vingt-huit. Un aperçu qui sous-estime ce qu'il va faire n'aide pas à décider.
        $this->seed(SpecialiteMedicaleSeeder::class);
        $structure = $this->structure();
        $service = ServiceEtablissement::create([
            'structure_id' => $structure->id, 'nom_service' => 'ORL',
            'specialite' => 'orl', 'actif' => true,
        ]);
        Medecin::create([
            'structure_id' => $structure->id, 'service_id' => $service->id,
            'titre' => 'Dr', 'nom' => 'Yao', 'prenom' => 'Koffi',
            'specialite' => 'ORL', 'actif' => true,
        ]);

        $this->artisan('masante:specialites:backfill --dry-run')
            ->expectsOutputToContain('1 service(s) et 1 praticien(s) seraient rattachés.')
            ->assertSuccessful();

        // …et le passage réel confirme le même compte.
        $this->artisan('masante:specialites:backfill')
            ->expectsOutputToContain('1 service(s) et 1 praticien(s) rattachés')
            ->assertSuccessful();
    }

    public function test_le_backfill_ne_rattache_pas_un_code_hors_vocabulaire(): void
    {
        $this->seed(SpecialiteMedicaleSeeder::class);
        $structure = $this->structure();
        $service = ServiceEtablissement::create([
            'structure_id' => $structure->id, 'nom_service' => 'Chirurgie esthétique',
            'specialite' => 'chirurgie_esthetique', 'actif' => true,
        ]);

        $this->artisan('masante:specialites:backfill')->assertSuccessful();

        // Ni rattaché de force, ni rapproché « au plus proche » : un code inconnu reste inconnu,
        // et l'écran du référentiel le compte pour qu'il soit traité.
        $this->assertNull($service->fresh()->specialite_id);
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // Le code du don de sang ne vit plus dans le client
    // ─────────────────────────────────────────────────────────────────────────────

    public function test_le_code_du_centre_de_collecte_est_servi_par_le_backend(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/don-sang')
            ->assertOk()
            ->assertJsonPath('regles.specialite_centre', 'don_sang');
    }

    /**
     * Une ligne d'instantané, pour éprouver le contrôle qualité sans passer par la base.
     *
     * @return array<string, mixed>
     */
    private function ligne(array $remplacements = []): array
    {
        return array_merge([
            'code' => 'cardiologie', 'pays_code' => 'CI', 'libelle' => 'Cardiologie',
            'nature' => 'specialite_medicale', 'profession' => 'medecin_specialiste',
            'description' => null, 'ordre' => 10, 'actif' => true,
        ], $remplacements);
    }
}
