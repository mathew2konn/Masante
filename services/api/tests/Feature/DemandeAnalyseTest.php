<?php

namespace Tests\Feature;

use App\Models\AccesDossier;
use App\Models\Analyse;
use App\Models\DemandeAnalyse;
use App\Models\Medecin;
use App\Models\MembreFamille;
use App\Models\ServiceEtablissement;
use App\Models\StructureSanitaire;
use App\Models\User;
use App\Services\EcritureSoignantService;
use App\Services\Pki\AutoriteCertification;
use App\Services\Pki\DocumentPrescriptionBiologique;
use App\Services\Pki\ServiceSignature;
use App\Services\ServiceConsultation;
use App\Services\SessionDossierService;
use App\Support\RegistreDocumentsSignables;
use App\Support\RegistreSectionsCarnet;
use App\Support\StatutDemandeAnalyse;
use Database\Seeders\PortailRolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * B5-a — la demande d'examen, analogue exact de l'ordonnance (plan G1 PLAN 4, décision L1).
 *
 * CE QUE CETTE SUITE PROTÈGE, EN TROIS TEMPS :
 *
 *  1. K5/K11 refermé : un client ne peut plus poser `source: 'structure'` sur sa propre saisie,
 *     ni ici ni sur les trois sections déjà existantes (voir aussi `CarnetSectionTest`).
 *  2. Le circuit transpose B2-c → B3-a : un praticien produit une pièce identifiée, le lien au
 *     catalogue est facultatif-relu-figé (patron P6.7a), et le jeton est un secret d'accès distinct
 *     de l'étiquette de prélèvement (L5, qui n'existe qu'en B5-b).
 *  3. La signature (L8) branche la TROISIÈME entité du registre PKI (K2) sans casser le contenu
 *     canonique existant des ordonnances.
 */
class DemandeAnalyseTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PortailRolesSeeder::class);
    }

    /** @return array{0: User, 1: Medecin, 2: StructureSanitaire} */
    private function soignantAvecFiche(): array
    {
        $structure = StructureSanitaire::create([
            'nom' => 'CHU de Cocody', 'type' => 'chu', 'adresse' => 'Abidjan', 'commune' => 'Cocody',
            'latitude' => 5.35, 'longitude' => -3.98, 'actif' => true,
        ]);

        $service = ServiceEtablissement::create([
            'structure_id' => $structure->id, 'nom_service' => 'Médecine générale',
            'specialite' => 'medecine_generale', 'actif' => true,
        ]);

        $user = User::factory()->create(['structure_id' => $structure->id]);
        $user->givePermissionTo('dossier.ecrire');

        $fiche = Medecin::create([
            'user_id' => $user->id, 'structure_id' => $structure->id, 'service_id' => $service->id,
            'nom' => 'Kablan', 'prenom' => 'Koffi', 'specialite' => 'medecine_generale',
        ]);

        $fiche->forceFill([
            'numero_professionnel' => 'PRO000778',
            'pays_code' => 'CI',
            'profession' => 'medecin_generaliste',
        ])->save();

        return [$user->fresh(), $fiche->fresh(), $structure];
    }

    private function patient(): MembreFamille
    {
        return MembreFamille::factory()->for(User::factory()->create())->create();
    }

    /** @return array<string, mixed> */
    private function demande(array $extra = []): array
    {
        return array_merge([
            'medecin_nom' => 'Saisi par le client',
            'structure_sanitaire' => 'Saisie par le client',
            'date_demande' => '2026-09-05',
            'analyses_json' => [['libelle' => 'Numération formule sanguine']],
        ], $extra);
    }

    private function ecriture(): EcritureSoignantService
    {
        return app(EcritureSoignantService::class);
    }

    private function analyseDuCatalogue(): Analyse
    {
        // `code` et `pays_code` sont HORS `$fillable` (un client ne choisit pas un identifiant
        // national) : assignation directe, comme le fait `AttributeurCodeAnalyse`.
        $analyse = Analyse::create([
            'libelle' => 'Glycémie à jeun', 'unite' => 'g/L', 'categorie' => 'biochimie',
        ]);

        $analyse->forceFill(['code' => 'ANA900001', 'pays_code' => 'CI'])->save();

        return $analyse->fresh();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // K5/K11 — la porte `source` est fermée, dès l'origine, sur la section neuve
    // ─────────────────────────────────────────────────────────────────────────

    public function test_source_declaree_par_le_client_est_ignoree(): void
    {
        $user = User::factory()->create();
        $membre = MembreFamille::factory()->for($user)->create();

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/membres/{$membre->id}/demandes-analyses", $this->demande([
                'source' => 'structure',
            ]))
            ->assertCreated()
            ->assertJsonPath('item.source', 'patient');

        $this->assertDatabaseHas('demandes_analyses', [
            'membre_id' => $membre->id, 'source' => 'patient',
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Le circuit transpose B2-c → B3-a (L1)
    // ─────────────────────────────────────────────────────────────────────────

    public function test_une_demande_du_soignant_designe_sa_fiche_et_son_etablissement(): void
    {
        [$soignant, $fiche, $structure] = $this->soignantAvecFiche();
        $patient = $this->patient();

        /** @var DemandeAnalyse $entree */
        $entree = $this->ecriture()->ecrire(
            $soignant, $patient, 'qr_scan', 'demandes-analyses', $this->demande()
        );

        $this->assertSame($fiche->id, $entree->medecin_id);
        $this->assertSame($structure->id, $entree->structure_id);
        $this->assertSame($fiche->nom_complet, $entree->medecin_nom);
        $this->assertSame('medecin', $entree->source);
        $this->assertSame('medecin', $entree->added_by);
    }

    public function test_une_demande_saisie_par_le_patient_ne_designe_aucun_prescripteur(): void
    {
        $patient = $this->patient();

        $entree = $patient->demandesAnalyses()->create($this->demande());

        $this->assertNull($entree->medecin_id);
        $this->assertNull($entree->structure_id);
        $this->assertNull($entree->consultation_id);
        $this->assertSame('patient', $entree->source);
    }

    /** Comme pour les ordonnances : le rattachement à la consultation en cours, ou son absence. */
    public function test_une_demande_ecrite_pendant_une_consultation_s_y_rattache(): void
    {
        [$soignant] = $this->soignantAvecFiche();
        $patient = $this->patient();

        $acces = AccesDossier::create([
            'membre_id' => $patient->id, 'agent_id' => $soignant->id,
            'type_acces' => 'qr_scan', 'etablissement' => 'CHU de Cocody',
        ]);
        app(SessionDossierService::class)->ouvrir($acces);
        $consultation = app(ServiceConsultation::class)->ouvrir($soignant, 'Fatigue persistante');

        /** @var DemandeAnalyse $entree */
        $entree = $this->ecriture()->ecrire(
            $soignant, $patient, 'qr_scan', 'demandes-analyses', $this->demande()
        );

        $this->assertSame($consultation->id, $entree->consultation_id);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Le lien facultatif au catalogue (L2) — patron P6.7a, réutilisé, pas réécrit
    // ─────────────────────────────────────────────────────────────────────────

    public function test_un_examen_rattache_au_catalogue_fige_le_code_et_l_unite(): void
    {
        $analyse = $this->analyseDuCatalogue();
        $patient = $this->patient();

        $this->actingAs($patient->user, 'sanctum')
            ->postJson("/api/v1/membres/{$patient->id}/demandes-analyses", $this->demande([
                'analyses_json' => [['libelle' => 'Glycémie', 'analyse_id' => $analyse->id]],
            ]))
            ->assertCreated();

        $ligne = DemandeAnalyse::first()->fresh()->lignes()->first();

        $this->assertSame($analyse->id, $ligne->analyse_id);
        $this->assertSame('ANA900001', $ligne->code_national);
        $this->assertSame('g/L', $ligne->unite);
        // Le libellé du prescripteur n'est PAS écrasé (leçon P6.7b) : ses mots restent les siens.
        $this->assertSame('Glycémie', $ligne->libelle);
    }

    public function test_un_examen_inconnu_du_catalogue_est_refuse_en_le_nommant(): void
    {
        $patient = $this->patient();

        $this->actingAs($patient->user, 'sanctum')
            ->postJson("/api/v1/membres/{$patient->id}/demandes-analyses", $this->demande([
                'analyses_json' => [['libelle' => 'Test', 'analyse_id' => 424242]],
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('analyses_json')
            ->assertJsonFragment(['analyses_json' => ["L'analyse n°424242 n'existe pas au catalogue national."]]);

        $this->assertDatabaseCount('demandes_analyses', 0);
    }

    public function test_un_examen_hors_catalogue_est_accepte_libelle_libre(): void
    {
        $patient = $this->patient();

        $this->actingAs($patient->user, 'sanctum')
            ->postJson("/api/v1/membres/{$patient->id}/demandes-analyses", $this->demande([
                'analyses_json' => [['libelle' => 'Dosage rare non catalogué']],
            ]))
            ->assertCreated();

        $ligne = DemandeAnalyse::first()->fresh()->lignes()->first();

        $this->assertNull($ligne->analyse_id);
        $this->assertFalse($ligne->estCodee());
        $this->assertSame('Dosage rare non catalogué', $ligne->libelle);
    }

    public function test_deux_examens_produisent_deux_lignes(): void
    {
        $patient = $this->patient();

        $this->actingAs($patient->user, 'sanctum')
            ->postJson("/api/v1/membres/{$patient->id}/demandes-analyses", $this->demande([
                'analyses_json' => [
                    ['libelle' => 'NFS'],
                    ['libelle' => 'CRP'],
                ],
            ]))
            ->assertCreated();

        $this->assertDatabaseCount('demande_analyse_lignes', 2);
    }

    /** Le `PUT` doit reprojeter les lignes, au même titre que la création (leçon P6.8b). */
    public function test_la_modification_reprojete_les_lignes(): void
    {
        $patient = $this->patient();
        $entree = $patient->demandesAnalyses()->create($this->demande());
        $this->assertDatabaseCount('demande_analyse_lignes', 1);

        $this->actingAs($patient->user, 'sanctum')
            ->putJson("/api/v1/membres/{$patient->id}/demandes-analyses/{$entree->id}", [
                'analyses_json' => [['libelle' => 'NFS'], ['libelle' => 'Ionogramme']],
            ])
            ->assertOk();

        $this->assertDatabaseCount('demande_analyse_lignes', 2);
        $this->assertDatabaseMissing('demande_analyse_lignes', ['libelle' => 'Numération formule sanguine']);
    }

    /**
     * Une demande déjà `servie` n'est plus reprojetée : régénérer ses lignes romprait ce à quoi un
     * prélèvement (B5-b) se rattache (patron `ProjecteurLignesOrdonnance`).
     */
    public function test_une_demande_servie_n_est_plus_reprojetee(): void
    {
        $patient = $this->patient();
        $entree = $patient->demandesAnalyses()->create($this->demande());
        $this->assertDatabaseCount('demande_analyse_lignes', 1);

        $entree->forceFill(['statut' => StatutDemandeAnalyse::SERVIE])->save();

        $entree->forceFill(['analyses_json' => [['libelle' => 'NFS'], ['libelle' => 'CRP'], ['libelle' => 'Ionogramme']]])
            ->save();

        $this->assertDatabaseCount('demande_analyse_lignes', 1);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // L5 — le jeton est un secret d'accès, jamais une donnée exposée
    // ─────────────────────────────────────────────────────────────────────────

    public function test_le_jeton_est_genere_unique_et_jamais_expose(): void
    {
        $patient = $this->patient();

        $reponse = $this->actingAs($patient->user, 'sanctum')
            ->postJson("/api/v1/membres/{$patient->id}/demandes-analyses", $this->demande())
            ->assertCreated();

        $reponse->assertJsonMissingPath('item.jeton_partage');

        $entree = DemandeAnalyse::first();
        $this->assertNotNull($entree->jeton_partage);
        $this->assertSame(48, strlen($entree->jeton_partage));
    }

    public function test_deux_demandes_ne_partagent_jamais_le_meme_jeton(): void
    {
        $patient = $this->patient();
        $a = $patient->demandesAnalyses()->create($this->demande());
        $b = $patient->demandesAnalyses()->create($this->demande());

        $this->assertNotSame($a->jeton_partage, $b->jeton_partage);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // K2/L8 — la troisième entité du registre PKI est branchée
    // ─────────────────────────────────────────────────────────────────────────

    public function test_la_prescription_biologique_est_desormais_branchee_au_registre(): void
    {
        $this->assertTrue(RegistreDocumentsSignables::existe(DocumentPrescriptionBiologique::CODE));
        $this->assertArrayNotHasKey(
            DocumentPrescriptionBiologique::CODE,
            RegistreDocumentsSignables::NON_BRANCHES,
        );
    }

    public function test_une_demande_peut_etre_signee(): void
    {
        [$soignant, $fiche] = $this->soignantAvecFiche();
        $soignant->givePermissionTo('document.signer');
        $fiche->update(['autorisation_statut' => 'valide']);

        config(['pki.ca_passphrase' => 'phrase-de-passe-de-test']);
        app(AutoriteCertification::class)->creerAutorite();
        app(AutoriteCertification::class)->emettre($fiche->fresh(), 'secret-du-praticien');

        /** @var DemandeAnalyse $entree */
        $entree = $this->ecriture()->ecrire(
            $soignant->fresh(), $this->patient(), 'qr_scan', 'demandes-analyses', $this->demande()
        );

        app(ServiceSignature::class)->signer(
            $soignant->fresh(), DocumentPrescriptionBiologique::CODE, $entree->id, 'secret-du-praticien'
        );

        $verdict = app(ServiceSignature::class)->verifier(DocumentPrescriptionBiologique::CODE, $entree->id);

        $this->assertTrue($verdict['integre'] ?? false);
    }

    /**
     * LE POINT LE PLUS SENSIBLE (L8) : l'état du circuit n'entre pas dans le contenu signé, sinon
     * chaque transition (émise → servie) ferait passer TOUTE demande signée pour altérée.
     */
    public function test_un_changement_d_etat_du_circuit_ne_casse_pas_la_signature(): void
    {
        [$soignant, $fiche] = $this->soignantAvecFiche();
        $soignant->givePermissionTo('document.signer');
        $fiche->update(['autorisation_statut' => 'valide']);

        config(['pki.ca_passphrase' => 'phrase-de-passe-de-test']);
        app(AutoriteCertification::class)->creerAutorite();
        app(AutoriteCertification::class)->emettre($fiche->fresh(), 'secret-du-praticien');

        /** @var DemandeAnalyse $entree */
        $entree = $this->ecriture()->ecrire(
            $soignant->fresh(), $this->patient(), 'qr_scan', 'demandes-analyses', $this->demande()
        );

        app(ServiceSignature::class)->signer(
            $soignant->fresh(), DocumentPrescriptionBiologique::CODE, $entree->id, 'secret-du-praticien'
        );

        // Le geste que B5-b posera : le statut change quand un prélèvement est enregistré.
        $entree->forceFill(['statut' => StatutDemandeAnalyse::SERVIE])->save();

        $verdict = app(ServiceSignature::class)->verifier(DocumentPrescriptionBiologique::CODE, $entree->id);

        $this->assertTrue(
            $verdict['integre'] ?? false,
            'Une transition du circuit ne doit JAMAIS casser la signature de la demande.'
        );
    }

    /** Contre-épreuve : ce qui DOIT casser la signature la casse toujours. */
    public function test_modifier_un_examen_demande_casse_la_signature(): void
    {
        [$soignant, $fiche] = $this->soignantAvecFiche();
        $soignant->givePermissionTo('document.signer');
        $fiche->update(['autorisation_statut' => 'valide']);

        config(['pki.ca_passphrase' => 'phrase-de-passe-de-test']);
        app(AutoriteCertification::class)->creerAutorite();
        app(AutoriteCertification::class)->emettre($fiche->fresh(), 'secret-du-praticien');

        /** @var DemandeAnalyse $entree */
        $entree = $this->ecriture()->ecrire(
            $soignant->fresh(), $this->patient(), 'qr_scan', 'demandes-analyses', $this->demande()
        );

        app(ServiceSignature::class)->signer(
            $soignant->fresh(), DocumentPrescriptionBiologique::CODE, $entree->id, 'secret-du-praticien'
        );

        $entree->forceFill(['analyses_json' => [['libelle' => 'Un tout autre examen']]])->save();

        $verdict = app(ServiceSignature::class)->verifier(DocumentPrescriptionBiologique::CODE, $entree->id);

        $this->assertFalse($verdict['integre'] ?? true);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Registre de sections et anti-IDOR structurel
    // ─────────────────────────────────────────────────────────────────────────

    public function test_demandes_analyses_est_au_registre_et_l_auteur_en_est_le_prescripteur(): void
    {
        $this->assertTrue(RegistreSectionsCarnet::existe('demandes-analyses'));
        $this->assertTrue(RegistreSectionsCarnet::auteurEstPrescripteur('demandes-analyses'));
        $this->assertTrue(RegistreSectionsCarnet::ouverteAuSoignant('demandes-analyses'));
    }

    public function test_un_client_ne_peut_pas_lire_les_demandes_d_un_autre_membre(): void
    {
        $autre = $this->patient();
        $autre->demandesAnalyses()->create($this->demande());

        $moi = User::factory()->create();
        $monMembre = MembreFamille::factory()->for($moi)->create();

        $this->actingAs($moi, 'sanctum')
            ->getJson("/api/v1/membres/{$monMembre->id}/demandes-analyses/".DemandeAnalyse::first()->id)
            ->assertNotFound();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // K1 — le circuit n'existait à aucun degré ; vérification de schéma
    // ─────────────────────────────────────────────────────────────────────────

    public function test_les_tables_du_circuit_existent(): void
    {
        $this->assertTrue(Schema::hasTable('demandes_analyses'));
        $this->assertTrue(Schema::hasTable('demande_analyse_lignes'));
    }

    public function test_l_etat_par_defaut_est_emise(): void
    {
        $entree = $this->patient()->demandesAnalyses()->create($this->demande());

        $this->assertSame(StatutDemandeAnalyse::EMISE, $entree->statut);
        $this->assertTrue($entree->estOuverte());
    }
}
