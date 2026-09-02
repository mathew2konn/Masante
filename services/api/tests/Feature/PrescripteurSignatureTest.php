<?php

namespace Tests\Feature;

use App\Models\CertificatNumerique;
use App\Models\Medecin;
use App\Models\MembreFamille;
use App\Models\Ordonnance;
use App\Models\ServiceEtablissement;
use App\Models\SignatureElectronique;
use App\Models\StructureSanitaire;
use App\Models\User;
use App\Services\EcritureSoignantService;
use App\Services\Pki\AutoriteCertification;
use App\Services\Professionnel\AttributeurNumeroProfessionnel;
use Database\Seeders\PortailRolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * P6.5b — Le prescripteur cesse d'être déclaré par le client, et l'écran « Ma signature ».
 *
 * ═══ CE QUE CETTE SUITE REFERME ═══
 *
 * Le constat qui a ouvert P6.5 : `ordonnances.medecin_nom` était `required|string|max:200`, saisi
 * au formulaire, **y compris quand c'était le soignant lui-même qui écrivait**. Une ordonnance
 * pouvait donc porter le nom de n'importe quel médecin.
 *
 * Le vecteur central est donc celui-ci : un soignant envoie « Dr Quelqu'un d'Autre », et le
 * serveur écrit SON nom à lui. Sans ce test, la correction pourrait disparaître d'un refactoring
 * sans que rien ne le signale.
 *
 * ═══ ET CE QU'ELLE PROTÈGE EN MIROIR ═══
 *
 * Le chemin du PATIENT n'est pas touché : quelqu'un qui recopie une ordonnance papier continue de
 * saisir le nom du médecin qui la lui a remise. Le lui imposer depuis un compte qu'il n'a pas
 * serait absurde — et ce serait une régression sur un module validé G5.
 */
class PrescripteurSignatureTest extends TestCase
{
    use RefreshDatabase;

    private StructureSanitaire $structure;

