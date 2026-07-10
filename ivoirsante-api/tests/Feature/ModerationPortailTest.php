<?php

namespace Tests\Feature;

use App\Models\Avis;
use App\Models\Signalement;
use App\Models\StructureSanitaire;
use App\Models\User;
use Database\Seeders\PortailRolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Module 4 / 4.6 — Modération des avis et signalements, réservée à l'admin IVOIRSANTÉ.
 *
 * Points sensibles couverts : recalcul de la note dénormalisée au masquage, séparation
 * validation / publication, traçabilité des décisions, anonymat du signalant.
 */
class ModerationPortailTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PortailRolesSeeder::class);
    }

    private function admin(): User
    {
        $u = User::factory()->create(['structure_id' => null]);
        $u->assignRole('admin_ivoirsante');

        return $u;
    }

    private function structure(): StructureSanitaire
    {
        return StructureSanitaire::create([
            'nom' => 'CHU Test', 'type' => 'chu', 'adresse' => 'Abidjan', 'commune' => 'Cocody',
            'latitude' => 5.35, 'longitude' => -3.98, 'actif' => true,
        ]);
    }

    private function avis(StructureSanitaire $s, int $note, bool $visible = true): Avis
    {
        return Avis::create([
            'structure_id' => $s->id, 'user_id' => User::factory()->create()->id,
            'note' => $note, 'commentaire' => 'Commentaire de test', 'visible' => $visible,
        ]);
    }

    private function signalement(StructureSanitaire $s, string $statut = 'en_attente'): Signalement
    {
        return Signalement::create([
            'type' => 'pot_de_vin', 'structure_id' => $s->id, 'user_id' => User::factory()->create()->id,
            'description' => 'Demande de 5000 FCFA à l\'accueil.', 'statut' => $statut,
        ]);
    }

    // ---- Accès --------------------------------------------------------------

    public function test_seul_l_admin_accede_a_la_moderation(): void
    {
        $structure = $this->structure();

        // Le gestionnaire ne modère pas, même les avis de son propre établissement.
        $gestionnaire = User::factory()->create(['structure_id' => $structure->id]);
        $gestionnaire->assignRole('gestionnaire_etablissement');
        $this->actingAs($gestionnaire)->get(route('portail.moderation.index'))->assertForbidden();

        $agent = User::factory()->create(['structure_id' => $structure->id]);
        $agent->assignRole('agent_garde');
        $this->actingAs($agent)->get(route('portail.moderation.index'))->assertForbidden();

        $this->actingAs($this->admin())->get(route('portail.moderation.index'))->assertOk();
    }

    // ---- Avis ---------------------------------------------------------------

    public function test_masquer_un_avis_recalcule_la_note_de_la_structure(): void
    {
        $structure = $this->structure();
        $this->avis($structure, 5);
        $mauvais = $this->avis($structure, 1);

        app(\App\Services\NoteStructureService::class)->recalculer($structure);
        $this->assertEqualsWithDelta(3.0, (float) $structure->fresh()->note_moyenne, 0.01);

        $this->actingAs($this->admin())
            ->patch(route('portail.moderation.avis', $mauvais), ['motif' => 'Propos injurieux'])
            ->assertRedirect();

        // L'avis masqué sort de la moyenne : 5 seul demeure visible.
        $structure->refresh();
        $this->assertEqualsWithDelta(5.0, (float) $structure->note_moyenne, 0.01);
        $this->assertSame(1, $structure->nb_avis);

        // La décision est tracée et l'avis n'est PAS supprimé (modération réversible).
        $mauvais->refresh();
        $this->assertFalse($mauvais->visible);
        $this->assertFalse($mauvais->signale);
        $this->assertSame('Propos injurieux', $mauvais->motif_moderation);
        $this->assertNotNull($mauvais->modere_at);
        $this->assertDatabaseCount('avis', 2);
    }

    public function test_masquer_un_avis_exige_un_motif_mais_pas_le_retablir(): void
    {
        $structure = $this->structure();
        $visible = $this->avis($structure, 2);
        $admin = $this->admin();

        // Masquer sans motif : refusé.
        $this->actingAs($admin)
            ->patch(route('portail.moderation.avis', $visible), [])
            ->assertSessionHasErrors('motif');
        $this->assertTrue($visible->fresh()->visible);

        // Rétablir sans motif : accepté (décision favorable à l'auteur).
        $masque = $this->avis($structure, 4, visible: false);
        $this->actingAs($admin)->patch(route('portail.moderation.avis', $masque), [])->assertRedirect();
        $this->assertTrue($masque->fresh()->visible);

        // Les deux avis sont désormais visibles et comptés (celui du début n'a pas été masqué).
        $this->assertSame(2, $structure->fresh()->nb_avis);
    }

    // ---- Signalements -------------------------------------------------------

    public function test_valider_un_signalement_ne_le_publie_pas(): void
    {
        $signalement = $this->signalement($this->structure());

        $this->actingAs($this->admin())
            ->patch(route('portail.moderation.trancher', $signalement), ['decision' => 'valide'])
            ->assertRedirect();

        $signalement->refresh();
        $this->assertSame('valide', $signalement->statut);
        $this->assertFalse($signalement->visible_publiquement);   // publication = décision séparée
        $this->assertNotNull($signalement->modere_par_user_id);
    }

    public function test_la_publication_est_une_bascule_reservee_aux_signalements_valides(): void
    {
        $structure = $this->structure();
        $admin = $this->admin();

        // Un signalement en attente ne peut pas être publié.
        $enAttente = $this->signalement($structure);
        $this->actingAs($admin)
            ->patch(route('portail.moderation.publication', $enAttente))
            ->assertSessionHasErrors('publication');
        $this->assertFalse($enAttente->fresh()->visible_publiquement);

        // Une fois validé : publication, puis retrait.
        $valide = $this->signalement($structure, 'valide');
        $this->actingAs($admin)->patch(route('portail.moderation.publication', $valide))->assertRedirect();
        $this->assertTrue($valide->fresh()->visible_publiquement);

        $this->actingAs($admin)->patch(route('portail.moderation.publication', $valide))->assertRedirect();
        $this->assertFalse($valide->fresh()->visible_publiquement);
    }

    public function test_rejeter_exige_un_motif_et_depublie_le_signalement(): void
    {
        $structure = $this->structure();
        $admin = $this->admin();

        $sansMotif = $this->signalement($structure);
        $this->actingAs($admin)
            ->patch(route('portail.moderation.trancher', $sansMotif), ['decision' => 'rejete'])
            ->assertSessionHasErrors('motif');
        $this->assertSame('en_attente', $sansMotif->fresh()->statut);

        // Un signalement publié puis rejeté quitte l'historique public.
        $publie = $this->signalement($structure, 'valide');
        $publie->update(['visible_publiquement' => true]);

        $this->actingAs($admin)->patch(route('portail.moderation.trancher', $publie), [
            'decision' => 'rejete', 'motif' => 'Non confirmé après vérification',
        ])->assertRedirect();

        $publie->refresh();
        $this->assertSame('rejete', $publie->statut);
        $this->assertFalse($publie->visible_publiquement);
    }

    public function test_l_anonymat_du_signalant_est_preserve_dans_le_portail(): void
    {
        $signalement = $this->signalement($this->structure());
        $auteur = User::find($signalement->user_id);

        // Le modérateur voit le contenu, jamais l'identité de son auteur.
        $this->actingAs($this->admin())
            ->get(route('portail.moderation.index'))
            ->assertOk()
            ->assertSee('Demande de 5000 FCFA', escape: false)
            ->assertDontSee($auteur->email);

        // `user_id` reste masqué à la sérialisation, même pour un modérateur.
        $this->assertArrayNotHasKey('user_id', $signalement->toArray());
    }
}
