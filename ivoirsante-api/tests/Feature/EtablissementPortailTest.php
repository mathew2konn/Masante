<?php

namespace Tests\Feature;

use App\Models\ActivationPortail;
use App\Models\StructureSanitaire;
use App\Models\User;
use Database\Seeders\PortailRolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Module 4 / 4.2 — Établissements (admin) + création du gestionnaire + flux d'activation (CdC §5.4.1/2).
 */
class EtablissementPortailTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PortailRolesSeeder::class);
    }

    private function admin(): User
    {
        return User::where('email', 'admin@masante.ci')->first();
    }

    /** Données valides d'un établissement + son gestionnaire. */
    private function payload(array $override = []): array
    {
        return array_merge([
            'nom'                 => 'CHU de Cocody',
            'type'                => 'chu',
            'adresse'             => 'Boulevard de l\'Université',
            'commune'             => 'Cocody',
            'latitude'            => 5.3599,
            'longitude'           => -3.9877,
            'specialites'         => 'Cardiologie, ORL',
            'partenaire_ivoirsante' => '1',
            'gestionnaire_prenom' => 'Awa',
            'gestionnaire_nom'    => 'Koné',
            'gestionnaire_email'  => 'awa.kone@chu-cocody.ci',
        ], $override);
    }

    public function test_admin_voit_la_liste_des_etablissements(): void
    {
        StructureSanitaire::create($this->structure());

        $this->actingAs($this->admin())->get('/portail/etablissements')
            ->assertOk()
            ->assertSee('CHU de Cocody');
    }

    public function test_un_gestionnaire_ne_peut_pas_acceder_a_la_gestion_des_etablissements(): void
    {
        $gestionnaire = User::factory()->create(['password' => Hash::make('Gestion@2026!')]);
        $gestionnaire->assignRole('gestionnaire_etablissement');

        $this->actingAs($gestionnaire)->get('/portail/etablissements')->assertForbidden();
    }

    public function test_admin_cree_un_etablissement_et_son_gestionnaire_sans_mot_de_passe(): void
    {
        $this->actingAs($this->admin())
            ->post('/portail/etablissements', $this->payload())
            ->assertRedirect(route('portail.etablissements.index'))
            ->assertSessionHas('lien_activation');

        $structure = StructureSanitaire::where('nom', 'CHU de Cocody')->first();
        $this->assertNotNull($structure);
        $this->assertEquals(['Cardiologie', 'ORL'], $structure->specialites_json);

        $gestionnaire = User::where('email', 'awa.kone@chu-cocody.ci')->first();
        $this->assertNotNull($gestionnaire);
        $this->assertNull($gestionnaire->password);            // aucun mot de passe temporaire
        $this->assertEquals($structure->id, $gestionnaire->structure_id);
        $this->assertTrue($gestionnaire->hasRole('gestionnaire_etablissement'));
        $this->assertDatabaseHas('activations_portail', ['user_id' => $gestionnaire->id, 'used_at' => null]);
    }

    public function test_le_gestionnaire_active_son_compte_et_peut_se_connecter(): void
    {
        $this->actingAs($this->admin())->post('/portail/etablissements', $this->payload());
        $this->flushSession();

        $gestionnaire = User::where('email', 'awa.kone@chu-cocody.ci')->first();
        $token = $this->dernierTokenClair($gestionnaire);

        // Le lien affiche le formulaire.
        $this->get(route('portail.activation.show', ['token' => $token]))->assertOk()->assertSee('Awa');

        // Pose du mot de passe → compte activé.
        $this->post(route('portail.activation.attempt', ['token' => $token]), [
            'password' => 'Gestion@2026!',
            'password_confirmation' => 'Gestion@2026!',
        ])->assertRedirect(route('portail.login'));

        $gestionnaire->refresh();
        $this->assertNotNull($gestionnaire->password);
        $this->assertDatabaseMissing('activations_portail', ['user_id' => $gestionnaire->id, 'used_at' => null]);

        // Connexion possible.
        $this->post('/portail/login', ['email' => $gestionnaire->email, 'password' => 'Gestion@2026!'])
            ->assertRedirect(route('portail.dashboard'));
        $this->assertAuthenticated();
    }

    public function test_activation_ferme_la_session_en_cours(): void
    {
        // L'admin crée l'établissement puis ouvre le lien d'activation SANS se déconnecter (même
        // navigateur). Après activation, la session doit être vidée pour ne pas rester sur le compte
        // en cours (sinon la page de connexion redirige vers son dashboard).
        $this->actingAs($this->admin())->post('/portail/etablissements', $this->payload());

        $gestionnaire = User::where('email', 'awa.kone@chu-cocody.ci')->first();
        $token = $this->dernierTokenClair($gestionnaire);

        $this->actingAs($this->admin())
            ->post(route('portail.activation.attempt', ['token' => $token]), [
                'password' => 'Gestion@2026!', 'password_confirmation' => 'Gestion@2026!',
            ])
            ->assertRedirect(route('portail.login'));

        $this->assertGuest();
    }

    public function test_un_jeton_deja_utilise_est_refuse(): void
    {
        $this->actingAs($this->admin())->post('/portail/etablissements', $this->payload());
        $this->flushSession();

        $gestionnaire = User::where('email', 'awa.kone@chu-cocody.ci')->first();
        $token = $this->dernierTokenClair($gestionnaire);

        // Première consommation OK.
        $this->post(route('portail.activation.attempt', ['token' => $token]), [
            'password' => 'Gestion@2026!', 'password_confirmation' => 'Gestion@2026!',
        ]);

        // Rejeu du même jeton → refusé (usage unique).
        $this->post(route('portail.activation.attempt', ['token' => $token]), [
            'password' => 'Autre@2026!', 'password_confirmation' => 'Autre@2026!',
        ])->assertSessionHasErrors('token');
    }

    public function test_desactiver_un_etablissement_suspend_son_gestionnaire(): void
    {
        $this->actingAs($this->admin())->post('/portail/etablissements', $this->payload());
        $structure = StructureSanitaire::where('nom', 'CHU de Cocody')->first();
        $gestionnaire = $structure->staff()->first();

        $this->actingAs($this->admin())
            ->patch(route('portail.etablissements.toggle', $structure))
            ->assertRedirect(route('portail.etablissements.index'));

        $structure->refresh();
        $gestionnaire->refresh();
        $this->assertFalse($structure->actif);
        $this->assertFalse($gestionnaire->actif);
    }

    public function test_un_compte_desactive_ne_peut_pas_se_connecter(): void
    {
        $gestionnaire = User::factory()->create(['password' => Hash::make('Gestion@2026!'), 'actif' => false]);
        $gestionnaire->assignRole('gestionnaire_etablissement');

        $this->post('/portail/login', ['email' => $gestionnaire->email, 'password' => 'Gestion@2026!'])
            ->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    /** Structure brute (sans passer par le contrôleur). */
    private function structure(array $override = []): array
    {
        return array_merge([
            'nom' => 'CHU de Cocody', 'type' => 'chu', 'adresse' => 'Bd Université',
            'commune' => 'Cocody', 'latitude' => 5.3599, 'longitude' => -3.9877, 'actif' => true,
        ], $override);
    }

    /**
     * Régénère un jeton en clair connu pour un utilisateur : on remplace le hash stocké par celui
     * d'un jeton maîtrisé par le test (le clair n'est jamais persité par l'application).
     */
    private function dernierTokenClair(User $user): string
    {
        $token = 'jeton-test-' . $user->id;
        ActivationPortail::where('user_id', $user->id)->whereNull('used_at')
            ->update(['token_hash' => hash('sha256', $token)]);

        return $token;
    }
}