    private ServiceEtablissement $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PortailRolesSeeder::class);
        config(['pki.ca_passphrase' => 'phrase-de-test-du-g3']);

        $this->structure = StructureSanitaire::create([
            'nom' => 'CHU de Cocody', 'type' => 'chu', 'adresse' => 'Abidjan', 'commune' => 'Cocody',
            'latitude' => 5.35, 'longitude' => -3.98, 'actif' => true,
        ]);
        $this->service = ServiceEtablissement::create([
            'structure_id' => $this->structure->id, 'nom_service' => 'Cardiologie',
            'specialite' => 'cardiologie', 'actif' => true,
        ]);
    }

    /** Un compte de soignant, relié ou non à une fiche professionnelle. */
    private function soignant(bool $avecFiche = true, array $fiche = []): User
    {
        $compte = User::factory()->create(['structure_id' => $this->structure->id]);
        $compte->assignRole('medecin');
        $compte->givePermissionTo('dossier.ecrire');

        if ($avecFiche) {
            $professionnel = Medecin::create(array_merge([
                'structure_id'             => $this->structure->id,
                'service_id'               => $this->service->id,
                'user_id'                  => $compte->id,
                'titre'                    => 'Dr',
                'prenom'                   => 'Aya',
                'nom'                      => 'Koffi',
                'specialite'               => 'Cardiologie',
                'profession'               => 'medecin_specialiste',
                'autorisation_numero'      => 'AUT-2024-118',
                'autorisation_statut'      => 'valide',
                'autorisation_delivree_le' => '2024-01-15',
                'autorisation_expire_le'   => '2030-01-15',
                'actif'                    => true,
            ], $fiche));

            app(AttributeurNumeroProfessionnel::class)->attribuer($professionnel);
        }

        return $compte->fresh();
    }

    /** @return array<string, mixed> Une ordonnance telle que le formulaire l'envoie. */
    private function ordonnanceSaisie(string $medecinDeclare = "Dr Quelqu'un d'Autre"): array
    {
        return [
            'medecin_nom'         => $medecinDeclare,
            'structure_sanitaire' => 'Clinique inventée',
            'date_prescription'   => '2026-08-13',
            'medicaments_json'    => [['nom' => 'Paracétamol 500 mg']],
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // LE VECTEUR CENTRAL — le prescripteur vient du serveur
    // ─────────────────────────────────────────────────────────────────────────────

    public function test_le_soignant_ne_peut_plus_declarer_un_autre_prescripteur(): void
    {
        $soignant = $this->soignant();
        $enfant   = MembreFamille::factory()->create();

        $entree = app(EcritureSoignantService::class)->ecrire(
            $soignant, $enfant, 'qr_scan', 'ordonnances', $this->ordonnanceSaisie(),
        );

        // Si ce test tombe, une ordonnance peut de nouveau porter le nom de n'importe quel médecin.
        $this->assertSame('Dr Aya Koffi', $entree->medecin_nom);
        $this->assertSame('CHU de Cocody', $entree->structure_sanitaire);
    }

    public function test_le_chemin_du_patient_n_est_pas_touche(): void
    {
        // Le miroir, et il est indispensable : un patient qui recopie une ordonnance papier doit
        // continuer de nommer le médecin qui la lui a remise. Sans ce vecteur, la correction
        // ci-dessus aurait pu déborder sur un module validé G5.
        $membre = MembreFamille::factory()->create();

        $ordonnance = $membre->ordonnances()->create($this->ordonnanceSaisie('Dr Médecin de Ville'));

        $this->assertSame('Dr Médecin de Ville', $ordonnance->medecin_nom);
        $this->assertSame('patient', $ordonnance->source);
    }

    public function test_un_soignant_sans_fiche_professionnelle_ecrit_toujours(): void
    {
        // La réécriture n'a lieu que si une fiche existe. Un soignant non encore relié doit
        // continuer d'écrire — sinon P6.5b casserait P7-D0, validé G5, pour tout compte pas
        // encore rattaché.
        $entree = app(EcritureSoignantService::class)->ecrire(
            $this->soignant(avecFiche: false),
            MembreFamille::factory()->create(),
            'qr_scan',
            'ordonnances',
            $this->ordonnanceSaisie('Dr Saisi à la main'),
        );

        $this->assertSame('Dr Saisi à la main', $entree->medecin_nom);
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // L'écran « Ma signature »
    // ─────────────────────────────────────────────────────────────────────────────

    public function test_un_praticien_autorise_obtient_son_certificat(): void
    {
        app(AutoriteCertification::class)->creerAutorite();
        $soignant = $this->soignant();

        $this->actingAs($soignant)
            ->post(route('portail.signature.creer'), [
                'secret'              => 'mon-secret-solide',
                'secret_confirmation' => 'mon-secret-solide',
            ])
            ->assertRedirect(route('portail.signature.index'));

        $this->assertSame(1, CertificatNumerique::where('statut', 'actif')->count());
    }

    public function test_un_praticien_sans_autorisation_d_exercer_n_obtient_rien(): void
    {
        // LA GARDE DE P6.5a EST CE QUI REND L'AUTO-DEMANDE ACCEPTABLE : l'autorité ne certifie que
        // ce que le référentiel affirme déjà, et `autorisation_statut` n'est écrite que par un
        // compte portant `professionnel.habiliter`.
        app(AutoriteCertification::class)->creerAutorite();
        $soignant = $this->soignant(fiche: ['autorisation_statut' => null]);

        $this->actingAs($soignant)
            ->post(route('portail.signature.creer'), [
                'secret'              => 'mon-secret-solide',
                'secret_confirmation' => 'mon-secret-solide',
            ])
            ->assertSessionHasErrors('secret');

        $this->assertSame(0, CertificatNumerique::count());
    }

    public function test_un_secret_mal_confirme_est_refuse(): void
    {
        // Une faute de frappe au scellement rendrait le certificat inutilisable dès sa création,
        // sans aucun moyen de la rattraper : le secret n'est stocké nulle part.
        app(AutoriteCertification::class)->creerAutorite();

        $this->actingAs($this->soignant())
            ->post(route('portail.signature.creer'), [
                'secret'              => 'mon-secret-solide',
                'secret_confirmation' => 'mon-secret-solidE',
            ])
            ->assertSessionHasErrors('secret');

        $this->assertSame(0, CertificatNumerique::count());
    }

    public function test_un_compte_sans_la_permission_n_atteint_pas_l_ecran(): void
    {
        $compte = User::factory()->create(['structure_id' => $this->structure->id]);
        $compte->assignRole('personnel_accueil');

        $this->actingAs($compte)->get(route('portail.signature.index'))->assertForbidden();
    }

    public function test_la_revocation_conserve_le_certificat(): void
    {
        app(AutoriteCertification::class)->creerAutorite();
        $soignant = $this->soignant();
        $fiche    = Medecin::where('user_id', $soignant->id)->sole();
        app(AutoriteCertification::class)->emettre($fiche, 'mon-secret-solide');

        $this->actingAs($soignant)
            ->post(route('portail.signature.revoquer'), ['motif' => 'Secret compromis.'])
            ->assertRedirect(route('portail.signature.index'));

        // Il n'est PAS supprimé : les signatures déjà posées le référencent, et une signature dont
        // le certificat aurait disparu deviendrait invérifiable — ce qui reviendrait à l'effacer.
        $certificat = CertificatNumerique::sole();
        $this->assertSame('revoque', $certificat->statut);
        $this->assertSame('Secret compromis.', $certificat->revocation_motif);
    }

    public function test_la_verification_d_une_ordonnance_est_consultable(): void
    {
        app(AutoriteCertification::class)->creerAutorite();
        $soignant = $this->soignant();
        $entree   = app(EcritureSoignantService::class)->ecrire(
            $soignant, MembreFamille::factory()->create(), 'qr_scan', 'ordonnances', $this->ordonnanceSaisie(),
        );

        $this->actingAs($soignant)
            ->get(route('portail.signature.verifier', ['type' => 'ordonnance', 'id' => $entree->id]))
            ->assertOk()
            // « Non signé » n'est pas « invalide » : une ordonnance non signée reste une ordonnance.
            ->assertSee('Document non signé');
    }

    public function test_le_role_medecin_porte_la_signature_et_pas_l_habilitation(): void
    {
        $role = Role::findByName('medecin', 'web');

        $this->assertTrue($role->hasPermissionTo('document.signer'));
        // Signer ses propres prescriptions relève du soin ; déclarer un droit d'exercer, non.
        $this->assertFalse($role->hasPermissionTo('professionnel.habiliter'));
    }

    public function test_signer_a_l_ecriture_reste_facultatif(): void
    {
        // Décision assumée : P7-D0 est validé G5, et un praticien sans certificat doit continuer
        // d'écrire. Ce qui est INCONDITIONNEL, c'est le nom du prescripteur — vérifié plus haut.
        $entree = app(EcritureSoignantService::class)->ecrire(
            $this->soignant(), MembreFamille::factory()->create(), 'qr_scan', 'ordonnances', $this->ordonnanceSaisie(),
        );

        $this->assertInstanceOf(Ordonnance::class, $entree);
        $this->assertSame(0, SignatureElectronique::count());
    }
}
