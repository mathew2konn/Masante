<?php

namespace Tests\Feature;

use App\Http\Requests\StoreMembreRequest;
use App\Models\MembreFamille;
use App\Models\User;
use App\Services\Nis\CalculateurNis;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * P6.1 — Dossier de santé du titulaire (ADR-021 §2.1, variante (c)).
 *
 * Couvre le chemin que l'écran mobile de complétion consomme : état, création, NIS attribué,
 * identité reprise du compte, hors quota, et non-régression de l'inscription (P1 validé G5).
 */
class DossierTitulaireTest extends TestCase
{
    use RefreshDatabase;

    private const PAYLOAD = ['date_naissance' => '1990-05-12', 'sexe' => 'M'];

    #[Test]
    public function un_compte_neuf_n_a_pas_de_dossier_titulaire(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->getJson('/api/v1/membres/titulaire')
            ->assertOk()
            ->assertJsonPath('existe', false)
            ->assertJsonPath('membre', null);

        // Le backend fait autorité : /me le dit aussi, le mobile ne le déduit pas.
        $this->actingAs($user)
            ->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('user.a_dossier_titulaire', false);
    }

    #[Test]
    public function la_completion_cree_le_dossier_et_attribue_le_nis(): void
    {
        $user = User::factory()->create(['nom' => 'Kouassi', 'prenom' => 'Jean']);

        $membre = $this->actingAs($user)
            ->postJson('/api/v1/membres/titulaire', self::PAYLOAD)
            ->assertCreated()
            ->json('membre');

        $this->assertTrue($membre['est_titulaire']);
        $this->assertNotNull($membre['nis']);
        $this->assertTrue(app(CalculateurNis::class)->estValide($membre['nis']));
        $this->assertSame('CI', $membre['pays_code']);

        // Le matricule interne ne fuite pas, même sur ce chemin.
        $this->assertArrayNotHasKey('matricule_ivs', $membre);

        $this->assertDatabaseHas('nis_journal', ['nis' => $membre['nis'], 'acteur_id' => $user->id]);
    }

    #[Test]
    public function l_identite_provient_du_compte_et_non_du_client(): void
    {
        $user = User::factory()->create(['nom' => 'Kouassi', 'prenom' => 'Jean']);

        // Le client tente d'imposer une autre identité : elle doit être ignorée, sinon on
        // fabriquerait un dossier de santé sous un nom différent de celui du compte.
        $membre = $this->actingAs($user)
            ->postJson('/api/v1/membres/titulaire', [
                ...self::PAYLOAD,
                'nom' => 'Usurpateur', 'prenom' => 'Faux',
            ])
            ->assertCreated()
            ->json('membre');

        $this->assertSame('Kouassi', $membre['nom']);
        $this->assertSame('Jean', $membre['prenom']);
    }

    #[Test]
    public function une_seconde_completion_est_refusee_proprement(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/api/v1/membres/titulaire', self::PAYLOAD)->assertCreated();

        // 409 lisible plutôt qu'une violation de contrainte remontée brute.
        $this->actingAs($user)
            ->postJson('/api/v1/membres/titulaire', self::PAYLOAD)
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'DOSSIER_TITULAIRE_EXISTANT');

        $this->assertSame(1, MembreFamille::where('user_id', $user->id)->count());
    }

    #[Test]
    public function la_date_de_naissance_future_est_refusee(): void
    {
        $this->actingAs(User::factory()->create())
            ->postJson('/api/v1/membres/titulaire', ['date_naissance' => '2099-01-01', 'sexe' => 'M'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('date_naissance');
    }

    #[Test]
    public function le_dossier_titulaire_ne_consomme_pas_le_quota_de_membres(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/api/v1/membres/titulaire', self::PAYLOAD)->assertCreated();

        // On remplit le quota complet APRÈS avoir créé le dossier titulaire.
        for ($i = 0; $i < StoreMembreRequest::MAX_MEMBRES; $i++) {
            $this->actingAs($user)
                ->postJson('/api/v1/membres', [
                    'nom' => "M{$i}", 'prenom' => 'X',
                    'date_naissance' => '2000-01-01', 'sexe' => 'F',
                ])
                ->assertCreated();
        }

        // Le quota est atteint par les 15 membres ajoutés, le titulaire n'y entrant pas.
        $this->assertSame(
            StoreMembreRequest::MAX_MEMBRES + 1,
            MembreFamille::where('user_id', $user->id)->count()
        );

        $this->actingAs($user)
            ->postJson('/api/v1/membres', [
                'nom' => 'DeTrop', 'prenom' => 'X',
                'date_naissance' => '2000-01-01', 'sexe' => 'F',
            ])
            ->assertStatus(422);
    }

    #[Test]
    public function l_inscription_reste_inchangee(): void
    {
        // Non-régression P1 (validé G5) : la variante (c) ne touche pas au tunnel d'inscription.
        // `register` assigne le rôle `patient` : il doit exister (même préalable qu'AuthRoleTest).
        $this->seed(\Database\Seeders\RoleSeeder::class);

        $this->postJson('/api/v1/auth/register', [
            'telephone' => '+2250700000042',
            'nom' => 'Test', 'prenom' => 'Nouveau',
            'password' => 'Motdepasse@2026', 'password_confirmation' => 'Motdepasse@2026',
        ])->assertCreated();

        $user = User::where('telephone', '+2250700000042')->firstOrFail();

        // Aucun dossier n'est créé à l'inscription : c'est tout l'objet de la variante (c).
        $this->assertSame(0, MembreFamille::where('user_id', $user->id)->count());
    }

    #[Test]
    public function le_dossier_titulaire_reste_isole_entre_comptes(): void
    {
        $a = User::factory()->create();
        $b = User::factory()->create();

        $this->actingAs($a)->postJson('/api/v1/membres/titulaire', self::PAYLOAD)->assertCreated();

        // Le compte B n'a toujours pas de dossier : la réponse est par compte, pas globale.
        $this->actingAs($b)
            ->getJson('/api/v1/membres/titulaire')
            ->assertOk()
            ->assertJsonPath('existe', false);
    }
}
