<?php

namespace Tests\Feature;

use App\Models\MembreFamille;
use App\Models\User;
use App\Services\FicheVitaleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Module 5 / 5.1 — Fiche vitale d'urgence (FN2), source unique du « vital minimal ».
 *
 * L'enjeu du périmètre : ces données seront lues sans authentification par un secouriste tenant
 * le téléphone. Tout ce qui n'est pas vital doit en être absent.
 */
class FicheVitaleTest extends TestCase
{
    use RefreshDatabase;

    private function membreComplet(User $user): MembreFamille
    {
        $membre = MembreFamille::factory()->for($user)->create([
            'nom' => 'Koné', 'prenom' => 'Awa', 'sexe' => 'F',
            'groupe_sanguin' => 'O+', 'date_naissance' => now()->subYears(30)->toDateString(),
        ]);

        // `membre_id` n'est pas `fillable` (il ne se choisit pas depuis une requête) : on crée
        // les lignes par la relation.
        $membre->antecedents()->create(['type' => 'allergie', 'description' => 'Pénicilline']);
        $membre->antecedents()->create([
            'type' => 'maladie_chronique', 'description' => 'Asthme', 'traitement_actuel' => 'Ventoline',
        ]);
        // Bruit : un antécédent chirurgical n'a rien à faire dans une fiche vitale.
        $membre->antecedents()->create(['type' => 'chirurgie', 'description' => 'Appendicectomie 2019']);

        $membre->vaccinations()->create([
            'vaccin_nom' => 'Fièvre jaune', 'obligatoire' => true,
            'statut' => 'fait', 'date_administration' => '2020-05-10',
        ]);
        $membre->vaccinations()->create(['vaccin_nom' => 'Grippe', 'obligatoire' => false, 'statut' => 'fait']);
        $membre->vaccinations()->create(['vaccin_nom' => 'Hépatite B', 'obligatoire' => true, 'statut' => 'a_faire']);

        $membre->contactsUrgence()->create([
            'nom' => 'Kouassi', 'lien_parente' => 'Papa', 'telephone' => '0701020304', 'est_principal' => true,
        ]);

        return $membre;
    }

    public function test_la_fiche_vitale_contient_le_strict_necessaire_a_l_urgence(): void
    {
        $user = User::factory()->create();
        $membre = $this->membreComplet($user);
        Sanctum::actingAs($user);

        $this->getJson("/api/v1/membres/{$membre->id}/fiche-vitale")
            ->assertOk()
            ->assertJsonPath('fiche_vitale.prenom', 'Awa')
            ->assertJsonPath('fiche_vitale.age', 30)
            ->assertJsonPath('fiche_vitale.groupe_sanguin', 'O+')
            ->assertJsonPath('fiche_vitale.allergies', ['Pénicilline'])
            ->assertJsonPath('fiche_vitale.maladies_chroniques.0.libelle', 'Asthme')
            ->assertJsonPath('fiche_vitale.maladies_chroniques.0.traitement', 'Ventoline')
            ->assertJsonPath('fiche_vitale.contacts_urgence.0.telephone', '0701020304');
    }

    public function test_la_fiche_vitale_exclut_tout_ce_qui_ne_releve_pas_de_l_urgence(): void
    {
        $user = User::factory()->create();
        $membre = $this->membreComplet($user);
        $membre->notesObservations()->create(['contenu' => 'Note confidentielle', 'auteur_type' => 'patient']);
        Sanctum::actingAs($user);

        $reponse = $this->getJson("/api/v1/membres/{$membre->id}/fiche-vitale")->assertOk();

        // Antécédent chirurgical, vaccin facultatif, vaccin non fait : hors périmètre vital.
        $reponse->assertJsonMissing(['Appendicectomie 2019'])
            ->assertJsonMissing(['Grippe'])
            ->assertJsonMissing(['Hépatite B'])
            ->assertJsonMissing(['Note confidentielle']);

        // Ni matricule interne, ni numéro CMU : une fiche vitale sert à soigner, pas à identifier.
        $fiche = $reponse->json('fiche_vitale');
        $this->assertArrayNotHasKey('matricule_ivs', $fiche);
        $this->assertArrayNotHasKey('cmu_numero', $fiche);

        // Une seule vaccination retenue : l'obligatoire effectivement faite.
        $this->assertCount(1, $fiche['vaccinations_essentielles']);
        $this->assertSame('Fièvre jaune', $fiche['vaccinations_essentielles'][0]['vaccin']);
    }

    public function test_la_fiche_vitale_d_un_membre_d_autrui_est_refusee(): void
    {
        $membre = $this->membreComplet(User::factory()->create());
        Sanctum::actingAs(User::factory()->create());

        $this->getJson("/api/v1/membres/{$membre->id}/fiche-vitale")->assertForbidden();
    }

    public function test_le_resume_sms_tient_en_une_ligne_lisible(): void
    {
        $membre = $this->membreComplet(User::factory()->create());

        $resume = app(FicheVitaleService::class)->resume($membre);

        $this->assertStringContainsString('Awa Koné', $resume);
        $this->assertStringContainsString('O+', $resume);
        $this->assertStringContainsString('Allergies: Pénicilline', $resume);
        $this->assertStringContainsString('Chroniques: Asthme', $resume);
        $this->assertStringNotContainsString("\n", $resume);
    }

    public function test_un_membre_sans_donnee_vitale_renvoie_une_fiche_vide_sans_erreur(): void
    {
        $user = User::factory()->create();
        // Cas réel : un membre tout juste créé, dont le carnet n'est pas encore rempli.
        $membre = MembreFamille::factory()->for($user)->create(['groupe_sanguin' => null]);
        Sanctum::actingAs($user);

        $this->getJson("/api/v1/membres/{$membre->id}/fiche-vitale")
            ->assertOk()
            ->assertJsonPath('fiche_vitale.groupe_sanguin', null)
            ->assertJsonPath('fiche_vitale.allergies', [])
            ->assertJsonPath('fiche_vitale.maladies_chroniques', [])
            ->assertJsonPath('fiche_vitale.vaccinations_essentielles', [])
            ->assertJsonPath('fiche_vitale.contacts_urgence', []);
    }
}
