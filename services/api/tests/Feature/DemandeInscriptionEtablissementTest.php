<?php

namespace Tests\Feature;

use App\Models\DemandeInscriptionEtablissement;
use App\Models\StructureSanitaire;
use App\Models\User;
use App\Services\OnboardingEtablissementService;
use Database\Seeders\PortailRolesSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * P11.1 — La méthode 2 de CDC_11 §3 : l'établissement demande, la plateforme valide.
 *
 * Elle était ouverte depuis P6.4a sous le nom de limite **M1**, reportée deux fois, pendant que
 * CDC_11 §3 affirmait que « les deux méthodes sont implémentées ». Ces vecteurs éprouvent les
 * trois garanties qui font la différence entre une candidature et un établissement.
 */
class DemandeInscriptionEtablissementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(PortailRolesSeeder::class);
    }

    /** @return array<string, mixed> */
    private function candidature(array $ecrasements = []): array
    {
        return array_merge([
            'nom' => 'Clinique Saint Joseph',
            'type' => 'clinique_privee',
            'statut_juridique' => 'prive',
            'numero_autorisation' => 'AUT-2026-00417',
            'adresse' => 'Rue des Jardins, Cocody',
            'commune' => 'Cocody',
            'telephone' => '+2252722000000',
            'email' => 'contact@saintjoseph.ci',
            'demandeur_nom' => 'Kouassi',
            'demandeur_prenom' => 'Aya',
            'demandeur_fonction' => 'Directrice administrative',
            'demandeur_email' => 'aya.kouassi@saintjoseph.ci',
            'demandeur_telephone' => '+2250700112233',
            'message' => 'Nous souhaitons rejoindre la plateforme.',
        ], $ecrasements);
    }

    private function agentHabilite(): User
    {
        $agent = User::factory()->create(['actif' => true]);
        $agent->assignRole('admin_ivoirsante');

        return $agent;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Le dépôt public
    // ─────────────────────────────────────────────────────────────────────────

    #[Test]
    public function une_candidature_se_depose_sans_aucun_jeton(): void
    {
        // C'est tout le point de la méthode 2 : le demandeur n'a ni compte ni contact préalable.
        // Exiger un jeton ici la ramènerait à la méthode 1.
        $reponse = $this->postJson('/api/v1/etablissements/demandes', $this->candidature());

        $reponse->assertCreated()
            ->assertJsonPath('statut', DemandeInscriptionEtablissement::EN_ATTENTE);

        $this->assertMatchesRegularExpression('/^DEM-[A-Z0-9]{10}$/', $reponse->json('reference'));
    }

    #[Test]
    public function une_candidature_n_ecrit_rien_dans_l_annuaire_des_etablissements(): void
    {
        // LA garantie centrale. `structures_sanitaires` est lue par l'annuaire PUBLIC de P3, par
        // le référentiel gouverné de P6.4a et par l'orientation après triage de P10a : y déposer
        // un candidat non vérifié le ferait apparaître à un patient qui cherche où se soigner.
        $avant = StructureSanitaire::count();

        $this->postJson('/api/v1/etablissements/demandes', $this->candidature())->assertCreated();

        $this->assertSame($avant, StructureSanitaire::count());
        $this->assertDatabaseCount('demandes_inscription_etablissement', 1);
    }

    #[Test]
    public function le_client_ne_peut_pas_declarer_l_etat_de_sa_propre_demande_par_http(): void
    {
        // Première des deux couches : la règle de validation écarte les clés non déclarées.
        $this->postJson('/api/v1/etablissements/demandes', $this->candidature([
            'statut' => DemandeInscriptionEtablissement::APPROUVEE,
            'reference' => 'DEM-AUTOACCORD',
            'structure_id' => 999,
        ]))->assertCreated();

        $demande = DemandeInscriptionEtablissement::firstOrFail();

        $this->assertSame(DemandeInscriptionEtablissement::EN_ATTENTE, $demande->statut);
        $this->assertNotSame('DEM-AUTOACCORD', $demande->reference);
        $this->assertNull($demande->structure_id);
    }

    #[Test]
    public function le_client_ne_peut_pas_declarer_l_etat_meme_en_assignation_de_masse(): void
    {
        // SECONDE couche, et le vecteur est DÉDOUBLÉ exprès : celui du dessus prouve le
        // validateur, pas le modèle. Un import, une commande ou un futur endpoint qui
        // assignerait en masse n'aurait pas de `validate()` devant lui. Parade de P6.6b, après
        // quatre occurrences de « le vecteur prouve autre chose ».
        $demande = new DemandeInscriptionEtablissement($this->candidature([
            'statut' => DemandeInscriptionEtablissement::APPROUVEE,
            'reference' => 'DEM-AUTOACCORD',
            'structure_id' => 999,
        ]));

        $this->assertNull($demande->statut);
        $this->assertNull($demande->reference);
        $this->assertNull($demande->structure_id);
    }

    #[Test]
    public function une_seconde_demande_pour_la_meme_adresse_est_refusee_en_rappelant_la_reference(): void
    {
        $premiere = $this->postJson('/api/v1/etablissements/demandes', $this->candidature());
        $premiere->assertCreated();

        $this->postJson('/api/v1/etablissements/demandes', $this->candidature(['nom' => 'Autre nom']))
            ->assertStatus(422)
            ->assertJsonFragment(['demandeur_email' => [
                'Une demande est déjà en cours pour cette adresse (référence '
                .$premiere->json('reference').'). Attendez sa décision avant d’en déposer une autre.',
            ]]);

        $this->assertDatabaseCount('demandes_inscription_etablissement', 1);
    }

    #[Test]
    public function le_numero_d_autorisation_est_exige(): void
    {
        // C'est ce qui rend une demande vérifiable : sans lui, la plateforme n'a rien à
        // confronter à l'autorité de tutelle et ne peut que croire le demandeur sur parole.
        $this->postJson('/api/v1/etablissements/demandes', $this->candidature(['numero_autorisation' => '']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('numero_autorisation');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Le suivi public
    // ─────────────────────────────────────────────────────────────────────────

    #[Test]
    public function le_suivi_rend_l_etat_de_la_decision_et_jamais_le_contenu_depose(): void
    {
        $reference = $this->postJson('/api/v1/etablissements/demandes', $this->candidature())
            ->json('reference');

        $reponse = $this->getJson("/api/v1/etablissements/demandes/{$reference}");

        $reponse->assertOk()->assertJsonPath('statut', DemandeInscriptionEtablissement::EN_ATTENTE);

        // Rien du dossier déposé ne doit ressortir : la référence peut être interceptée, elle ne
        // doit pas devenir un moyen de relire les coordonnées d'un établissement candidat.
        foreach (['nom', 'numero_autorisation', 'demandeur_email', 'telephone', 'adresse'] as $fuite) {
            $reponse->assertJsonMissingPath($fuite);
        }
    }

    #[Test]
    public function une_reference_inconnue_rend_404_et_jamais_403(): void
    {
        // Un 403 confirmerait qu'une demande existe à cet identifiant (précédent P10a).
        $this->getJson('/api/v1/etablissements/demandes/DEM-INEXISTANT')->assertNotFound();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Le traitement par la plateforme
    // ─────────────────────────────────────────────────────────────────────────

    #[Test]
    public function la_file_des_candidatures_est_refusee_a_un_compte_non_habilite(): void
    {
        $this->postJson('/api/v1/etablissements/demandes', $this->candidature())->assertCreated();

        $medecin = User::factory()->create(['actif' => true]);
        $medecin->assignRole('medecin');

        $this->actingAs($medecin, 'sanctum')
            ->getJson('/api/v1/portail/demandes-inscription')
            ->assertForbidden();
    }

    #[Test]
    public function approuver_fait_naitre_l_etablissement_son_gestionnaire_et_le_lien(): void
    {
        $reference = $this->postJson('/api/v1/etablissements/demandes', $this->candidature())
            ->json('reference');
        $demande = DemandeInscriptionEtablissement::where('reference', $reference)->firstOrFail();

        $reponse = $this->actingAs($this->agentHabilite(), 'sanctum')
            ->postJson("/api/v1/portail/demandes-inscription/{$demande->id}/approuver", [
                'latitude' => 5.35,
                'longitude' => -3.98,
                'gestionnaire_nom' => 'Kouassi',
                'gestionnaire_prenom' => 'Aya',
                'gestionnaire_email' => 'aya.kouassi@saintjoseph.ci',
            ]);

        $reponse->assertOk()->assertJsonPath('statut', DemandeInscriptionEtablissement::APPROUVEE);
        $this->assertStringContainsString('activation', $reponse->json('lien_activation'));

        $structure = StructureSanitaire::where('nom', 'Clinique Saint Joseph')->firstOrFail();
        $this->assertSame($structure->id, $demande->fresh()->structure_id);

        // Le gestionnaire naît SANS mot de passe : c'est l'activation qui le lui fait choisir,
        // et personne — pas même l'agent qui vient d'approuver — n'en connaît un.
        $gestionnaire = User::where('email', 'aya.kouassi@saintjoseph.ci')->firstOrFail();
        $this->assertNull($gestionnaire->password);
        $this->assertTrue($gestionnaire->hasRole('gestionnaire_etablissement'));
        $this->assertSame($structure->id, $gestionnaire->structure_id);
    }

    #[Test]
    public function la_decision_nomme_son_auteur_et_sa_date(): void
    {
        $reference = $this->postJson('/api/v1/etablissements/demandes', $this->candidature())
            ->json('reference');
        $demande = DemandeInscriptionEtablissement::where('reference', $reference)->firstOrFail();

        $agent = $this->agentHabilite();

        $this->actingAs($agent, 'sanctum')
            ->postJson("/api/v1/portail/demandes-inscription/{$demande->id}/rejeter", [
                'motif_rejet' => 'Numéro d’autorisation introuvable auprès de la tutelle.',
            ])->assertOk();

        $decidee = $demande->fresh();

        $this->assertSame($agent->id, $decidee->decide_par);
        $this->assertSame($agent->nomLisible(), $decidee->decide_par_nom);
        $this->assertNotNull($decidee->decide_le);
    }

    #[Test]
    public function une_demande_deja_traitee_rend_409_et_non_403(): void
    {
        // L'agent A LE DROIT de décider ; c'est CETTE demande qui n'est plus à décider.
        // Confondre les deux ferait croire à un défaut d'habilitation (précédent P7-C).
        $reference = $this->postJson('/api/v1/etablissements/demandes', $this->candidature())
            ->json('reference');
        $demande = DemandeInscriptionEtablissement::where('reference', $reference)->firstOrFail();

        $agent = $this->agentHabilite();
        $motif = ['motif_rejet' => 'Dossier incomplet, pièces légales absentes.'];

        $this->actingAs($agent, 'sanctum')
            ->postJson("/api/v1/portail/demandes-inscription/{$demande->id}/rejeter", $motif)
            ->assertOk();

        $this->actingAs($agent, 'sanctum')
            ->postJson("/api/v1/portail/demandes-inscription/{$demande->id}/rejeter", $motif)
            ->assertStatus(409);
    }

    #[Test]
    public function un_rejet_sans_motif_est_refuse(): void
    {
        $reference = $this->postJson('/api/v1/etablissements/demandes', $this->candidature())
            ->json('reference');
        $demande = DemandeInscriptionEtablissement::where('reference', $reference)->firstOrFail();

        $this->actingAs($this->agentHabilite(), 'sanctum')
            ->postJson("/api/v1/portail/demandes-inscription/{$demande->id}/rejeter", ['motif_rejet' => ''])
            ->assertStatus(422)
            ->assertJsonValidationErrors('motif_rejet');
    }

    #[Test]
    public function le_moteur_refuse_lui_aussi_un_rejet_sans_motif(): void
    {
        // Garde du moteur, en plus de celle du code : c'est en base qu'on relira ces lignes dans
        // dix ans, et une décision de refus sans raison n'y voudrait rien dire.
        $this->postJson('/api/v1/etablissements/demandes', $this->candidature())->assertCreated();

        $this->expectException(QueryException::class);

        DB::table('demandes_inscription_etablissement')->update([
            'statut' => DemandeInscriptionEtablissement::REJETEE,
            'motif_rejet' => null,
        ]);
    }

    #[Test]
    public function le_moteur_refuse_une_approbation_qui_ne_designe_aucun_etablissement(): void
    {
        $this->postJson('/api/v1/etablissements/demandes', $this->candidature())->assertCreated();

        $this->expectException(QueryException::class);

        DB::table('demandes_inscription_etablissement')->update([
            'statut' => DemandeInscriptionEtablissement::APPROUVEE,
            'structure_id' => null,
        ]);
    }

    #[Test]
    public function une_adresse_de_gestionnaire_deja_prise_est_refusee_sans_rien_creer(): void
    {
        User::factory()->create(['email' => 'deja@pris.ci']);

        $reference = $this->postJson('/api/v1/etablissements/demandes', $this->candidature())
            ->json('reference');
        $demande = DemandeInscriptionEtablissement::where('reference', $reference)->firstOrFail();

        $avant = StructureSanitaire::count();

        $this->actingAs($this->agentHabilite(), 'sanctum')
            ->postJson("/api/v1/portail/demandes-inscription/{$demande->id}/approuver", [
                'latitude' => 5.35, 'longitude' => -3.98,
                'gestionnaire_nom' => 'X', 'gestionnaire_prenom' => 'Y',
                'gestionnaire_email' => 'deja@pris.ci',
            ])->assertStatus(422);

        // Rien ne doit avoir été écrit : une structure créée puis laissée sans gestionnaire
        // serait un établissement que personne ne peut administrer.
        $this->assertSame($avant, StructureSanitaire::count());
        $this->assertTrue($demande->fresh()->estEnAttente());
    }

    #[Test]
    public function l_agent_peut_rectifier_le_type_mais_pas_le_reste_de_la_candidature(): void
    {
        // « Clinique » pour un cabinet est la faute la plus fréquente d'un demandeur, et la
        // laisser fausserait durablement les statistiques du §4.4. Le reste fait foi : l'agent
        // vérifie une candidature, il ne la ressaisit pas.
        $reference = $this->postJson('/api/v1/etablissements/demandes', $this->candidature())
            ->json('reference');
        $demande = DemandeInscriptionEtablissement::where('reference', $reference)->firstOrFail();

        $this->actingAs($this->agentHabilite(), 'sanctum')
            ->postJson("/api/v1/portail/demandes-inscription/{$demande->id}/approuver", [
                'latitude' => 5.35, 'longitude' => -3.98,
                'type' => 'cabinet',
                'nom' => 'Nom réécrit par l’agent',
                'numero_autorisation' => 'AUT-FALSIFIE',
                'gestionnaire_nom' => 'Kouassi', 'gestionnaire_prenom' => 'Aya',
                'gestionnaire_email' => 'aya.kouassi@saintjoseph.ci',
            ])->assertOk();

        $structure = StructureSanitaire::latest('id')->firstOrFail();

        $this->assertSame('cabinet', $structure->type, 'Le type doit être rectifiable.');
        $this->assertSame('Clinique Saint Joseph', $structure->nom, 'Le nom vient de la candidature.');
        $this->assertSame('AUT-2026-00417', $structure->numero_autorisation);
    }

    #[Test]
    public function les_deux_methodes_d_onboarding_produisent_le_meme_acte(): void
    {
        // Vecteur de la source unique : la méthode 1 (l'administrateur crée) et la méthode 2
        // (l'approbation d'une candidature) passent par le MÊME service. S'ils divergeaient, ce
        // serait du côté qu'on regarde le moins.
        $service = app(OnboardingEtablissementService::class);

        $resultat = $service->creer([
            'nom' => 'CHU de contrôle', 'type' => 'chu', 'adresse' => 'Abidjan',
            'commune' => 'Cocody', 'latitude' => 5.35, 'longitude' => -3.98,
        ], ['nom' => 'Yao', 'prenom' => 'Koffi', 'email' => 'yao@controle.ci']);

        $this->assertTrue($resultat->structure->actif);
        $this->assertNull($resultat->gestionnaire->password);
        $this->assertTrue($resultat->gestionnaire->hasRole('gestionnaire_etablissement'));
        $this->assertSame($resultat->structure->id, $resultat->gestionnaire->structure_id);
        $this->assertStringContainsString('activation', $resultat->lienActivation);
    }
}
