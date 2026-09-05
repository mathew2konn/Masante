<?php

namespace Tests\Feature;

use App\Models\Antecedent;
use App\Models\MembreFamille;
use App\Models\Ordonnance;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Étape 2A.4 — Sections du carnet. On vérifie le chiffrement au repos (§6), l'isolation
 * anti-IDOR (§4.3) héritée du membre parent, et le scoping des éléments enfants.
 */
class CarnetSectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_antecedent_cree_chiffre_et_liste(): void
    {
        $user = User::factory()->create();
        $membre = MembreFamille::factory()->for($user)->create();
        Sanctum::actingAs($user);

        $this->postJson("/api/v1/membres/{$membre->id}/antecedents", [
            'type'          => 'maladie_chronique',
            'description'   => 'Asthme persistant modéré',
            'impact_triage' => 12,
        ])->assertCreated()->assertJsonPath('item.type', 'maladie_chronique');

        $antecedent = Antecedent::first();
        // Chiffré au repos : la valeur brute en base n'est pas le clair, mais se déchiffre.
        $this->assertNotSame('Asthme persistant modéré', $antecedent->getRawOriginal('description'));
        $this->assertSame('Asthme persistant modéré', $antecedent->description);

        $this->getJson("/api/v1/membres/{$membre->id}/antecedents")
            ->assertOk()->assertJsonCount(1, 'items');
    }

    public function test_ordonnance_medicaments_json_chiffre_et_round_trip(): void
    {
        $user = User::factory()->create();
        $membre = MembreFamille::factory()->for($user)->create();
        Sanctum::actingAs($user);

        $this->postJson("/api/v1/membres/{$membre->id}/ordonnances", [
            'medecin_nom'         => 'Dr Aka',
            'structure_sanitaire' => 'CHU de Cocody',
            'date_prescription'   => '2026-06-10',
            'medicaments_json'    => [['nom' => 'Paracétamol', 'dosage' => '500mg']],
        ])->assertCreated();

        $ordo = Ordonnance::first();
        $this->assertStringNotContainsString('Paracétamol', (string) $ordo->getRawOriginal('medicaments_json'));
        $this->assertSame('Paracétamol', $ordo->medicaments_json[0]['nom']);
    }

    public function test_isolation_idor_sur_une_section(): void
    {
        $membre = MembreFamille::factory()->for(User::factory())->create();
        Sanctum::actingAs(User::factory()->create());

        $this->getJson("/api/v1/membres/{$membre->id}/antecedents")->assertForbidden();
        $this->postJson("/api/v1/membres/{$membre->id}/antecedents", [
            'type' => 'allergie', 'description' => 'Pénicilline',
        ])->assertForbidden();
        $this->assertDatabaseCount('antecedents', 0);
    }

    public function test_un_element_d_un_autre_membre_est_introuvable(): void
    {
        $user = User::factory()->create();
        $membre1 = MembreFamille::factory()->for($user)->create();
        $membre2 = MembreFamille::factory()->for($user)->create();
        $antecedent = $membre1->antecedents()->create([
            'type' => 'autre', 'description' => 'X',
        ]);
        Sanctum::actingAs($user);

        // L'antécédent appartient à membre1 : on ne peut pas l'atteindre via membre2 (scoping).
        $this->getJson("/api/v1/membres/{$membre2->id}/antecedents/{$antecedent->id}")->assertNotFound();
        $this->putJson("/api/v1/membres/{$membre2->id}/antecedents/{$antecedent->id}", ['description' => 'Y'])
            ->assertNotFound();
    }

    /**
     * RÉÉCRIT EN P6.8b — ce vecteur affirmait le comportement que l'incrément a retiré.
     *
     * Il vérifiait qu'un `PUT { statut: 'en_retard' }` faisait passer une vaccination administrée à
     * « en retard ». C'était vrai, et c'était le défaut : le statut était DÉCLARÉ par le client,
     * puis figé au jour de la saisie. Il est désormais CALCULÉ (frontière CDC_01 §0.1 — un état
     * métier est fourni par le backend, jamais déclaré).
     *
     * Le vecteur n'est donc pas corrigé pour passer : il est réécrit pour dire la garantie NEUVE
     * — une dose administrée reste `fait`, quoi que le client envoie (précédent P6.4d).
     */
    public function test_vaccination_crud_basique_et_le_statut_ne_se_declare_plus(): void
    {
        $user = User::factory()->create();
        $membre = MembreFamille::factory()->for($user)->create();
        Sanctum::actingAs($user);

        $id = $this->postJson("/api/v1/membres/{$membre->id}/vaccinations", [
            'vaccin_nom' => 'BCG', 'statut' => 'a_faire', 'date_administration' => '2015-05-01',
        ])->assertCreated()->assertJsonPath('item.statut', 'fait')->json('item.id');

        // Le client insiste à la modification : la dose reste administrée, donc faite.
        $this->putJson("/api/v1/membres/{$membre->id}/vaccinations/{$id}", ['statut' => 'en_retard'])
            ->assertOk()->assertJsonPath('item.statut', 'fait');

        $this->deleteJson("/api/v1/membres/{$membre->id}/vaccinations/{$id}")->assertOk();
        $this->assertDatabaseCount('vaccinations', 0);
    }

    public function test_les_sections_exigent_une_authentification(): void
    {
        $membre = MembreFamille::factory()->for(User::factory())->create();
        $this->getJson("/api/v1/membres/{$membre->id}/rappels")->assertUnauthorized();
    }

    // --- F2.13 : traçabilité de la provenance (`source`) ---

    public function test_f2_13_source_par_defaut_patient_et_exposee_en_lecture(): void
    {
        $user = User::factory()->create();
        $membre = MembreFamille::factory()->for($user)->create();
        Sanctum::actingAs($user);

        // Sans `source` fournie : défaut BDD 'patient', exposé dès la création puis en liste.
        $this->postJson("/api/v1/membres/{$membre->id}/antecedents", [
            'type' => 'allergie', 'description' => 'Arachide',
        ])->assertCreated()->assertJsonPath('item.source', 'patient');

        $this->getJson("/api/v1/membres/{$membre->id}/antecedents")
            ->assertOk()->assertJsonPath('items.0.source', 'patient');
    }

    /**
     * B5-a (L4/K5/K11) — RÉÉCRIT, PAS SEULEMENT CORRIGÉ. Ce test affirmait qu'un client pouvait
     * poser lui-même `source: 'structure'`/`'medecin'` sur sa propre saisie directe — c'était
     * exactement le défaut que K11 a trouvé ACTIF : `ServiceFicheParcours::autresEntrees()` lit
     * cette valeur comme « un fait de professionnel ». La garantie neuve est l'inverse : `source`
     * envoyée par le client est TOUJOURS ignorée sur ces trois sections, quelle que soit sa
     * valeur — la colonne garde son défaut `patient`.
     */
    public function test_f2_13_source_du_client_est_toujours_ignoree(): void
    {
        $user = User::factory()->create();
        $membre = MembreFamille::factory()->for($user)->create();
        Sanctum::actingAs($user);

        $this->postJson("/api/v1/membres/{$membre->id}/antecedents", [
            'type' => 'maladie_chronique', 'description' => 'Diabète type 2', 'source' => 'structure',
        ])->assertCreated()->assertJsonPath('item.source', 'patient');

        $this->postJson("/api/v1/membres/{$membre->id}/ordonnances", [
            'medecin_nom' => 'Dr Aka', 'structure_sanitaire' => 'CHU de Cocody',
            'date_prescription' => '2026-06-10', 'medicaments_json' => [['nom' => 'Metformine']],
            'source' => 'medecin',
        ])->assertCreated()->assertJsonPath('item.source', 'patient');

        // Une valeur HORS de l'ENUM lui-même n'est plus refusée en 422 : elle n'atteint même plus
        // la validation, elle est simplement écartée avant d'y arriver — l'entrée se crée quand
        // même, avec la valeur par défaut.
        $this->postJson("/api/v1/membres/{$membre->id}/resultats-analyses", [
            'type_analyse' => 'biologique', 'intitule' => 'NFS',
            'date_analyse' => '2026-06-01', 'source' => 'hopital',
        ])->assertCreated()->assertJsonPath('item.source', 'patient');

        $this->assertDatabaseHas('antecedents', ['membre_id' => $membre->id, 'source' => 'patient']);
        $this->assertDatabaseHas('ordonnances', ['membre_id' => $membre->id, 'source' => 'patient']);
        $this->assertDatabaseHas('resultats_analyses', ['membre_id' => $membre->id, 'source' => 'patient']);
    }
}
