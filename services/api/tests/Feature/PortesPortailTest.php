<?php

namespace Tests\Feature;

use App\Models\StructureSanitaire;
use App\Models\User;
use Database\Seeders\PortailRolesSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * P11.0 — LES PORTES DU PORTAIL.
 *
 * Sept rôles existaient depuis P1, traduits dans `@masante/shared`, soumis au MFA — et portaient
 * zéro permission, sans qu'aucun portail ne les accepte. Trois autres étaient les doublons
 * dormants de trois rôles vivants. Ce fichier éprouve les deux moitiés du correctif : les rôles
 * réconciliés, et les permissions qui ouvrent réellement quelque chose.
 *
 * Chaque vecteur vise UNE garantie. Ceux qui portent sur une réconciliation de noms sont écrits
 * dans les deux sens : le nom retiré doit avoir disparu, ET le survivant doit porter ce que
 * l'autre aurait dû porter.
 */
class PortesPortailTest extends TestCase
{
    use RefreshDatabase;

    /** Les onze métiers reconnus par la plateforme, après réconciliation. */
    private const ROLES_ATTENDUS = [
        'admin_ivoirsante',
        'assurance',
        'gestionnaire_etablissement',
        'infirmier',
        'laborantin',
        'medecin',
        'ministere',
        'patient',
        'personnel_accueil',
        'pharmacien',
        'radiologue',
    ];

