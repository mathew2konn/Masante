<?php

namespace Tests\Feature;

use App\Events\PartageRdvEcriture;
use App\Events\PartageRdvFerme;
use App\Events\PartageRdvOuvert;
use App\Models\Medecin;
use App\Models\MembreFamille;
use App\Models\RendezVous;
use App\Models\ServiceEtablissement;
use App\Models\StructureSanitaire;
use App\Models\User;
use App\Services\EcritureSoignantService;
use App\Services\PartageRdvService;
use App\Services\SessionDossierService;
use App\Support\AutorisationCanalPresenceRdv;
use App\Support\DiffusionPresence;
use Database\Seeders\PortailRolesSeeder;
use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * B1-c — Partage temporaire d'accès (30 min) vers le médecin d'UN rendez-vous précis + présence
 * temps réel (D8/D9, CDC_11 §9). Voir {@see PartageRdvService} pour la conception.
 *
 * QUATRE GARDES à l'ouverture, chacune son vecteur — aucune ne rattrape les autres : permission,
 * anti-IDOR (le bon médecin), statut `confirme`, check-in accueil. Et TROIS ÉVÉNEMENTS diffusés,
 * chacun sur le canal `rdv.{id}.presence`, sans jamais casser l'appelant si la diffusion échoue.
 */
class PartageRdvTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PortailRolesSeeder::class);
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // Fixtures
    // ─────────────────────────────────────────────────────────────────────────────

    private function structure(): StructureSanitaire
    {
        return StructureSanitaire::create([
            'nom' => 'CHU de Cocody', 'type' => 'chu', 'adresse' => 'Abidjan',
            'commune' => 'Cocody', 'latitude' => 5.35, 'longitude' => -3.98, 'actif' => true,
        ]);
    }

    private function service(StructureSanitaire $s): ServiceEtablissement
    {
        return ServiceEtablissement::create([
            'structure_id' => $s->id, 'nom_service' => 'Cardiologie', 'specialite' => 'cardiologie', 'actif' => true,
        ]);
    }

    /** @return array{0: User, 1: Medecin} le compte connecté ET sa fiche, LIÉS. */
    private function medecinAvecFiche(ServiceEtablissement $service): array
    {
        $user = User::factory()->create(['structure_id' => $service->structure_id, 'service_id' => $service->id]);
        $user->assignRole('medecin');

        $fiche = Medecin::create([
            'structure_id' => $service->structure_id, 'service_id' => $service->id, 'user_id' => $user->id,
            'nom' => 'Koffi', 'prenom' => 'Aya', 'titre' => 'Dr', 'specialite' => 'cardiologie', 'actif' => true,
        ]);

        return [$user->fresh(), $fiche];
    }

    private function rdv(
        ServiceEtablissement $service,
        ?Medecin $medecin = null,
        string $statut = 'confirme',
        bool $enregistre = true,
    ): RendezVous {
        $membre = MembreFamille::factory()->create();

        return RendezVous::create([
            'membre_id' => $membre->id, 'structure_id' => $service->structure_id, 'service_id' => $service->id,
            'medecin_id' => $medecin?->id, 'motif' => 'Douleur thoracique',
            'date_souhaitee' => now()->addDays(2)->toDateString(), 'mode_attribution' => 'etablissement_attribue',
            'statut' => $statut, 'date_confirmee' => $statut === 'confirme' ? now()->addDay() : null,
            'checked_in_at' => $enregistre ? now() : null,
        ]);
    }

    private function service_(): PartageRdvService
    {
        return app(PartageRdvService::class);
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // Ouverture — ce que D8 permet
    // ─────────────────────────────────────────────────────────────────────────────

    public function test_le_medecin_de_ce_rdv_ouvre_son_acces(): void
    {
        Event::fake([PartageRdvOuvert::class]);
        $service = $this->service($this->structure());
        [$medecinUser, $fiche] = $this->medecinAvecFiche($service);
        $rdv = $this->rdv($service, $fiche);

        $acces = $this->service_()->ouvrir($medecinUser, $rdv, '127.0.0.1');

        $this->assertSame('rdv_partage', $acces->type_acces);
        $this->assertSame($medecinUser->id, $acces->agent_id);
        $this->assertSame($rdv->membre_id, $acces->membre_id);
        $this->assertSame($rdv->id, $acces->rendez_vous_id);
        $this->assertSame('CHU de Cocody', $acces->etablissement);

        $session = app(SessionDossierService::class);
        $this->assertTrue($session->estActive());
        $this->assertSame('rdv_partage', $session->typeAcces());
        $this->assertSame($rdv->id, $session->rdvDeclare());
        $this->assertGreaterThan(1700, $session->secondesRestantes()); // ~30 min

        Event::assertDispatched(PartageRdvOuvert::class, function ($e) use ($rdv) {
            return $e->rdvId === $rdv->id && $e->medecinNom === 'Dr Aya Koffi';
        });
    }

    public function test_refuse_sans_la_permission_rdv_validate(): void
    {
        $service = $this->service($this->structure());
        [$medecinUser, $fiche] = $this->medecinAvecFiche($service);
        $medecinUser->removeRole('medecin'); // perd rdv.validate
        $rdv = $this->rdv($service, $fiche);

        try {
            $this->service_()->ouvrir($medecinUser->fresh(), $rdv, null);
            $this->fail('Aurait dû être refusé (403).');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
    }

    /** Anti-IDOR — un médecin habilité, mais pas CELUI de ce rendez-vous précis. 404, pas 403. */
    public function test_refuse_a_un_medecin_qui_n_est_pas_celui_de_ce_rdv(): void
    {
        $service = $this->service($this->structure());
        [, $ficheAttribuee] = $this->medecinAvecFiche($service);
        [$autreMedecinUser] = $this->medecinAvecFiche($service);
        $rdv = $this->rdv($service, $ficheAttribuee);

        try {
            $this->service_()->ouvrir($autreMedecinUser, $rdv, null);
            $this->fail('Aurait dû être refusé (404).');
        } catch (HttpException $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
    }

    public function test_refuse_tant_que_le_rdv_n_est_pas_confirme(): void
    {
        $service = $this->service($this->structure());
        [$medecinUser, $fiche] = $this->medecinAvecFiche($service);
        $rdv = $this->rdv($service, $fiche, statut: 'prevalide');

        try {
            $this->service_()->ouvrir($medecinUser, $rdv, null);
            $this->fail('Aurait dû être refusé (409).');
        } catch (HttpException $e) {
            $this->assertSame(409, $e->getStatusCode());
        }
    }

    public function test_refuse_tant_que_le_patient_n_est_pas_enregistre_a_l_accueil(): void
    {
        $service = $this->service($this->structure());
        [$medecinUser, $fiche] = $this->medecinAvecFiche($service);
        $rdv = $this->rdv($service, $fiche, enregistre: false);

        try {
            $this->service_()->ouvrir($medecinUser, $rdv, null);
            $this->fail('Aurait dû être refusé (409).');
        } catch (HttpException $e) {
            $this->assertSame(409, $e->getStatusCode());
        }
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // Écriture pendant la session — le canal se déclenche, jamais ailleurs
    // ─────────────────────────────────────────────────────────────────────────────

    public function test_une_ecriture_pendant_la_session_diffuse_l_evenement(): void
    {
        Event::fake([PartageRdvEcriture::class]);
        $service = $this->service($this->structure());
        [$medecinUser, $fiche] = $this->medecinAvecFiche($service);
        $rdv = $this->rdv($service, $fiche);
        $this->service_()->ouvrir($medecinUser, $rdv, null);

        // Le rôle `medecin` porte déjà `dossier.ecrire` depuis P6.5a — rien à ajouter ici.
        app(EcritureSoignantService::class)->ecrire(
            $medecinUser,
            $rdv->membre,
            'rdv_partage',
            'antecedents',
            ['type' => 'maladie_chronique', 'description' => 'Suivi cardiologique'],
        );

        Event::assertDispatched(PartageRdvEcriture::class, fn ($e) => $e->rdvId === $rdv->id);
    }

    /**
     * LA GARDE SYMÉTRIQUE : une écriture en `qr_scan` (ou toute autre voie) ne doit JAMAIS
     * diffuser — aucun canal Reverb n'est ouvert pour ces voies, et un patient qui consulte son
     * carnet normalement ne doit rien voir en direct.
     */
    public function test_une_ecriture_hors_rdv_partage_ne_diffuse_rien(): void
    {
        Event::fake([PartageRdvEcriture::class]);
        $enfant = MembreFamille::factory()->create();
        $soignant = User::factory()->create();
        $soignant->givePermissionTo('dossier.ecrire');

        app(EcritureSoignantService::class)->ecrire(
            $soignant, $enfant, 'qr_scan', 'antecedents',
            ['type' => 'maladie_chronique', 'description' => 'Sans rapport'],
        );

        Event::assertNotDispatched(PartageRdvEcriture::class);
    }

    /**
     * LE VECTEUR QUI ISOLE VRAIMENT LA GARDE (et non la seule absence de session). Sans une
     * session `rdv_partage` réellement active, `rdvDeclare()` vaut déjà NULL — le vecteur
     * ci-dessus ne prouverait la garde `$voie === RDV_PARTAGE` qu'en apparence : une mutation qui
     * la neutralise SURVIT à ce seul vecteur (trouvé pendant la campagne de mutation, motif
     * « le vecteur prouve autre chose »). Ici une VRAIE session `rdv_partage` est active
     * (`rdvDeclare()` non NULL), et c'est le `$voie` MENTI par l'appelant qui doit, seul, empêcher
     * la diffusion.
     */
    public function test_un_voie_mensongere_ne_diffuse_pas_meme_avec_une_session_rdv_partage_active(): void
    {
        Event::fake([PartageRdvEcriture::class]);
        $service = $this->service($this->structure());
        [$medecinUser, $fiche] = $this->medecinAvecFiche($service);
        $rdv = $this->rdv($service, $fiche);
        $this->service_()->ouvrir($medecinUser, $rdv, null); // rdvDeclare() vaut désormais $rdv->id

        app(EcritureSoignantService::class)->ecrire(
            $medecinUser, $rdv->membre, 'qr_scan', 'antecedents', // voie MENTIE : la session est rdv_partage
            ['type' => 'maladie_chronique', 'description' => 'Sans rapport'],
        );

        Event::assertNotDispatched(PartageRdvEcriture::class);
    }

    /**
     * Aucun contenu médical dans la charge diffusée — précédent P7-D1, transposé à ce canal.
     *
     * DÉFAUT RÉEL TROUVÉ EN B1-d (2026-09-02, échec réel à 20:42) : le vecteur cherchait « 42 »
     * (l'id de RDV du test) comme sous-chaîne du JSON ENTIER — y compris `'a' => now()->…`, un
     * horodatage RÉEL. À chaque minute ou seconde :42 (~1/60 des exécutions), la charge contenait
     * « 42 » dans son horodatage, et le test échouait pour une raison SANS RAPPORT avec une fuite
     * d'identifiant — un vecteur qui ment selon l'heure. Corrigé en vérifiant les CLÉS de la charge
     * (elle ne doit porter QUE `'a'`) plutôt qu'une recherche de sous-chaîne sur un horodatage
     * vivant — garantie plus forte, et non flaky.
     */
    public function test_l_evenement_d_ecriture_ne_porte_aucun_contenu_clinique(): void
    {
        $evenement = new PartageRdvEcriture(42);
        $charge = $evenement->broadcastWith();

        $this->assertSame(['a'], array_keys($charge), 'La charge ne doit porter QUE l\'horodatage.');
        $this->assertStringNotContainsString(
            'antecedent',
            strtolower((string) json_encode($charge, JSON_UNESCAPED_UNICODE)),
        );
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // Clôture — explicite, idempotente-défensive
    // ─────────────────────────────────────────────────────────────────────────────

    public function test_fermer_diffuse_et_referme_la_session(): void
    {
        Event::fake([PartageRdvFerme::class]);
        $service = $this->service($this->structure());
        [$medecinUser, $fiche] = $this->medecinAvecFiche($service);
        $rdv = $this->rdv($service, $fiche);
        $this->service_()->ouvrir($medecinUser, $rdv, null);

        $this->service_()->fermer($rdv);

        $this->assertFalse(app(SessionDossierService::class)->estActive());
        Event::assertDispatched(PartageRdvFerme::class, fn ($e) => $e->rdvId === $rdv->id);
    }

    /** Idempotent-défensif : fermer une seconde fois (bouton cliqué deux fois) ne fait rien. */
    public function test_fermer_deux_fois_ne_diffuse_qu_une_fois(): void
    {
        Event::fake([PartageRdvFerme::class]);
        $service = $this->service($this->structure());
        [$medecinUser, $fiche] = $this->medecinAvecFiche($service);
        $rdv = $this->rdv($service, $fiche);
        $this->service_()->ouvrir($medecinUser, $rdv, null);

        $this->service_()->fermer($rdv);
        $this->service_()->fermer($rdv); // aucune session active : ne fait rien

        Event::assertDispatchedTimes(PartageRdvFerme::class, 1);
    }

    /** Une session `rdv_partage` d'un AUTRE rendez-vous n'est pas fermée par erreur. */
    public function test_fermer_ignore_la_session_d_un_autre_rdv(): void
    {
        Event::fake([PartageRdvFerme::class]);
        $service = $this->service($this->structure());
        [$medecinUser, $fiche] = $this->medecinAvecFiche($service);
        $rdv1 = $this->rdv($service, $fiche);
        $rdv2 = $this->rdv($service, $fiche);
        $this->service_()->ouvrir($medecinUser, $rdv1, null);

        $this->service_()->fermer($rdv2); // la session active porte $rdv1, pas $rdv2

        $this->assertTrue(app(SessionDossierService::class)->estActive());
        Event::assertNotDispatched(PartageRdvFerme::class);
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // Autorisation du canal — routes/channels.php (D9 : « le seul titulaire/patient concerné »)
    // ─────────────────────────────────────────────────────────────────────────────

    public function test_seul_le_titulaire_du_membre_est_autorise_sur_le_canal(): void
    {
        $service = $this->service($this->structure());
        [, $fiche] = $this->medecinAvecFiche($service);
        $rdv = $this->rdv($service, $fiche);
        $titulaire = $rdv->membre->user;

        $this->assertTrue(AutorisationCanalPresenceRdv::verifier($titulaire, $rdv->id));
    }

    public function test_un_autre_compte_est_refuse_sur_le_canal(): void
    {
        $service = $this->service($this->structure());
        [, $fiche] = $this->medecinAvecFiche($service);
        $rdv = $this->rdv($service, $fiche);
        $unAutre = User::factory()->create();

        $this->assertFalse(AutorisationCanalPresenceRdv::verifier($unAutre, $rdv->id));
    }

    /** Un rendez-vous inconnu ne doit jamais lever — juste refuser. */
    public function test_un_rdv_inconnu_est_refuse_sur_le_canal(): void
    {
        $quelconque = User::factory()->create();

        $this->assertFalse(AutorisationCanalPresenceRdv::verifier($quelconque, 999999));
    }

    /** Même le médecin qui a ouvert l'accès n'est PAS autorisé sur ce canal : lui, il ÉCRIT. */
    public function test_le_medecin_lui_meme_n_est_pas_autorise_sur_le_canal(): void
    {
        $service = $this->service($this->structure());
        [$medecinUser, $fiche] = $this->medecinAvecFiche($service);
        $rdv = $this->rdv($service, $fiche);

        $this->assertFalse(AutorisationCanalPresenceRdv::verifier($medecinUser, $rdv->id));
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // La garde qui ne casse jamais l'appelant — précédent P7-D1
    // ─────────────────────────────────────────────────────────────────────────────

    public function test_diffusion_presence_avale_toute_exception_sans_la_relancer(): void
    {
        Log::spy();

        $evenementEmpoisonne = new class implements ShouldBroadcastNow
        {
            /** @return array<Channel> */
            public function broadcastOn(): array
            {
                throw new \RuntimeException('Serveur de diffusion injoignable (simulé)');
            }
        };

        // Ne lève RIEN : c'est la garantie testée.
        DiffusionPresence::diffuser($evenementEmpoisonne);

        Log::shouldHaveReceived('warning')->once();
        $this->assertTrue(true);
    }
}
