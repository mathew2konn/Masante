<?php

namespace Tests\Feature;

use App\Models\AccesDossier;
use App\Models\MembreFamille;
use App\Models\ServiceEtablissement;
use App\Models\StructureSanitaire;
use App\Models\User;
use App\Services\SessionDossierService;
use Database\Seeders\PortailRolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Module 5 / 5.3 — Bris de glace (Note_Continuite §5), quatrième voie d'accès au dossier.
 *
 * L'enjeu : un accès sans consentement doit être difficile à obtenir, impossible à obtenir par
 * tâtonnement, strictement borné dans son périmètre et sa durée, et intégralement traçable.
 */
class BrisDeGlacePortailTest extends TestCase
{
    use RefreshDatabase;

    private const MOTIF = 'Patiente inconsciente admise aux urgences, identifiee par sa CNI. Titulaire injoignable.';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PortailRolesSeeder::class);
    }

    private function structure(): StructureSanitaire
    {
        return StructureSanitaire::create([
            'nom' => 'CHU Test', 'type' => 'chu', 'adresse' => 'Abidjan', 'commune' => 'Cocody',
            'latitude' => 5.35, 'longitude' => -3.98, 'actif' => true,
        ]);
    }

    private function service(StructureSanitaire $s, string $specialite = 'urgences'): ServiceEtablissement
    {
        return ServiceEtablissement::create([
            'structure_id' => $s->id, 'nom_service' => ucfirst($specialite),
            'specialite' => $specialite, 'actif' => true,
        ]);
    }

    /** Agent affecté à un service, habilité ou non au bris de glace. */
    private function agent(ServiceEtablissement $service, bool $habilite = true): User
    {
        $u = User::factory()->create(['structure_id' => $service->structure_id, 'service_id' => $service->id]);
        $u->assignRole('personnel_accueil');

        if ($habilite) {
            $u->givePermissionTo('urgence.bris_de_glace');
        }

        return $u;
    }

    private function patient(): MembreFamille
    {
        return MembreFamille::factory()->for(User::factory())->create([
            'nom' => 'Koné', 'prenom' => 'Awa', 'date_naissance' => '1996-03-12', 'groupe_sanguin' => 'O+',
        ]);
    }

    private function criteres(array $remplace = []): array
    {
        return array_merge([
            'nom' => 'Koné', 'prenom' => 'Awa', 'date_naissance' => '1996-03-12', 'motif' => self::MOTIF,
        ], $remplace);
    }

    // ---- Habilitation -------------------------------------------------------

    public function test_un_agent_non_habilite_n_accede_pas_au_bris_de_glace(): void
    {
        $agent = $this->agent($this->service($this->structure()), habilite: false);

        $this->actingAs($agent)->get(route('portail.urgence.bris'))->assertForbidden();
    }

    public function test_un_agent_habilite_hors_service_d_urgences_est_refuse(): void
    {
        // Cas réel : agent habilité aux urgences, puis muté en ORL. La permission subsiste, mais
        // l'affectation ne le justifie plus. On revalide la spécialité à chaque accès.
        $structure = $this->structure();
        $agent = $this->agent($this->service($structure, 'orl'));

        $this->actingAs($agent)->get(route('portail.urgence.bris'))->assertForbidden();
        $this->actingAs($agent)->post(route('portail.urgence.bris.ouvrir'), $this->criteres())->assertForbidden();
        $this->assertDatabaseCount('acces_dossier', 0);
    }

    public function test_le_gestionnaire_habilite_uniquement_les_agents_des_urgences(): void
    {
        $structure = $this->structure();
        $gestionnaire = User::factory()->create(['structure_id' => $structure->id]);
        $gestionnaire->assignRole('gestionnaire_etablissement');

        $agentOrl = $this->agent($this->service($structure, 'orl'), habilite: false);
        $agentUrgences = $this->agent($this->service($structure, 'urgences'), habilite: false);

        $this->actingAs($gestionnaire)
            ->patch(route('portail.agents.bris', $agentOrl))
            ->assertSessionHasErrors('agent');
        $this->assertFalse($agentOrl->fresh()->hasPermissionTo('urgence.bris_de_glace'));

        $this->actingAs($gestionnaire)->patch(route('portail.agents.bris', $agentUrgences))->assertRedirect();
        $this->assertTrue($agentUrgences->fresh()->hasPermissionTo('urgence.bris_de_glace'));

        // Bascule inverse : l'habilitation se retire.
        $this->actingAs($gestionnaire)->patch(route('portail.agents.bris', $agentUrgences))->assertRedirect();
        $this->assertFalse($agentUrgences->fresh()->hasPermissionTo('urgence.bris_de_glace'));
    }

    // ---- Identification -----------------------------------------------------

    public function test_l_identification_exige_les_trois_criteres_exacts(): void
    {
        $agent = $this->agent($this->service($this->structure()));
        $this->patient();

        // Un seul critère faux : aucun dossier. On confirme une identité, on n'explore pas.
        foreach ([
            ['nom' => 'Kone2'],
            ['prenom' => 'Awo'],
            ['date_naissance' => '1996-03-13'],
        ] as $faux) {
            $this->actingAs($agent)
                ->post(route('portail.urgence.bris.ouvrir'), $this->criteres($faux))
                ->assertSessionHasErrors('nom');
        }

        // Aucune trace d'accès : le dossier n'a jamais été ouvert.
        $this->assertDatabaseCount('acces_dossier', 0);
    }

    public function test_la_casse_et_les_espaces_ne_font_pas_echouer_l_identification(): void
    {
        $agent = $this->agent($this->service($this->structure()));
        $this->patient();

        $this->actingAs($agent)
            ->post(route('portail.urgence.bris.ouvrir'), $this->criteres(['nom' => '  KONÉ ', 'prenom' => 'awa']))
            ->assertRedirect(route('portail.urgence.dossier'));
    }

    public function test_une_justification_trop_courte_est_refusee(): void
    {
        $agent = $this->agent($this->service($this->structure()));
        $this->patient();

        $this->actingAs($agent)
            ->post(route('portail.urgence.bris.ouvrir'), $this->criteres(['motif' => 'urgence']))
            ->assertSessionHasErrors('motif');

        $this->assertDatabaseCount('acces_dossier', 0);
    }

    // ---- Ouverture, périmètre, clôture --------------------------------------

    public function test_l_ouverture_journalise_le_motif_et_expose_le_vital_minimal(): void
    {
        $agent = $this->agent($this->service($this->structure()));
        $patient = $this->patient();
        $patient->antecedents()->create(['type' => 'allergie', 'description' => 'Pénicilline']);
        $patient->antecedents()->create(['type' => 'chirurgie', 'description' => 'Appendicectomie 2019']);
        $patient->notesObservations()->create(['contenu' => 'Note confidentielle', 'auteur_type' => 'patient']);

        $this->actingAs($agent)
            ->post(route('portail.urgence.bris.ouvrir'), $this->criteres())
            ->assertRedirect(route('portail.urgence.dossier'));

        $ouverture = AccesDossier::firstOrFail();
        $this->assertSame('bris_de_glace', $ouverture->type_acces);
        $this->assertSame(self::MOTIF, $ouverture->motif_urgence);
        $this->assertSame($agent->id, $ouverture->agent_id);

        // Le vital minimal est exposé ; le reste du dossier ne l'est pas.
        $this->get(route('portail.urgence.dossier'))
            ->assertOk()
            ->assertSee('O+')
            ->assertSee('Pénicilline')
            ->assertDontSee('Appendicectomie 2019')
            ->assertDontSee('Note confidentielle');
    }

    public function test_la_fenetre_de_quinze_minutes_se_ferme_et_journalise_la_duree(): void
    {
        $agent = $this->agent($this->service($this->structure()));
        $this->patient();

        $this->actingAs($agent)->post(route('portail.urgence.bris.ouvrir'), $this->criteres());
        $this->get(route('portail.urgence.dossier'))->assertOk();

        // Au-delà de 15 minutes, l'accès est clos et l'agent renvoyé au formulaire.
        $this->travel(SessionDossierService::DUREE_BRIS_DE_GLACE + 1)->minutes();
        $this->get(route('portail.urgence.dossier'))->assertRedirect(route('portail.urgence.bris'));

        // Journal en ajout seul : ouverture + clôture, même motif, durée bornée à 15 minutes.
        $this->assertSame(2, AccesDossier::count());
        $cloture = AccesDossier::latest('id')->first();
        $this->assertSame(SessionDossierService::DUREE_BRIS_DE_GLACE, $cloture->duree_minutes);
        $this->assertSame(self::MOTIF, $cloture->motif_urgence);
        $this->assertSame(['fiche_vitale'], $cloture->sections_consultees);
    }

    public function test_la_fermeture_manuelle_journalise_la_cloture(): void
    {
        $agent = $this->agent($this->service($this->structure()));
        $this->patient();

        $this->actingAs($agent)->post(route('portail.urgence.bris.ouvrir'), $this->criteres());
        $this->post(route('portail.urgence.fermer'))->assertRedirect(route('portail.dashboard'));

        $this->assertSame(2, AccesDossier::count());
        $this->get(route('portail.urgence.dossier'))->assertRedirect(route('portail.urgence.bris'));
    }

    public function test_le_patient_voit_l_acces_d_urgence_dans_son_journal(): void
    {
        $agent = $this->agent($this->service($this->structure()));
        $patient = $this->patient();

        $this->actingAs($agent)->post(route('portail.urgence.bris.ouvrir'), $this->criteres());

        // Transparence (§5.3, garde-fou n°4) : l'accès figure au journal du membre.
        $this->assertDatabaseHas('acces_dossier', [
            'membre_id' => $patient->id, 'type_acces' => 'bris_de_glace', 'motif_urgence' => self::MOTIF,
        ]);
    }
}