    /** Les trois noms retirés, avec le survivant qui les absorbe. */
    private const NOMS_RETIRES = [
        'secretaire' => 'personnel_accueil',
        'admin_etablissement' => 'gestionnaire_etablissement',
        'super_admin' => 'admin_ivoirsante',
        'agent_garde' => 'personnel_accueil',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(PortailRolesSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Les noms : onze métiers, aucun doublon
    // ─────────────────────────────────────────────────────────────────────────

    #[Test]
    public function les_onze_roles_existent_et_aucun_nom_retire_ne_subsiste(): void
    {
        $enBase = Role::query()->orderBy('name')->pluck('name')->all();

        $this->assertSame(
            self::ROLES_ATTENDUS,
            $enBase,
            'La liste des rôles a divergé. Onze métiers, un nom chacun : tout écart signale soit '
            .'un doublon revenu, soit un rôle créé hors des deux seeders.',
        );

        foreach (array_keys(self::NOMS_RETIRES) as $retire) {
            $this->assertNull(
                Role::query()->where('name', $retire)->first(),
                "Le rôle « {$retire} » a été retiré au profit de son survivant ; le voir revenir "
                .'signifie qu\'un seeder ou une factory le recrée.',
            );
        }
    }

    #[Test]
    public function le_personnel_d_accueil_porte_ce_que_portait_l_agent_de_garde_moins_la_validation_finale(): void
    {
        // Vrai à P11.0 : le renommage `agent_garde` → `personnel_accueil` ne changeait QUE
        // l'étiquette, les cinq permissions restaient identiques (`rdv.validate` compris).
        //
        // B1-a REND CE VECTEUR FAUX, DÉLIBÉRÉMENT : c'est la dette qu'il soldait qui bouge.
        // CDC_11 §9.1 est littéral (« le médecin fait la validation finale ») — jusqu'ici
        // l'accueil pouvait confirmer un RDV de bout en bout, ce que ce test protégeait comme un
        // invariant du renommage. `rdv.validate` est remplacée par `rdv.prevalider` (étape 1,
        // en_attente→prevalide) ; la validation finale (prevalide→confirme) appartient désormais
        // au seul rôle `medecin` (et `gestionnaire_etablissement`, en supervision) — voir
        // `RendezVousValidationService`. Réécrit pour dire la garantie neuve, pas corrigé pour
        // passer (précédent P6.4d).
        $accueil = Role::findByName('personnel_accueil', 'web');

        foreach (['disponibilite.manage', 'rdv.prevalider', 'qr.scan', 'triage.view', 'dossier.referent'] as $p) {
            $this->assertTrue($accueil->hasPermissionTo($p), "L'accueil doit porter `{$p}`.");
        }
        $this->assertFalse(
            $accueil->hasPermissionTo('rdv.validate'),
            "L'accueil ne fait plus la validation finale (B1-a, CDC_11 §9.1) — seul le médecin la fait.",
        );

        $this->assertCount(5, $accueil->permissions, "L'accueil a gagné ou perdu une permission.");
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Les sept rôles muets reçoivent ce qui EXISTE, et rien de plus
    // ─────────────────────────────────────────────────────────────────────────

    #[Test]
    public function les_roles_soignants_peuvent_ecrire_au_carnet_et_identifier_le_patient(): void
    {
        foreach (['infirmier', 'laborantin'] as $nom) {
            $role = Role::findByName($nom, 'web');

            // §6 (constantes, traitements administrés) et §8.1 (publication d'un résultat) sont
            // littéralement « consigner un acte dans le carnet ». Les trois gardes cumulatives de
            // P7-D0 restent en place derrière cette permission.
            $this->assertTrue($role->hasPermissionTo('dossier.ecrire'), "{$nom} doit pouvoir consigner.");
            $this->assertTrue($role->hasPermissionTo('qr.scan'), "{$nom} doit pouvoir identifier le patient.");

            // Ce qu'ils ne reçoivent pas : la signature (aucun type de document ne les concerne
            // dans le registre de P6.5b) et la gouvernance du référentiel de leur propre domaine.
            $this->assertFalse($role->hasPermissionTo('document.signer'));
        }

        $this->assertFalse(
            Role::findByName('laborantin', 'web')->hasPermissionTo('analyse.referentiel'),
            'Un laboratoire ne fixe pas les valeurs de référence contre lesquelles ses propres '
            .'résultats seront lus (P6.7a).',
        );
    }

    #[Test]
    public function le_radiologue_ne_recoit_pas_l_ecriture_au_carnet(): void
    {
        // Vecteur écrit à l'envers de celui du dessus, et c'est le point : il n'existe dans ce
        // projet ni imagerie, ni DICOM, ni compte rendu radiologique — aucune des quatre sections
        // ouvertes au soignant n'en est un. Lui donner `dossier.ecrire` lui ouvrirait les
        // ordonnances et les antécédents, qui ne sont pas son métier.
        $role = Role::findByName('radiologue', 'web');

        $this->assertTrue($role->hasPermissionTo('qr.scan'));
        $this->assertTrue($role->hasPermissionTo('triage.view'));
        $this->assertFalse(
            $role->hasPermissionTo('dossier.ecrire'),
            "Tant que le compte rendu d'imagerie n'existe pas comme entité, l'écriture au carnet "
            .'ouvrirait au radiologue des sections qui ne le concernent pas.',
        );
    }

    #[Test]
    public function le_pharmacien_tient_son_officine_mais_pas_le_catalogue_national(): void
    {
        $role = Role::findByName('pharmacien', 'web');

        $this->assertTrue($role->hasPermissionTo('medicament.manage'));
        $this->assertFalse(
            $role->hasPermissionTo('medicament.referentiel'),
            'Le catalogue national, ses indications et ses interactions ne se décident pas à '
            ."l'officine (P6.6a) : un fabricant serait juge et partie sur son propre produit.",
        );
    }

    #[Test]
    public function le_ministere_pilote_et_surveille_sans_etre_borne_a_un_etablissement(): void
    {
        $role = Role::findByName('ministere', 'web');

        $this->assertTrue($role->hasPermissionTo('stats.global'));
        $this->assertTrue($role->hasPermissionTo('sante_publique.manage'));
        $this->assertFalse(
            $role->hasPermissionTo('stats.etablissement'),
            "Cette permission est bornée à l'établissement du compte, ce qui n'a aucun sens pour "
            .'un ministère qui les regarde tous.',
        );
    }

    #[Test]
    public function l_assurance_ne_porte_aucune_permission_et_c_est_une_garantie_explicite(): void
    {
        // Ce vide est la réponse honnête, pas un oubli : le §8.6 demande de vérifier une
        // couverture et de valider une prise en charge, or aucune de ces capacités n'existe
        // (limite ouverte de P6.8d). Lui fabriquer une permission lui donnerait une clé sans
        // serrure et ferait croire que son portail existe.
        $this->assertCount(
            0,
            Role::findByName('assurance', 'web')->permissions,
            "Le rôle `assurance` doit rester sans permission tant que son portail n'existe pas. "
            .'Si ce vecteur casse, une capacité a été inventée pour un écran absent.',
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // La porte du portail
    // ─────────────────────────────────────────────────────────────────────────

    #[Test]
    public function les_dix_roles_professionnels_entrent_au_portail_et_le_patient_non(): void
    {
        foreach (self::ROLES_ATTENDUS as $nom) {
            // Chaque rôle part d'une session vierge et d'un compteur remis à zéro. Sans cela, deux
            // artefacts du harnais fausseraient le vecteur : `throttle:login` finirait par rendre
            // 429 au bout de onze connexions, et l'`url.intended` laissée par un refus précédent
            // détournerait la redirection du suivant. Ni l'une ni l'autre garde n'est neutralisée —
            // on remet simplement l'état à zéro entre deux cas indépendants.
            $this->flushSession();
            Cache::flush();

            $compte = User::factory()->create(['password' => bcrypt('Portail@2026!'), 'actif' => true]);
            $compte->assignRole($nom);

            $this->post(route('portail.login'), [
                'email' => $compte->email, 'password' => 'Portail@2026!',
            ]);

            if ($nom === 'patient') {
                // On vérifie le REFUS lui-même, pas l'adresse de repli : `back()` dépend du
                // référent de la requête, qui n'a rien à voir avec la garde qu'on éprouve.
                $this->assertGuest();

                continue;
            }

            // Le message nomme le rôle : sans lui, un échec dirait « personne n'est connecté »
            // sans dire lequel des dix a été refusé, et il faudrait relancer dix fois pour savoir.
            $this->assertTrue(
                auth()->check(),
                "Le rôle « {$nom} » doit pouvoir entrer au portail (CDC_11 §5 à §8).",
            );
            $this->assertAuthenticatedAs($compte);
            $this->post(route('portail.logout'));
        }
    }

    #[Test]
    public function un_medecin_peut_scanner_le_qr_du_patient(): void
    {
        // DÉFAUT RÉEL CORRIGÉ PAR P11.0, trouvé en renommant `agent_garde`. `ScanController`
        // exigeait ce rôle PAR SON NOM en plus de la permission `qr.scan` : depuis P6.5a, le
        // rôle `medecin` portait `qr.scan` et se voyait refuser le scan avec le message
        // « réservé aux agents de garde ». La décision « le rôle medecin devient utilisable »
        // était restée à moitié inopérante, sans que rien ne le signale.
        $structure = StructureSanitaire::create([
            'nom' => 'CHU de test', 'type' => 'chu', 'adresse' => 'Abidjan', 'commune' => 'Cocody',
            'latitude' => 5.35, 'longitude' => -3.98, 'actif' => true,
        ]);

        $medecin = User::factory()->create(['structure_id' => $structure->id, 'actif' => true]);
        $medecin->assignRole('medecin');

        $this->actingAs($medecin)
            ->get(route('portail.scan.index'))
            ->assertOk();
    }

    #[Test]
    public function un_compte_sans_etablissement_ne_scanne_pas(): void
    {
        // La garde restante est réelle : sans rattachement, on ne saurait pas au nom de quel
        // établissement la session de dossier est ouverte.
        $medecin = User::factory()->create(['structure_id' => null, 'actif' => true]);
        $medecin->assignRole('medecin');

        $this->actingAs($medecin)
            ->get(route('portail.scan.index'))
            ->assertForbidden();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // `/me` expose les permissions
    // ─────────────────────────────────────────────────────────────────────────

    #[Test]
    public function me_expose_les_permissions_du_role_et_les_permissions_nominatives(): void
    {
        // Les deux sources comptent, et le vecteur les distingue : quatorze permissions de ce
        // projet n'appartiennent délibérément à aucun rôle et sont accordées nominativement.
        // N'exposer que celles du rôle rendrait le portail aveugle à la moitié de la réalité.
        $compte = User::factory()->create(['actif' => true]);
        $compte->assignRole('medecin');
        $compte->givePermissionTo('urgence.bris_de_glace');

        $reponse = $this->actingAs($compte, 'sanctum')->getJson('/api/v1/auth/me');

        $reponse->assertOk();
        $permissions = $reponse->json('user.permissions');

        $this->assertIsArray($permissions, '`/me` doit exposer les permissions (P11.0).');
        $this->assertContains('dossier.ecrire', $permissions, 'Permission héritée du rôle.');
        $this->assertContains('urgence.bris_de_glace', $permissions, 'Permission nominative.');
        $this->assertNotContains('etablissement.manage', $permissions, 'Permission qu\'il n\'a pas.');
    }

    #[Test]
    public function me_expose_une_liste_vide_et_non_nulle_pour_un_compte_sans_permission(): void
    {
        // Un tableau vide et une clé absente ne se ressemblent pas côté front : l'un dit « aucune
        // permission », l'autre « le backend ne me l'a pas dit ». C'est le cas du rôle
        // `assurance`, et le portail doit pouvoir l'afficher honnêtement.
        $compte = User::factory()->create(['actif' => true]);
        $compte->assignRole('assurance');

        $this->actingAs($compte, 'sanctum')
            ->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('user.permissions', []);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // La migration de réconciliation transfère les comptes
    // ─────────────────────────────────────────────────────────────────────────

    #[Test]
    public function la_migration_transfere_les_comptes_avant_de_supprimer_le_role_retire(): void
    {
        // Le vecteur qui compte : sur une base vierge la migration ne fait rien (les rôles
        // retirés n'existent pas), donc elle n'est prouvée que si on reconstitue la situation
        // qu'elle est faite pour résoudre. Supprimer d'abord aurait « nettoyé » silencieusement
        // un utilisateur de son rôle — la panne muette que ce projet refuse partout.
        $ancien = Role::create(['name' => 'super_admin', 'guard_name' => 'web']);
        $ancien->givePermissionTo(Permission::first());

        $compte = User::factory()->create(['actif' => true]);
        $compte->assignRole($ancien);

        $this->assertTrue($compte->fresh()->hasRole('super_admin'));
        $this->assertFalse($compte->fresh()->hasRole('admin_ivoirsante'));

        $migration = require database_path('migrations/2026_08_30_000002_p11_reconciliation_roles.php');
        $migration->up();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->assertTrue(
            $compte->fresh()->hasRole('admin_ivoirsante'),
            'Le compte doit avoir reçu le rôle survivant.',
        );
        $this->assertNull(Role::query()->where('name', 'super_admin')->first());
        $this->assertSame(
            0,
            DB::table('model_has_roles')->where('role_id', $ancien->id)->count(),
            "L'attribution de l'ancien rôle doit avoir disparu, pas rester orpheline.",
        );
    }

    #[Test]
    public function la_migration_refuse_bruyamment_de_renommer_sur_un_nom_deja_pris(): void
    {
        // `personnel_accueil` existe déjà (posé par le seeder). Recréer `agent_garde` place la
        // migration devant deux rôles vivants : elle doit s'arrêter en disant quoi faire, plutôt
        // que de laisser le moteur rendre une erreur d'unicité nue au milieu d'un déploiement.
        Role::create(['name' => 'agent_garde', 'guard_name' => 'web']);

        $migration = require database_path('migrations/2026_08_30_000002_p11_reconciliation_roles.php');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/les deux existent/');

        $migration->up();
    }
}
