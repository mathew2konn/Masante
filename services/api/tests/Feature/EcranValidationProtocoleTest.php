<?php

namespace Tests\Feature;

use App\Models\Protocole;
use App\Models\ProtocoleVersion;
use App\Models\User;
use App\Services\Protocole\ServiceGouvernanceProtocole;
use App\Services\Triage\ServicePlafondAntecedents;
use Database\Seeders\PortailRolesSeeder;
use Database\Seeders\ProtocoleSeeder;
use Database\Seeders\SpecialiteMedicaleSeeder;
use Database\Seeders\SymptomeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * P10b-3-ii — L'écran §7 : lire et signer, jamais éditer (CDC_08 §7, §10).
 *
 * Chaque refus est vérifié PAR SON MOTIF.
 */
class EcranValidationProtocoleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(SpecialiteMedicaleSeeder::class);
        $this->seed(SymptomeSeeder::class);
        $this->seed(PortailRolesSeeder::class);
        $this->seed(ProtocoleSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function test_un_role_metier_sans_permission_n_entre_pas(): void
    {
        // Un rôle réel, jamais `admin_ivoirsante` qui reçoit tout — un vecteur bâti sur lui aurait
        // été vert quoi qu'il arrive (leçon P6.6a).
        $agent = User::factory()->create();
        $agent->assignRole('gestionnaire_etablissement');

        $this->actingAs($agent->fresh(), 'web')
            ->get(route('portail.protocoles.index'))
            ->assertForbidden();
    }

    public function test_une_permission_de_validation_ouvre_l_ecran(): void
    {
        $this->actingAs($this->relecteur('protocole.valider.clinique'), 'web')
            ->get(route('portail.protocoles.index'))
            ->assertOk()
            ->assertSee('TRIAGE-ANTECEDENTS');
    }

    public function test_l_ecran_rend_les_regles_en_francais_et_n_offre_aucune_edition(): void
    {
        [$protocole, $version] = $this->brouillonDeLaBorne();

        $reponse = $this->actingAs($this->relecteur('protocole.valider.clinique'), 'web')
            ->get(route('portail.protocoles.show', [$protocole, $version]))
            ->assertOk();

        // La règle est lisible : le libellé de l'action vient de la liste blanche, pas du JSON.
        $reponse->assertSee('Borner la part des antécédents à', false);
        $reponse->assertSee('(toujours)', false);

        // ═══ AUCUN CHEMIN D'ÉDITION (décision Q2) ═══
        $html = $reponse->getContent();
        $this->assertStringNotContainsString('protocoles.modifier', $html);
        $this->assertStringNotContainsString('name="libelle"', $html, 'aucun champ de règle éditable');
    }

    public function test_une_validation_caduque_est_rendue_comme_caduque(): void
    {
        // C'est l'information dont un signataire a le plus besoin : le texte a bougé depuis qu'un
        // confrère l'a relu. La garantie d'anti-substitution de P10b-1 ne vaut à l'écran que si
        // elle SE VOIT.
        [$protocole, $version] = $this->brouillonDeLaBorne();

        $relecteur = $this->relecteur(...array_values(ServiceGouvernanceProtocole::PERMISSIONS_VALIDATION));

        app(ServiceGouvernanceProtocole::class)
            ->valider($version, $relecteur, 'clinique', 'favorable', 'Médecin urgentiste');

        $this->actingAs($relecteur, 'web')
            ->get(route('portail.protocoles.show', [$protocole, $version]))
            ->assertOk()
            ->assertDontSee('caduque');

        // Le contenu change APRÈS la signature.
        DB::table('protocole_regles')->where('version_id', $version->id)
            ->update(['libelle' => 'Libellé modifié après relecture']);

        $this->actingAs($relecteur, 'web')
            ->get(route('portail.protocoles.show', [$protocole, $version]))
            ->assertOk()
            ->assertSee('caduque')
            ->assertSee('ne vaut plus pour le texte', false);
    }

    public function test_signer_depuis_l_ecran_nomme_le_validateur_et_son_role(): void
    {
        [$protocole, $version] = $this->brouillonDeLaBorne();

        $relecteur = $this->relecteur('protocole.valider.clinique');

        $this->actingAs($relecteur, 'web')
            ->post(route('portail.protocoles.valider', [$protocole, $version]), [
                'type' => 'clinique',
                'avis' => 'favorable',
                'role' => 'Médecin urgentiste, CHU de Cocody',
                'commentaires' => 'Borne cohérente avec la pratique.',
            ])
            ->assertRedirect();

        $validation = $version->validations()->where('type', 'clinique')->firstOrFail();

        $this->assertSame('Médecin urgentiste, CHU de Cocody', $validation->validateur_role);
        $this->assertSame($relecteur->nomLisible(), $validation->validateur_nom);
    }

    public function test_un_relecteur_ne_peut_pas_signer_un_type_qu_il_n_est_pas_habilite_a_signer(): void
    {
        // La garde du groupe de routes accepte l'une quelconque des cinq permissions. Celle qui
        // FAIT AUTORITÉ est celle du service, qui exige la permission EXACTE du type signé — sans
        // quoi un relecteur clinique apposerait la signature technique.
        [$protocole, $version] = $this->brouillonDeLaBorne();

        $this->actingAs($this->relecteur('protocole.valider.clinique'), 'web')
            ->post(route('portail.protocoles.valider', [$protocole, $version]), [
                'type' => 'technique',
                'avis' => 'favorable',
                'role' => 'Médecin urgentiste',
            ])
            ->assertRedirect();

        $this->assertSame(0, $version->validations()->where('type', 'technique')->count());
    }

    public function test_publier_sans_les_quatre_validations_est_refuse_par_son_motif(): void
    {
        [$protocole, $version] = $this->brouillonDeLaBorne();

        $reponse = $this->actingAs($this->relecteur('protocole.publier'), 'web')
            ->post(route('portail.protocoles.publier', [$protocole, $version]));

        $reponse->assertRedirect();
        $this->assertNotNull(session('erreur'));
        $this->assertStringContainsString('technique', strtolower(session('erreur')),
            'le refus doit NOMMER la validation qui manque');

        $this->assertSame(ProtocoleVersion::BROUILLON, $version->fresh()->etat);
    }

    // ═════════════════════════════════════════════════════════════════════════════════════
    // Fixtures
    // ═════════════════════════════════════════════════════════════════════════════════════

    /** @return array{0: Protocole, 1: ProtocoleVersion} */
    private function brouillonDeLaBorne(): array
    {
        $protocole = Protocole::query()->where('code', ServicePlafondAntecedents::CODE)->firstOrFail();
        $version = $protocole->versions()->where('etat', ProtocoleVersion::BROUILLON)->firstOrFail();

        return [$protocole, $version];
    }

    private function relecteur(string ...$permissions): User
    {
        $user = User::factory()->create();

        foreach ($permissions as $permission) {
            $user->givePermissionTo($permission);
        }

        return $user->fresh();
    }
}
