<?php

namespace Tests\Feature;

use App\Models\ExerciceProfessionnel;
use App\Models\Medecin;
use App\Models\ServiceEtablissement;
use App\Models\StructureSanitaire;
use App\Models\User;
use Database\Seeders\PortailRolesSeeder;
use Database\Seeders\SpecialiteMedicaleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * P6.5a — Fiche professionnelle au portail (CDC_09 §5.2, CDC_11 §3.4).
 *
 * LA GARDE CENTRALE DE CETTE SUITE : un établissement décrit ses praticiens, il ne déclare pas
 * leur DROIT D'EXERCER. Le bloc « ordre professionnel + autorisation » n'est accepté que d'un
 * compte portant `professionnel.habiliter`.
 *
 * Ce n'est pas une précaution de forme. Ces colonnes sont celles que le §5.4 interrogera avant de
 * laisser signer une ordonnance : si l'hôpital qui emploie le praticien pouvait les écrire, le
 * contrôle qui autorise la signature reposerait sur la déclaration de l'intéressé — l'employeur
 * signerait le contrôle qui le vise.
 *
 * Le vecteur qui le prouve n'est pas un 403 mais un SILENCE : les champs envoyés sans habilitation
 * ne sont pas repris, exactement comme `identifiant_national` en P6.4d. C'est plus dur à voir dans
 * un test qu'un refus bruyant, et c'est pour cela qu'il en faut un.
 */
class PortailProfessionnelTest extends TestCase
{
    use RefreshDatabase;

    private StructureSanitaire $structure;

    private ServiceEtablissement $service;

