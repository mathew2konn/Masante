<?php

namespace Tests\Feature;

use App\Models\Avis;
use App\Models\MembreFamille;
use App\Models\RendezVous;
use App\Models\ServiceEtablissement;
use App\Models\StructureSanitaire;
use App\Models\User;
use Database\Seeders\PortailRolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Module 4 / 4.7 (comptes) et 4.8 (statistiques).
 *
 * Points sensibles : les comptes PATIENTS n'apparaissent jamais dans l'administration des comptes,
 * on ne peut pas se verrouiller dehors, et les statistiques du gestionnaire sont cloisonnées.
 */
class ComptesStatsPortailTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PortailRolesSeeder::class);
    }

    private function admin(): User
    {
        $u = User::factory()->create(['structure_id' => null, 'actif' => true]);
        $u->assignRole('admin_ivoirsante');

        return $u;
    }

    private function structure(string $nom = 'CHU Test'): StructureSanitaire
    {
        return StructureSanitaire::create([
            'nom' => $nom, 'type' => 'chu', 'adresse' => 'Abidjan', 'commune' => 'Cocody',
            'latitude' => 5.35, 'longitude' => -3.98, 'actif' => true,
        ]);
    }

    private function gestionnaire(StructureSanitaire $s): User
    {
        $u = User::factory()->create(['structure_id' => $s->id, 'actif' => true]);
        $u->assignRole('gestionnaire_etablissement');

        return $u;
    }

    private function rdv(StructureSanitaire $s, ServiceEtablissement $service, string $statut): RendezVous
    {
        return RendezVous::create([
            'membre_id' => MembreFamille::factory()->create()->id,
            'structure_id' => $s->id, 'service_id' => $service->id,
            'motif' => 'Test', 'date_souhaitee' => now()->addDay()->toDateString(),
            'mode_attribution' => 'etablissement_attribue', 'statut' => $statut,
        ]);
    }

    // ---- 4.7 Comptes --------------------------------------------------------

    public function test_les_comptes_patients_n_apparaissent_pas_dans_l_administration(): void
    {
        $structure = $this->structure();
        $this->gestionnaire($structure);

        // Un patient : compte sans aucun rôle portail.
        $patient = User::factory()->create(['nom' => 'Patientdupont']);

        $this->actingAs($this->admin())
            ->get(route('portail.comptes.index'))
            ->assertOk()
            ->assertDontSee('Patientdupont')
            ->assertDontSee($patient->email);
    }

    public function test_un_patient_ne_peut_pas_etre_suspendu_depuis_l_ecran_des_comptes(): void
    {
        $patient = User::factory()->create(['actif' => true]);

        $this->actingAs($this->admin())
            ->patch(route('portail.comptes.toggle', $patient))
            ->assertNotFound();

        $this->assertTrue($patient->fresh()->actif);
    }

    public function test_l_admin_ne_peut_ni_se_desactiver_ni_suspendre_le_dernier_admin(): void
    {
        // `PortailRolesSeeder` crée un admin de bootstrap : il faut le compter.
        $bootstrap = User::where('email', 'admin@masante.ci')->firstOrFail();
        $admin = $this->admin();

        // Se désactiver soi-même : refusé (on se verrouillerait dehors).
        $this->actingAs($admin)->patch(route('portail.comptes.toggle', $admin))->assertSessionHasErrors('compte');
        $this->assertTrue($admin->fresh()->actif);

        // Tant qu'un autre admin reste actif, la suspension est autorisée.
        $this->actingAs($admin)->patch(route('portail.comptes.toggle', $bootstrap))->assertRedirect();
        $this->assertFalse($bootstrap->fresh()->actif);

        // `$admin` est désormais le dernier admin actif : plus personne ne peut le suspendre.
        $this->actingAs($bootstrap)->patch(route('portail.comptes.toggle', $admin))->assertSessionHasErrors('compte');
        $this->assertTrue($admin->fresh()->actif);
    }

    public function test_l_admin_suspend_un_gestionnaire_et_regenere_son_lien_d_activation(): void
    {
        $gestionnaire = $this->gestionnaire($this->structure());
        $gestionnaire->update(['password' => null]);   // créé en 4.2, jamais activé
        $admin = $this->admin();

        $this->actingAs($admin)
            ->post(route('portail.comptes.lien', $gestionnaire))
            ->assertRedirect()
            ->assertSessionHas('lien_activation');

        $this->actingAs($admin)->patch(route('portail.comptes.toggle', $gestionnaire))->assertRedirect();
        $this->assertFalse($gestionnaire->fresh()->actif);
    }

    public function test_le_gestionnaire_n_accede_pas_a_l_administration_des_comptes(): void
    {
        $this->actingAs($this->gestionnaire($this->structure()))
            ->get(route('portail.comptes.index'))
            ->assertForbidden();
    }

    // ---- 4.8 Statistiques ---------------------------------------------------

    public function test_les_statistiques_globales_sont_reservees_a_l_admin(): void
    {
        $structure = $this->structure();

        $this->actingAs($this->gestionnaire($structure))
            ->get(route('portail.statistiques.global'))
            ->assertForbidden();

        $this->actingAs($this->admin())->get(route('portail.statistiques.global'))->assertOk();
    }

    public function test_les_statistiques_du_gestionnaire_sont_cloisonnees_a_son_etablissement(): void
    {
        $mien = $this->structure('Mon CHU');
        $autre = $this->structure('Autre CHU');

        $monService = ServiceEtablissement::create(['structure_id' => $mien->id, 'nom_service' => 'Urgences', 'specialite' => 'urgences', 'actif' => true]);
        $sonService = ServiceEtablissement::create(['structure_id' => $autre->id, 'nom_service' => 'ORL', 'specialite' => 'orl', 'actif' => true]);

        // 2 confirmés + 1 refusé chez moi ; 5 confirmés chez l'autre (ne doivent pas compter).
        $this->rdv($mien, $monService, 'confirme');
        $this->rdv($mien, $monService, 'confirme');
        $this->rdv($mien, $monService, 'refuse');
        for ($i = 0; $i < 5; $i++) {
            $this->rdv($autre, $sonService, 'confirme');
        }

        $reponse = $this->actingAs($this->gestionnaire($mien))
            ->get(route('portail.statistiques.etablissement'))
            ->assertOk();

        $reponse->assertViewHas('rdvTotal', 3);
        $reponse->assertViewHas('tauxConfirmation', 67);   // 2 confirmés sur 3 tranchés
        $reponse->assertViewHas('rdvParStatut', fn ($stats) => $stats['confirme'] === 2 && $stats['refuse'] === 1);
    }

    public function test_le_taux_de_confirmation_ignore_les_demandes_non_tranchees(): void
    {
        $structure = $this->structure();
        $service = ServiceEtablissement::create(['structure_id' => $structure->id, 'nom_service' => 'Urgences', 'specialite' => 'urgences', 'actif' => true]);

        // Aucune demande tranchée : le taux vaut 0, sans division par zéro.
        $this->rdv($structure, $service, 'en_attente');

        $this->actingAs($this->gestionnaire($structure))
            ->get(route('portail.statistiques.etablissement'))
            ->assertOk()
            ->assertViewHas('tauxConfirmation', 0);
    }

    public function test_l_admin_sans_etablissement_ne_voit_pas_les_stats_d_etablissement(): void
    {
        // L'admin possède toutes les permissions, mais n'est rattaché à aucun établissement.
        $this->actingAs($this->admin())
            ->get(route('portail.statistiques.etablissement'))
            ->assertForbidden();
    }

    public function test_la_note_moyenne_globale_ne_compte_que_les_avis_visibles(): void
    {
        $structure = $this->structure();
        Avis::create(['structure_id' => $structure->id, 'user_id' => User::factory()->create()->id, 'note' => 5, 'visible' => true]);
        Avis::create(['structure_id' => $structure->id, 'user_id' => User::factory()->create()->id, 'note' => 1, 'visible' => false]);

        $this->actingAs($this->admin())
            ->get(route('portail.statistiques.global'))
            ->assertOk()
            ->assertViewHas('avisVisibles', 1)
            ->assertViewHas('noteMoyenne', 5.0);
    }
}
