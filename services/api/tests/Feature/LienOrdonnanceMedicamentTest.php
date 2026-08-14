<?php

namespace Tests\Feature;

use App\Models\Medecin;
use App\Models\Medicament;
use App\Models\MembreFamille;
use App\Models\Ordonnance;
use App\Models\ServiceEtablissement;
use App\Models\StructureSanitaire;
use App\Models\User;
use App\Services\Medicament\AttributeurCodeMedicament;
use App\Services\Medicament\ServiceInteractions;
use App\Services\Pki\AutoriteCertification;
use App\Services\Pki\ServiceSignature;
use App\Services\Professionnel\AttributeurNumeroProfessionnel;
use Database\Seeders\PortailRolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * P6.6b — Le lien entre une ordonnance et le référentiel national (CDC_09 §6.1).
 *
 * Ce que ces vecteurs doivent tenir :
 *
 *  · le lien est FACULTATIF — le nom libre continue de suffire (un patient qui recopie une
 *    ordonnance papier ne choisit pas dans une liste) ;
 *  · quand il est fourni, le code national, la DCI et le dosage viennent du RÉFÉRENTIEL et jamais
 *    du client, et ils sont FIGÉS — une ordonnance signée doit continuer de dire ce qui a été
 *    prescrit ce jour-là ;
 *  · la garantie vaut sur les TROIS chemins d'écriture (patient, délégué, soignant) ;
 *  · un produit retiré est PRESCRIPTIBLE et SIGNALÉ — refuser serait une décision médicale ;
 *  · **une ordonnance signée avant P6.6b reste INTÈGRE** ;
 *  · les interactions se DEMANDENT, elles ne s'imposent pas à la prescription.
 */
