<?php

namespace Tests\Feature;

use App\Models\Medecin;
use App\Models\MembreFamille;
use App\Models\RendezVous;
use App\Models\ServiceEtablissement;
use App\Models\StructureSanitaire;
use App\Models\Triage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * B1-b — Fiche RDV enrichie côté PATIENT (D6/D7) : associer un triage après coup, aperçu du
 * tarif + statut réglé sur la liste, orientation libre à la réservation.
 *
 * Les vecteurs anti-IDOR sur `associerTriage` sont les MÊMES que ceux de `store()` (précédent
 * exact demandé par le plan G1) : membre du compte, triage du compte.
 */
class RendezVousFicheEnrichieTest extends TestCase
{
    use RefreshDatabase;

    private function structure(): StructureSanitaire
    {
        return StructureSanitaire::create([
            'nom' => 'CHU Test', 'type' => 'chu', 'adresse' => 'Abidjan', 'commune' => 'Cocody',
            'latitude' => 5.35, 'longitude' => -3.99,
        ]);
    }

    private function service(StructureSanitaire $s): ServiceEtablissement
    {
        return ServiceEtablissement::create([
            'structure_id' => $s->id, 'nom_service' => 'Cardiologie', 'specialite' => 'cardiologie', 'actif' => true,
        ]);
    }

    private function membreDe(User $user): MembreFamille
    {
        return MembreFamille::factory()->create(['user_id' => $user->id]);
    }

    private function rdv(MembreFamille $membre, ServiceEtablissement $service, string $statut = 'en_attente'): RendezVous
    {
        return RendezVous::create([
            'membre_id' => $membre->id, 'structure_id' => $service->structure_id, 'service_id' => $service->id,
            'motif' => 'Douleur thoracique', 'date_souhaitee' => Carbon::tomorrow()->toDateString(),
            'mode_attribution' => 'etablissement_attribue', 'statut' => $statut,
        ]);
    }