    private User $gestionnaire;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PortailRolesSeeder::class);
        // P6.8a — la spécialité d'une fiche se choisit dans le vocabulaire national : le seeder en
        // est une précondition, au même titre que les rôles.
        $this->seed(SpecialiteMedicaleSeeder::class);

        $this->structure = StructureSanitaire::create([
            'nom' => 'CHU de Cocody', 'type' => 'chu', 'adresse' => 'Boulevard de France',
            'commune' => 'Cocody', 'latitude' => 5.35, 'longitude' => -3.98, 'actif' => true,
        ]);
        $this->service = ServiceEtablissement::create([
            'structure_id' => $this->structure->id, 'nom_service' => 'Cardiologie',
            'specialite' => 'cardiologie', 'actif' => true,
        ]);

        $this->gestionnaire = User::factory()->create(['structure_id' => $this->structure->id]);
        $this->gestionnaire->assignRole('gestionnaire_etablissement');
    }

    /** Le même gestionnaire, mais habilité à déclarer l'autorisation d'exercer. */
    private function habilite(): User
    {
        $user = User::factory()->create(['structure_id' => $this->structure->id]);
        $user->assignRole('gestionnaire_etablissement');
        $user->givePermissionTo('professionnel.habiliter');

        return $user->fresh();
    }

    /** @return array<string, mixed> */
    private function fiche(array $remplacements = []): array
    {
        return array_merge([
            'titre'      => 'Dr',
            'prenom'     => 'Aya',
            'nom'        => 'Koffi',
            // P6.8a — un CODE du vocabulaire national, plus un libellé libre.
            'specialite_code' => 'cardiologie',
            'profession' => 'medecin_specialiste',
            'service_id' => $this->service->id,
        ], $remplacements);
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // Création : le numéro et l'exercice naissent avec la fiche
    // ─────────────────────────────────────────────────────────────────────────────

    public function test_la_creation_attribue_le_numero_national_et_l_exercice_principal(): void
    {
        $this->actingAs($this->gestionnaire)
            ->post(route('portail.medecins.store'), $this->fiche())
            ->assertRedirect(route('portail.medecins.index'));

        $professionnel = Medecin::sole();

        // Attribué DÈS la création, pas au prochain backfill : une fiche créée aujourd'hui et
        // publiée demain sans numéro ferait échouer le contrôle qualité du référentiel.
        $this->assertSame('PRO000001', $professionnel->numero_professionnel);

        $exercice = ExerciceProfessionnel::sole();
        $this->assertSame($professionnel->id, $exercice->medecin_id);
        $this->assertTrue($exercice->est_principal);
    }

    public function test_le_numero_national_envoye_par_le_formulaire_est_ignore(): void
    {
        $this->actingAs($this->gestionnaire)
            ->post(route('portail.medecins.store'), $this->fiche([
                'numero_professionnel' => 'PRO123456',
                'pays_code'            => 'ZZ',
            ]))
            ->assertRedirect();

        // Précédent P6.4d : envoyés, validés nulle part, jamais repris.
        $professionnel = Medecin::sole();
        $this->assertSame('PRO000001', $professionnel->numero_professionnel);
        $this->assertSame('CI', $professionnel->pays_code);
    }

    public function test_les_champs_du_cdc_sont_reellement_persistes(): void
    {
        $this->actingAs($this->gestionnaire)
            ->post(route('portail.medecins.store'), $this->fiche([
                'sexe'                  => 'F',
                'date_naissance'        => '1985-04-12',
                'sous_specialite'       => 'Rythmologie',
                'universite'            => 'Université Félix Houphouët-Boigny',
                'annee_diplome'         => 2012,
                'experience_annees'     => 13,
                'telephone'             => '+2250101020304',
                'email'                 => 'aya.koffi@chu-cocody.ci',
                'biographie'            => 'Praticien hospitalier.',
                'consultation_en_ligne' => '1',
            ]))
            ->assertRedirect();

        $professionnel = Medecin::sole();

        $this->assertSame('F', $professionnel->sexe);
        $this->assertSame('Rythmologie', $professionnel->sous_specialite);
        $this->assertSame(2012, $professionnel->annee_diplome);
        $this->assertSame('aya.koffi@chu-cocody.ci', $professionnel->email);
        $this->assertTrue($professionnel->consultation_en_ligne);
        // Case non envoyée = décochée, et non « valeur précédente conservée ».
        $this->assertFalse($professionnel->consultation_physique);
    }

    public function test_une_annee_de_diplome_dans_le_futur_est_refusee(): void
    {
        $this->actingAs($this->gestionnaire)
            ->post(route('portail.medecins.store'), $this->fiche([
                'annee_diplome' => (int) now()->format('Y') + 3,
            ]))
            ->assertSessionHasErrors('annee_diplome');

        $this->assertSame(0, Medecin::count());
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // LA GARDE CENTRALE : l'établissement ne déclare pas le droit d'exercer
    // ─────────────────────────────────────────────────────────────────────────────

    public function test_un_gestionnaire_ne_peut_pas_declarer_l_autorisation_d_exercer(): void
    {
        $this->actingAs($this->gestionnaire)
            ->post(route('portail.medecins.store'), $this->fiche([
                'ordre_professionnel'  => 'Ordre National des Médecins',
                'numero_ordre'         => 'ONM-4412',
                'autorisation_numero'  => 'AUT-FAUSSE',
                'autorisation_statut'  => 'valide',
                'autorisation_expire_le' => '2099-12-31',
            ]))
            ->assertRedirect();

        $professionnel = Medecin::sole();

        // Silence, pas refus : les champs traversent la requête et ne sont simplement pas repris.
        // Si ce test tombe, c'est qu'un hôpital peut certifier que ses propres médecins ont le
        // droit d'exercer — et le §5.4 s'appuierait dessus pour laisser signer une ordonnance.
        $this->assertNull($professionnel->autorisation_statut);
        $this->assertNull($professionnel->autorisation_numero);
        $this->assertNull($professionnel->numero_ordre);
        $this->assertNull($professionnel->ordre_professionnel);
    }

    public function test_un_compte_habilite_declare_l_autorisation_d_exercer(): void
    {
        // Le miroir du vecteur précédent : une garde qui refuserait tout le monde serait aussi
        // inutilisable qu'une garde qui n'arrête personne.
        $this->actingAs($this->habilite())
            ->post(route('portail.medecins.store'), $this->fiche([
                'ordre_professionnel'      => 'Ordre National des Médecins',
                'numero_ordre'             => 'ONM-4412',
                'autorisation_numero'      => 'AUT-2024-118',
                'autorisation_statut'      => 'valide',
                'autorisation_delivree_le' => '2024-01-15',
                'autorisation_expire_le'   => '2030-01-15',
            ]))
            ->assertRedirect();

        $professionnel = Medecin::sole();

        $this->assertSame('valide', $professionnel->autorisation_statut);
        $this->assertSame('ONM-4412', $professionnel->numero_ordre);
        $this->assertSame('2030-01-15', $professionnel->autorisation_expire_le->toDateString());
    }

    public function test_une_autorisation_expirant_avant_d_avoir_ete_delivree_est_refusee_au_formulaire(): void
    {
        // Renversement détection → INTERDICTION, comme le couple région/district en P6.4d : le
        // référentiel saurait le détecter après coup, mais au formulaire l'agent a encore
        // l'information sous les yeux.
        $this->actingAs($this->habilite())
            ->post(route('portail.medecins.store'), $this->fiche([
                'autorisation_statut'      => 'valide',
                'autorisation_delivree_le' => '2030-01-15',
                'autorisation_expire_le'   => '2024-01-15',
            ]))
            ->assertSessionHasErrors('autorisation_expire_le');

        $this->assertSame(0, Medecin::count());
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // Lieux d'exercice (§5.2)
    // ─────────────────────────────────────────────────────────────────────────────

    private function professionnelCree(): Medecin
    {
        $this->actingAs($this->gestionnaire)->post(route('portail.medecins.store'), $this->fiche());

        return Medecin::sole();
    }

    private function autreEtablissement(): StructureSanitaire
    {
        return StructureSanitaire::create([
            'nom' => 'Polyclinique Sainte-Anne', 'type' => 'clinique_privee', 'adresse' => 'Marcory',
            'commune' => 'Marcory', 'latitude' => 5.30, 'longitude' => -3.98, 'actif' => true,
        ]);
    }

    public function test_un_gestionnaire_ne_peut_pas_declarer_un_second_lieu_d_exercice(): void
    {
        $professionnel = $this->professionnelCree();

        // Un hôpital qui pourrait l'écrire seul se rattacherait le médecin d'un confrère sans que
        // celui-ci en sache rien.
        $this->actingAs($this->gestionnaire)
            ->post(route('portail.medecins.exercices.store', $professionnel), [
                'structure_id' => $this->autreEtablissement()->id,
            ])
            ->assertForbidden();

        $this->assertSame(1, ExerciceProfessionnel::count());
    }

    public function test_un_compte_habilite_declare_un_second_lieu_d_exercice(): void
    {
        $professionnel = $this->professionnelCree();
        $autre = $this->autreEtablissement();

        $this->actingAs($this->habilite())
            ->post(route('portail.medecins.exercices.store', $professionnel), [
                'structure_id' => $autre->id,
            ])
            ->assertRedirect();

        $this->assertSame(2, ExerciceProfessionnel::where('medecin_id', $professionnel->id)->count());
        $this->assertFalse(
            ExerciceProfessionnel::where('structure_id', $autre->id)->sole()->est_principal,
        );
    }

    public function test_un_lieu_d_exercice_en_double_est_refuse(): void
    {
        $professionnel = $this->professionnelCree();

        $this->actingAs($this->habilite())
            ->post(route('portail.medecins.exercices.store', $professionnel), [
                'structure_id' => $this->structure->id,
            ])
            ->assertSessionHasErrors('structure_id');

        $this->assertSame(1, ExerciceProfessionnel::count());
    }

    public function test_l_exercice_principal_ne_peut_pas_etre_retire(): void
    {
        $professionnel = $this->professionnelCree();
        $principal = ExerciceProfessionnel::sole();

        // Le retirer laisserait le référentiel affirmer que ce praticien n'exerce pas là où le
        // patient le réserve (P3/P4, validés G5).
        $this->actingAs($this->habilite())
            ->delete(route('portail.medecins.exercices.destroy', [$professionnel, $principal]))
            ->assertSessionHasErrors('exercice');

        $this->assertSame(1, ExerciceProfessionnel::count());
    }

    public function test_un_lieu_d_exercice_secondaire_se_retire(): void
    {
        $professionnel = $this->professionnelCree();
        $secondaire = ExerciceProfessionnel::create([
            'medecin_id' => $professionnel->id, 'structure_id' => $this->autreEtablissement()->id,
            'est_principal' => false, 'actif' => true,
        ]);

        $this->actingAs($this->habilite())
            ->delete(route('portail.medecins.exercices.destroy', [$professionnel, $secondaire]))
            ->assertRedirect();

        $this->assertSame(1, ExerciceProfessionnel::count());
    }

    public function test_l_exercice_d_un_autre_praticien_est_introuvable(): void
    {
        $professionnel = $this->professionnelCree();

        $autreFiche = Medecin::create([
            'structure_id' => $this->structure->id, 'service_id' => $this->service->id,
            'titre' => 'Dr', 'prenom' => 'Koffi', 'nom' => 'Yao', 'specialite' => 'ORL', 'actif' => true,
        ]);
        $exerciceDeLAutre = ExerciceProfessionnel::create([
            'medecin_id' => $autreFiche->id, 'structure_id' => $this->autreEtablissement()->id,
            'est_principal' => false, 'actif' => true,
        ]);

        // Anti-IDOR : l'identifiant est dans l'URL, donc on vérifie l'appartenance (CDC_10 §5).
        $this->actingAs($this->habilite())
            ->delete(route('portail.medecins.exercices.destroy', [$professionnel, $exerciceDeLAutre]))
            ->assertNotFound();

        $this->assertNotNull($exerciceDeLAutre->fresh());
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // Le rôle `medecin` devient utilisable (décision propriétaire P5)
    // ─────────────────────────────────────────────────────────────────────────────

    public function test_un_compte_medecin_accede_au_portail(): void
    {
        $medecin = User::factory()->create([
            'structure_id' => $this->structure->id,
            'password'     => bcrypt('Medecin@2026!'),
            'actif'        => true,
        ]);
        $medecin->assignRole('medecin');

        // Il était créé depuis P1 et refusé par `AuthController` : un praticien devait emprunter
        // un compte `agent_garde`, c'est-à-dire l'identité d'un agent d'accueil.
        $this->post(route('portail.login'), [
            'email' => $medecin->email, 'password' => 'Medecin@2026!',
        ])->assertRedirect(route('portail.dashboard'));

        $this->assertAuthenticatedAs($medecin);
    }

    public function test_le_role_medecin_ecrit_au_carnet_mais_ne_tient_pas_l_accueil(): void
    {
        $role = Role::findByName('medecin', 'web');

        // Ce qu'il reçoit : le soin. `dossier.ecrire` n'était donnée à aucun rôle en P7-D0 faute
        // de rôle de soin — ce rôle est le destinataire que ce commentaire annonçait.
        $this->assertTrue($role->hasPermissionTo('dossier.ecrire'));
        $this->assertTrue($role->hasPermissionTo('qr.scan'));
        $this->assertTrue($role->hasPermissionTo('dossier.referent'));

        // P11.0 — `rdv.validate` PASSE DE FAUX À VRAI, ET CE VECTEUR EST RÉÉCRIT POUR DIRE LA
        // GARANTIE NEUVE plutôt que corrigé pour passer (précédent P6.4d).
        //
        // P6.5a écrivait ici : « `rdv.validate` reste à l'accueil : CDC_11 §9 prévoit bien une
        // validation finale par le médecin, mais ce circuit est celui de P4, validé G5, et on ne
        // le rouvre pas au détour d'un incrément sur les référentiels. » C'était une dette
        // annoncée avec son porteur ; P11.0 est ce porteur. Le §9.1 est littéral : « Le médecin
        // fait la validation finale. » Jusqu'ici l'accueil pouvait confirmer un rendez-vous et le
        // praticien concerné, non.
        $this->assertTrue(
            $role->hasPermissionTo('rdv.validate'),
            'CDC_11 §9.1 confie la validation finale au médecin.',
        );

        // Ce qu'il ne reçoit toujours pas, et le titre de ce vecteur reste donc vrai : ouvrir des
        // créneaux est un acte d'organisation du service, que le §9.1 confie explicitement à
        // l'accueil ; et un praticien ne se décrit pas lui-même dans l'annuaire national.
        $this->assertFalse($role->hasPermissionTo('disponibilite.manage'));
        $this->assertFalse($role->hasPermissionTo('medecin.manage'));
        // Et surtout pas l'habilitation : un praticien ne se déclare pas lui-même autorisé.
        $this->assertFalse($role->hasPermissionTo('professionnel.habiliter'));
    }

    public function test_l_habilitation_n_est_donnee_a_aucun_role_metier(): void
    {
        // Cinquième occurrence du précédent `urgence.bris_de_glace` / `dossier.ecrire` /
        // `referentiel.*` : la permission existe, elle s'accorde nominativement.
        foreach (['gestionnaire_etablissement', 'personnel_accueil', 'medecin'] as $nom) {
            $this->assertFalse(
                Role::findByName($nom, 'web')->hasPermissionTo('professionnel.habiliter'),
                "Le rôle « {$nom} » ne doit pas porter `professionnel.habiliter`.",
            );
        }
    }
}