class LienOrdonnanceMedicamentTest extends TestCase
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

    private function url(string $suffixe = ''): string
    {
        return "/api/v1/membres/{$this->membre->id}/ordonnances".$suffixe;
    }

    private function medicament(array $remplacements = []): Medicament
    {
        $m = Medicament::create(array_merge([
            'nom_generique' => 'Paracétamol',
            'categorie'     => 'Analgésique',
            'forme'         => 'comprime',
            'dosage'        => '500 mg',
        ], $remplacements));

        app(AttributeurCodeMedicament::class)->attribuer($m);

        return $m->fresh();
    }

    /** @param array<int, array<string, mixed>> $medicaments */
    private function prescrire(array $medicaments)
    {
        return $this->postJson($this->url(), [
            'medecin_nom'         => 'Dr Aya Koffi',
            'structure_sanitaire' => 'CHU de Cocody',
            'date_prescription'   => '2026-08-14',
            'medicaments_json'    => $medicaments,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // Le lien reste facultatif
    // ─────────────────────────────────────────────────────────────────────────────

    public function test_une_ordonnance_sans_lien_reste_acceptee(): void
    {
        // Non-régression : le chemin du patient qui recopie une ordonnance papier ne bouge pas.
        $this->prescrire([['nom' => 'Doliprane 500', 'posologie' => '3/jour']])
            ->assertCreated()
            ->assertJsonPath('item.medicaments_json.0.nom', 'Doliprane 500');

        $ligne = Ordonnance::first()->medicaments_json[0];

        $this->assertArrayNotHasKey('medicament_id', $ligne);
        $this->assertArrayNotHasKey('code_national', $ligne);
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // Quand il est fourni, le serveur ne croit rien du client
    // ─────────────────────────────────────────────────────────────────────────────

    public function test_le_serveur_fige_le_code_la_dci_et_le_dosage(): void
    {
        $produit = $this->medicament();

        $this->prescrire([['nom' => 'Doliprane 500', 'medicament_id' => $produit->id]])->assertCreated();

        $ligne = Ordonnance::first()->medicaments_json[0];

        $this->assertSame($produit->id, $ligne['medicament_id']);
        $this->assertSame('MED000001', $ligne['code_national']);
        $this->assertSame('Paracétamol', $ligne['dci']);
        $this->assertSame('500 mg', $ligne['dosage_referentiel']);
        // Le texte du prescripteur reste le sien : on ne le réécrit pas.
        $this->assertSame('Doliprane 500', $ligne['nom']);
    }

    public function test_le_client_ne_peut_pas_declarer_le_code_ni_la_dci(): void
    {
        $produit = $this->medicament();

        $this->prescrire([[
            'nom'           => 'Doliprane 500',
            'medicament_id' => $produit->id,
            'code_national' => 'MED999999',
            'dci'           => 'Molécule inventée',
        ]])->assertCreated();

        $ligne = Ordonnance::first()->medicaments_json[0];

        $this->assertSame('MED000001', $ligne['code_national']);
        $this->assertSame('Paracétamol', $ligne['dci']);
    }

    public function test_la_validation_ecarte_les_cles_non_declarees(): void
    {
        // PREMIÈRE COUCHE. `validate()` ne renvoie que les chemins déclarés dans les règles : une
        // clé `code_national` envoyée par le client n'atteint jamais le service.
        $this->prescrire([['nom' => 'Produit inconnu', 'code_national' => 'MED999999', 'dci' => 'Fausse DCI']])
            ->assertCreated();

        $ligne = Ordonnance::first()->medicaments_json[0];

        $this->assertArrayNotHasKey('code_national', $ligne);
        $this->assertArrayNotHasKey('dci', $ligne);
    }

    public function test_le_service_ecarte_les_cles_derivees_meme_appele_directement(): void
    {
        // SECONDE COUCHE, et c'est celle qu'il fallait éprouver ici.
        //
        // Le premier jeu de vecteurs passait AUSSI quand la garde du service était retirée : la
        // validation écartait déjà les clés, si bien qu'ils prouvaient le comportement du
        // validateur et non celui du service. Un appelant qui n'a pas validé — un import, un
        // script, ce fichier de test lui-même — doit pourtant obtenir la même garantie.
        $resolu = app(\App\Services\Medicament\ServiceLienMedicament::class)->resoudre([
            ['nom' => 'Produit inconnu', 'code_national' => 'MED999999', 'dci' => 'Fausse DCI',
                'dosage_referentiel' => '9 g'],
        ]);

        $this->assertArrayNotHasKey('code_national', $resolu[0]);
        $this->assertArrayNotHasKey('dci', $resolu[0]);
        $this->assertArrayNotHasKey('dosage_referentiel', $resolu[0]);
        $this->assertSame('Produit inconnu', $resolu[0]['nom']);
    }

    public function test_un_medicament_inconnu_est_refuse_avec_un_message_qui_le_nomme(): void
    {
        $this->prescrire([['nom' => 'Fantôme', 'medicament_id' => 4242]])
            ->assertStatus(422)
            ->assertJsonValidationErrors('medicaments_json');

        $this->assertSame(0, Ordonnance::count());
    }

    public function test_les_valeurs_figees_ne_bougent_plus_quand_le_referentiel_change(): void
    {
        $produit = $this->medicament();

        $this->prescrire([['nom' => 'Doliprane 500', 'medicament_id' => $produit->id]])->assertCreated();

        // Le laboratoire cède la marque, l'autorité corrige le dosage.
        $produit->update(['nom_generique' => 'Paracétamol (révisé)', 'dosage' => '1000 mg']);

        $ligne = Ordonnance::first()->fresh()->medicaments_json[0];

        $this->assertSame('Paracétamol', $ligne['dci'], 'Une ordonnance a changé de contenu toute seule.');
        $this->assertSame('500 mg', $ligne['dosage_referentiel']);
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // Un produit retiré : signalé, jamais bloqué
    // ─────────────────────────────────────────────────────────────────────────────

    public function test_un_medicament_retire_est_prescriptible_et_signale(): void
    {
        $produit = $this->medicament(['nom_generique' => 'Produit retiré', 'statut_marche' => 'retire']);

        $reponse = $this->prescrire([['nom' => 'Produit retiré', 'medicament_id' => $produit->id]])
            ->assertCreated();

        // PRESCRIT : refuser serait une décision médicale prise par une machine (CDC_00 §4).
        $this->assertSame(1, Ordonnance::count());
        // SIGNALÉ : le prescripteur doit savoir.
        $this->assertSame('retire', $reponse->json('avertissements.0.statut'));
        $this->assertStringContainsString('retiré du marché', $reponse->json('avertissements.0.message'));
    }

    public function test_un_medicament_autorise_ne_produit_aucun_avertissement(): void
    {
        $produit = $this->medicament();

        $reponse = $this->prescrire([['nom' => 'Doliprane', 'medicament_id' => $produit->id]])->assertCreated();

        $this->assertNull($reponse->json('avertissements'));
    }

    public function test_les_interactions_ne_sont_PAS_calculees_a_la_prescription(): void
    {
        // Choix du propriétaire : « donnée du référentiel + consultation explicite », et NON
        // « signalement au moment de prescrire ». Les calculer ici rapprocherait P6.6 d'une aide à
        // la décision, terrain de CDC_05 et CDC_08.
        $a = $this->medicament(['nom_generique' => 'Warfarine']);
        $b = $this->medicament(['nom_generique' => 'Aspirine']);
        app(ServiceInteractions::class)->declarer($a, $b, 'contre_indication', 'Risque hémorragique.', null, 'Thesaurus');

        $reponse = $this->prescrire([
            ['nom' => 'Warfarine', 'medicament_id' => $a->id],
            ['nom' => 'Aspirine', 'medicament_id' => $b->id],
        ])->assertCreated();

        $this->assertNull($reponse->json('avertissements'));
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // Les trois chemins d'écriture
    // ─────────────────────────────────────────────────────────────────────────────

    public function test_le_chemin_du_delegue_resout_le_lien_au_depot(): void
    {
        $produit = $this->medicament();

        $proprietaire = User::factory()->create();
        $membre = MembreFamille::factory()->for($proprietaire)->create();

        \App\Models\Delegation::create([
            'titulaire_user_id' => $proprietaire->id,
            'delegue_user_id'   => $this->user->id,
            'membre_id'         => $membre->id,
            'droits'            => 'lecture_ecriture',
            'invitee_at'        => now(),
            'acceptee_at'       => now(),
        ]);

        $contribution = app(\App\Services\ContributionCarnetService::class)->deposer(
            $this->user,
            $membre,
            'ordonnances',
            [
                'medecin_nom'         => 'Dr Aya Koffi',
                'structure_sanitaire' => 'CHU de Cocody',
                'date_prescription'   => '2026-08-14',
                'medicaments_json'    => [['nom' => 'Doliprane', 'medicament_id' => $produit->id]],
            ],
        );

        // Résolu AU DÉPÔT : le brouillon montre au responsable ce que l'auteur a choisi.
        $this->assertSame('MED000001', $contribution->donnees['medicaments_json'][0]['code_national']);
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // LE VECTEUR OBLIGATOIRE — les signatures déjà posées
    // ─────────────────────────────────────────────────────────────────────────────

    public function test_une_ordonnance_signee_AVANT_le_lien_reste_integre(): void
    {
        // Sans ce vecteur, on découvrirait le problème sur une ordonnance réelle — et *une
        // signature qui casse toute seule ne prouve plus rien, pire, elle accuse* (P6.5b).
        [$praticien, $compte] = $this->praticienCertifie();

        $ordonnance = $this->membre->ordonnances()->create([
            'medecin_nom'         => $praticien->nom_complet,
            'structure_sanitaire' => 'CHU de Cocody',
            'date_prescription'   => '2026-08-13',
            // La forme d'AVANT P6.6b : aucune des clés neuves.
            'medicaments_json'    => [['nom' => 'Paracétamol 500 mg', 'posologie' => '3/jour']],
        ]);

        app(ServiceSignature::class)->signer($compte, 'ordonnance', $ordonnance->id, 'secret-de-signature');

        $verdict = app(ServiceSignature::class)->verifier('ordonnance', $ordonnance->id);

        $this->assertTrue($verdict['signe']);
        $this->assertTrue($verdict['integre'], 'La migration P6.6b a cassé une signature déjà posée.');
    }

    public function test_une_ordonnance_signee_AVEC_le_lien_reste_integre_et_reste_alterable(): void
    {
        [$praticien, $compte] = $this->praticienCertifie();
        $produit = $this->medicament();

        $ordonnance = $this->membre->ordonnances()->create([
            'medecin_nom'         => $praticien->nom_complet,
            'structure_sanitaire' => 'CHU de Cocody',
            'date_prescription'   => '2026-08-13',
            'medicaments_json'    => app(\App\Services\Medicament\ServiceLienMedicament::class)
                ->resoudre([['nom' => 'Doliprane', 'medicament_id' => $produit->id]]),
        ]);

        app(ServiceSignature::class)->signer($compte, 'ordonnance', $ordonnance->id, 'secret-de-signature');
        $this->assertTrue(app(ServiceSignature::class)->verifier('ordonnance', $ordonnance->id)['integre']);

        // Le miroir : la signature RÉVÈLE toujours une modification (§5.3).
        $lignes = $ordonnance->fresh()->medicaments_json;
        $lignes[0]['dosage_referentiel'] = '1000 mg';
        $ordonnance->update(['medicaments_json' => $lignes]);

        $this->assertFalse(app(ServiceSignature::class)->verifier('ordonnance', $ordonnance->id)['integre']);
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // La consultation des interactions
    // ─────────────────────────────────────────────────────────────────────────────

    public function test_la_consultation_rapporte_les_interactions_declarees(): void
    {
        $a = $this->medicament(['nom_generique' => 'Warfarine']);
        $b = $this->medicament(['nom_generique' => 'Aspirine']);
        app(ServiceInteractions::class)->declarer($a, $b, 'contre_indication', 'Risque hémorragique majeur.', 'Surveiller l\'INR.', 'Thesaurus ANSM');

        $reponse = $this->getJson("/api/v1/medicaments/interactions?medicament_id[]={$a->id}&medicament_id[]={$b->id}")
            ->assertOk();

        $this->assertCount(1, $reponse->json('interactions'));
        $this->assertSame('contre_indication', $reponse->json('interactions.0.niveau'));
        $this->assertSame('Contre-indication', $reponse->json('interactions.0.niveau_libelle'));
        $this->assertSame('Thesaurus ANSM', $reponse->json('interactions.0.source'));
        // La réponse dit ce qu'elle n'est pas.
        $this->assertStringContainsString('ne remplace pas', $reponse->json('avertissement'));
    }

    public function test_la_consultation_resout_par_MOLECULE_et_pas_par_identifiant(): void
    {
        // L'interaction est déclarée sur les GÉNÉRIQUES ; le patient prend une MARQUE de la même
        // molécule. Chercher les seuls identifiants prescrits produirait un silence qui
        // ressemblerait à « aucune interaction ».
        $warfarineGenerique = $this->medicament(['nom_generique' => 'Warfarine']);
        $aspirineGenerique  = $this->medicament(['nom_generique' => 'Aspirine']);
        $aspegic = $this->medicament(['nom_generique' => 'Aspirine', 'nom_commercial' => 'Aspégic']);

        app(ServiceInteractions::class)->declarer(
            $warfarineGenerique, $aspirineGenerique, 'contre_indication', 'Risque hémorragique.', null, 'Thesaurus'
        );

        $reponse = $this->getJson("/api/v1/medicaments/interactions?medicament_id[]={$warfarineGenerique->id}&medicament_id[]={$aspegic->id}")
            ->assertOk();

        $this->assertCount(1, $reponse->json('interactions'), 'L\'équivalence par DCI n\'a pas joué.');
    }

    public function test_la_consultation_exige_au_moins_deux_medicaments(): void
    {
        $a = $this->medicament();

        $this->getJson("/api/v1/medicaments/interactions?medicament_id[]={$a->id}")
            ->assertStatus(422);
    }

    public function test_la_consultation_cite_la_version_du_referentiel(): void
    {
        $a = $this->medicament(['nom_generique' => 'Warfarine']);
        $b = $this->medicament(['nom_generique' => 'Aspirine']);

        // Aucune version publiée : la réponse le dit plutôt que de laisser croire qu'elle fait
        // autorité. C'est le même refus de mentir que l'estampille nulle de L2.
        $reponse = $this->getJson("/api/v1/medicaments/interactions?medicament_id[]={$a->id}&medicament_id[]={$b->id}")
            ->assertOk();

        $this->assertNull($reponse->json('referentiel'));
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // Fixtures lourdes
    // ─────────────────────────────────────────────────────────────────────────────

    /** @return array{0: Medecin, 1: User} */
    private function praticienCertifie(): array
    {
        $this->seed(PortailRolesSeeder::class);
        config(['pki.ca_passphrase' => 'phrase-de-test-du-g3']);

        $structure = StructureSanitaire::create([
            'nom' => 'CHU de Cocody', 'type' => 'chu', 'adresse' => 'Boulevard de France',
            'commune' => 'Cocody', 'latitude' => 5.35, 'longitude' => -3.98, 'actif' => true,
        ]);
        $service = ServiceEtablissement::create([
            'structure_id' => $structure->id, 'nom_service' => 'Cardiologie',
            'specialite' => 'cardiologie', 'actif' => true,
        ]);

        $compte = User::factory()->create(['structure_id' => $structure->id]);
        $compte->assignRole('medecin');

        $fiche = Medecin::create([
            'structure_id' => $structure->id, 'service_id' => $service->id, 'user_id' => $compte->id,
            'titre' => 'Dr', 'prenom' => 'Aya', 'nom' => 'Koffi', 'specialite' => 'Cardiologie',
            'profession' => 'medecin_specialiste',
            'autorisation_numero' => 'AUT-2024-118', 'autorisation_statut' => 'valide',
            'autorisation_delivree_le' => '2024-01-15', 'autorisation_expire_le' => '2030-01-15',
            'actif' => true,
        ]);
        app(AttributeurNumeroProfessionnel::class)->attribuer($fiche);

        $autorite = app(AutoriteCertification::class);
        $autorite->creerAutorite();
        $autorite->emettre($fiche->fresh(), 'secret-de-signature');

        return [$fiche->fresh(), $compte->fresh()];
    }
}