    private function triageDe(User $user, ?MembreFamille $membre = null): Triage
    {
        return Triage::create([
            'user_id' => $user->id, 'membre_id' => $membre?->id,
            'symptomes_json' => [], 'reponses_json' => [], 'score_severite' => 20,
            'niveau' => 'leger', 'recommandation_texte' => 'Repos.',
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // D6 — associer un triage après coup
    // ─────────────────────────────────────────────────────────────────────────────

    public function test_associe_un_triage_du_compte(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $membre = $this->membreDe($user);
        $service = $this->service($this->structure());
        $rdv = $this->rdv($membre, $service);
        $triage = $this->triageDe($user);

        $this->patchJson("/api/v1/rendez-vous/{$rdv->id}/triage", ['triage_id' => $triage->id])
            ->assertOk()
            ->assertJsonPath('rendez_vous.triage_id', $triage->id);

        $this->assertDatabaseHas('rendez_vous', ['id' => $rdv->id, 'triage_id' => $triage->id]);
    }

    public function test_refuse_un_rdv_d_un_autre_compte(): void
    {
        $proprietaire = User::factory()->create();
        $membre = $this->membreDe($proprietaire);
        $service = $this->service($this->structure());
        $rdv = $this->rdv($membre, $service);
        $intrus = User::factory()->create();
        $triage = $this->triageDe($intrus);
        Sanctum::actingAs($intrus);

        $this->patchJson("/api/v1/rendez-vous/{$rdv->id}/triage", ['triage_id' => $triage->id])
            ->assertStatus(403);

        $this->assertDatabaseHas('rendez_vous', ['id' => $rdv->id, 'triage_id' => null]);
    }

    public function test_refuse_un_triage_d_un_autre_compte(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $membre = $this->membreDe($user);
        $service = $this->service($this->structure());
        $rdv = $this->rdv($membre, $service);
        // Le triage appartient à QUELQU'UN D'AUTRE — même mécanisme que store().
        $triage = $this->triageDe(User::factory()->create());

        $this->patchJson("/api/v1/rendez-vous/{$rdv->id}/triage", ['triage_id' => $triage->id])
            ->assertStatus(403);

        $this->assertDatabaseHas('rendez_vous', ['id' => $rdv->id, 'triage_id' => null]);
    }

    public function test_refuse_sur_un_rdv_clos(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $membre = $this->membreDe($user);
        $service = $this->service($this->structure());
        $rdv = $this->rdv($membre, $service, 'honore');
        $triage = $this->triageDe($user);

        $this->patchJson("/api/v1/rendez-vous/{$rdv->id}/triage", ['triage_id' => $triage->id])
            ->assertStatus(422);
    }

    public function test_accepte_encore_sur_un_rdv_confirme(): void
    {
        // Le staff peut encore lire la fiche avant l'acte : associer un triage garde son sens.
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $membre = $this->membreDe($user);
        $service = $this->service($this->structure());
        $rdv = $this->rdv($membre, $service, 'confirme');
        $triage = $this->triageDe($user);

        $this->patchJson("/api/v1/rendez-vous/{$rdv->id}/triage", ['triage_id' => $triage->id])
            ->assertOk();
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // D7 — aperçu du tarif + statut réglé, sur la liste patient
    // ─────────────────────────────────────────────────────────────────────────────

    public function test_la_liste_expose_le_tarif_et_regle_a_faux_avant_paiement(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $membre = $this->membreDe($user);
        $service = $this->service($this->structure());
        $service->update(['tarif_consultation_cfa' => 12000]);
        $this->rdv($membre, $service);

        $this->getJson('/api/v1/rendez-vous')
            ->assertOk()
            ->assertJsonPath('rendez_vous.0.tarif', 12000)
            ->assertJsonPath('rendez_vous.0.tarif_source', 'service')
            ->assertJsonPath('rendez_vous.0.regle', false);
    }

    public function test_regle_passe_a_vrai_apres_paiement(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $membre = $this->membreDe($user);
        $service = $this->service($this->structure());
        $service->update(['tarif_consultation_cfa' => 12000]);
        $rdv = $this->rdv($membre, $service);

        $this->postJson("/api/v1/rendez-vous/{$rdv->id}/paiement", ['mode' => 'mobile_money'])->assertCreated();

        $this->getJson('/api/v1/rendez-vous')
            ->assertOk()
            ->assertJsonPath('rendez_vous.0.regle', true)
            // Le détail du reçu (montant/QR/transaction) n'a rien à faire dans la LISTE.
            ->assertJsonMissingPath('rendez_vous.0.recu');
    }

    public function test_tarif_est_null_sans_aucune_configuration(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $membre = $this->membreDe($user);
        $service = $this->service($this->structure()); // aucun tarif nulle part
        $this->rdv($membre, $service);

        $this->getJson('/api/v1/rendez-vous')
            ->assertOk()
            ->assertJsonPath('rendez_vous.0.tarif', null);
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // D6 — orientation libre à la réservation
    // ─────────────────────────────────────────────────────────────────────────────

    public function test_l_orientation_libre_est_persistee(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $structure = $this->structure();
        $service = $this->service($structure);
        $membre = $this->membreDe($user);

        $this->postJson('/api/v1/rendez-vous', [
            'membre_id' => $membre->id, 'structure_id' => $structure->id, 'service_id' => $service->id,
            'motif' => 'Suivi', 'date_souhaitee' => Carbon::tomorrow()->toDateString(),
            'motif_orientation' => 'Médecin traitant', 'message_orientation' => 'Douleur persistante depuis 3 jours.',
        ])
            ->assertCreated()
            ->assertJsonPath('rendez_vous.motif_orientation', 'Médecin traitant');

        $this->assertDatabaseHas('rendez_vous', [
            'membre_id' => $membre->id, 'motif_orientation' => 'Médecin traitant',
        ]);
    }

    public function test_l_orientation_libre_est_facultative(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $structure = $this->structure();
        $service = $this->service($structure);
        $membre = $this->membreDe($user);

        $this->postJson('/api/v1/rendez-vous', [
            'membre_id' => $membre->id, 'structure_id' => $structure->id, 'service_id' => $service->id,
            'motif' => 'Suivi', 'date_souhaitee' => Carbon::tomorrow()->toDateString(),
        ])->assertCreated()->assertJsonPath('rendez_vous.motif_orientation', null);
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // D5 — numéro professionnel + photo, exposés au patient pour la première fois
    // ─────────────────────────────────────────────────────────────────────────────

    public function test_la_liste_expose_le_numero_professionnel_du_medecin(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $structure = $this->structure();
        $service = $this->service($structure);
        $medecin = Medecin::create([
            'structure_id' => $structure->id, 'service_id' => $service->id,
            'titre' => 'Dr', 'nom' => 'Koffi', 'prenom' => 'Serge', 'specialite' => 'Cardiologie', 'actif' => true,
        ]);
        // `numero_professionnel` est délibérément HORS `$fillable` (P6.5a : un client ne choisit
        // pas son numéro national) — l'assignation directe le contourne, comme le ferait
        // `AttributeurNumeroProfessionnel` en production.
        $medecin->numero_professionnel = 'CI-PRO000042';
        $medecin->save();
        $membre = $this->membreDe($user);
        $rdv = $this->rdv($membre, $service);
        $rdv->update(['medecin_id' => $medecin->id]);

        $this->getJson('/api/v1/rendez-vous')
            ->assertOk()
            ->assertJsonPath('rendez_vous.0.medecin.numero_professionnel', 'CI-PRO000042');
    }
}
