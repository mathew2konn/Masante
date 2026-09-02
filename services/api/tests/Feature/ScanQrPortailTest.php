<?php

namespace Tests\Feature;

use App\Models\AccesDossier;
use App\Models\MembreFamille;
use App\Models\Paiement;
use App\Models\RecuRdv;
use App\Models\RendezVous;
use App\Models\ServiceEtablissement;
use App\Models\StructureSanitaire;
use App\Models\TokenQr;
use App\Models\User;
use App\Services\QrTokenService;
use App\Services\RecuRdvService;
use App\Services\SessionDossierService;
use Database\Seeders\PortailRolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Module 4 / 4.5 — Scan des QR à l'accueil.
 *
 * Deux flux cloisonnés : le QR carnet ouvre le dossier (session 30 min, audit en 2 lignes) ;
 * le QR de reçu enregistre l'arrivée du patient sans jamais ouvrir de dossier.
 */
class ScanQrPortailTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PortailRolesSeeder::class);
    }

    private function structure(string $nom = 'CHU Test'): StructureSanitaire
    {
        return StructureSanitaire::create([
            'nom' => $nom, 'type' => 'chu', 'adresse' => 'Abidjan', 'commune' => 'Cocody',
            'latitude' => 5.35, 'longitude' => -3.98, 'actif' => true,
        ]);
    }

    private function service(StructureSanitaire $s): ServiceEtablissement
    {
        return ServiceEtablissement::create([
            'structure_id' => $s->id, 'nom_service' => 'Urgences', 'specialite' => 'urgences', 'actif' => true,
        ]);
    }

    private function agent(ServiceEtablissement $service): User
    {
        $u = User::factory()->create([
            'password' => Hash::make('Agent@2026!'),
            'structure_id' => $service->structure_id,
            'service_id' => $service->id,
        ]);
        $u->assignRole('personnel_accueil');

        return $u;
    }

    private function qrPour(MembreFamille $membre): string
    {
        return app(QrTokenService::class)->generer($membre)['qr'];
    }

    /** Reçu payé pour un RDV confirmé, avec son code de check-in signé. */
    private function recuPour(RendezVous $rdv): RecuRdv
    {
        $paiement = Paiement::create([
            'rendez_vous_id' => $rdv->id, 'montant' => 5000, 'mode' => 'mobile_money',
            'statut' => 'paye', 'transaction_ref' => 'SIM-TEST',
        ]);

        return RecuRdv::create([
            'rendez_vous_id' => $rdv->id, 'paiement_id' => $paiement->id,
            'reference' => 'MS-RECU-2026-'.strtoupper(Str::random(6)),   // `reference` est unique en base
            'statut' => 'paye', 'expires_at' => now()->endOfDay(),
        ]);
    }

    private function rdv(ServiceEtablissement $service, string $statut = 'confirme'): RendezVous
    {
        $membre = MembreFamille::factory()->create();

        return RendezVous::create([
            'membre_id' => $membre->id, 'structure_id' => $service->structure_id, 'service_id' => $service->id,
            'motif' => 'Fièvre', 'date_souhaitee' => now()->addDay()->toDateString(),
            'mode_attribution' => 'etablissement_attribue', 'statut' => $statut,
        ]);
    }

    // ---- Flux 1 : QR carnet → dossier ---------------------------------------

    public function test_un_agent_scanne_un_qr_valide_et_ouvre_le_dossier(): void
    {
        $service = $this->service($this->structure());
        $membre = MembreFamille::factory()->create();

        $this->actingAs($this->agent($service))
            ->post(route('portail.scan.carnet'), ['token' => $this->qrPour($membre)])
            ->assertRedirect(route('portail.dossier.show'));

        // Le token est consommé et l'ouverture est journalisée avec l'agent et l'établissement.
        $this->assertNotNull(TokenQr::first()->used_at);
        $this->assertDatabaseHas('acces_dossier', [
            'membre_id' => $membre->id, 'type_acces' => 'qr_scan', 'duree_minutes' => null,
        ]);

        $this->get(route('portail.dossier.show'))->assertOk()->assertSee($membre->nom);
    }

    public function test_un_qr_deja_utilise_est_refuse_sans_ouvrir_de_dossier(): void
    {
        $service = $this->service($this->structure());
        $membre = MembreFamille::factory()->create();
        $qr = $this->qrPour($membre);
        $agent = $this->agent($service);

        $this->actingAs($agent)->post(route('portail.scan.carnet'), ['token' => $qr]);
        $this->actingAs($agent)->post(route('portail.dossier.fermer'));

        // Rejeu du même token : refusé (409 traduit en message d'accueil).
        $this->actingAs($agent)
            ->post(route('portail.scan.carnet'), ['token' => $qr])
            ->assertSessionHasErrors('token');
    }

    public function test_un_qr_expire_est_refuse(): void
    {
        $service = $this->service($this->structure());
        $membre = MembreFamille::factory()->create();
        $qr = $this->qrPour($membre);
        TokenQr::first()->forceFill(['expires_at' => now()->subMinute()])->save();

        $this->actingAs($this->agent($service))
            ->post(route('portail.scan.carnet'), ['token' => $qr])
            ->assertSessionHasErrors('token');

        $this->assertDatabaseCount('acces_dossier', 0);
    }

    public function test_la_cloture_journalise_la_duree_et_les_sections_consultees(): void
    {
        $service = $this->service($this->structure());
        $membre = MembreFamille::factory()->create();
        $agent = $this->agent($service);

        $this->actingAs($agent)->post(route('portail.scan.carnet'), ['token' => $this->qrPour($membre)]);
        $this->get(route('portail.dossier.show'))->assertOk();               // fiche vitale (identite)
        $this->get(route('portail.dossier.section', 'antecedents'))->assertOk();
        $this->get(route('portail.dossier.section', 'vaccinations'))->assertOk();

        $this->travel(5)->minutes();
        $this->post(route('portail.dossier.fermer'))->assertRedirect(route('portail.scan.index'));

        // Journal en AJOUT SEUL : une ligne d'ouverture + une ligne de clôture, même token.
        $this->assertSame(2, AccesDossier::count());
        $cloture = AccesDossier::latest('id')->first();

        $this->assertSame(5, $cloture->duree_minutes);
        $this->assertSame(['identite', 'antecedents', 'vaccinations'], $cloture->sections_consultees);
        $this->assertSame(AccesDossier::oldest('id')->first()->token_qr_id, $cloture->token_qr_id);
    }

    public function test_la_session_dossier_expire_au_bout_de_trente_minutes(): void
    {
        $service = $this->service($this->structure());
        $membre = MembreFamille::factory()->create();

        $this->actingAs($this->agent($service))
            ->post(route('portail.scan.carnet'), ['token' => $this->qrPour($membre)]);

        $this->travel(SessionDossierService::DUREE_MINUTES + 1)->minutes();

        // Fenêtre close : l'agent est renvoyé au scanner, et la clôture est journalisée.
        $this->get(route('portail.dossier.show'))->assertRedirect(route('portail.scan.index'));
        $this->assertSame(2, AccesDossier::count());
        $this->assertSame(SessionDossierService::DUREE_MINUTES, AccesDossier::latest('id')->first()->duree_minutes);
    }

    public function test_le_dossier_est_inaccessible_sans_scan_prealable(): void
    {
        $service = $this->service($this->structure());

        $this->actingAs($this->agent($service))
            ->get(route('portail.dossier.show'))
            ->assertRedirect(route('portail.scan.index'));
    }

    public function test_le_gestionnaire_et_l_admin_ne_scannent_pas(): void
    {
        $structure = $this->structure();
        $this->service($structure);

        // Le gestionnaire n'a pas la permission `qr.scan` (CdC §5.4.2).
        $gestionnaire = User::factory()->create(['structure_id' => $structure->id]);
        $gestionnaire->assignRole('gestionnaire_etablissement');
        $this->actingAs($gestionnaire)->get(route('portail.scan.index'))->assertForbidden();

        // L'admin hérite de la permission mais n'est rattaché à aucun établissement → refusé.
        $admin = User::factory()->create(['structure_id' => null]);
        $admin->assignRole('admin_ivoirsante');
        $this->actingAs($admin)->get(route('portail.scan.index'))->assertForbidden();
    }

    // ---- Flux 2 : QR reçu → check-in ----------------------------------------

    public function test_un_agent_enregistre_l_arrivee_du_patient_par_le_qr_du_recu(): void
    {
        $service = $this->service($this->structure());
        $rdv = $this->rdv($service);
        $code = app(RecuRdvService::class)->vue($this->recuPour($rdv))['code'];

        $this->actingAs($this->agent($service))
            ->post(route('portail.scan.checkin'), ['code' => $code])
            ->assertRedirect(route('portail.rdv.show', $rdv));

        $this->assertNotNull($rdv->fresh()->checked_in_at);
        $this->assertSame('utilise', $rdv->recu->fresh()->statut);

        // Un check-in n'ouvre AUCUN dossier : le journal d'accès reste vide.
        $this->assertDatabaseCount('acces_dossier', 0);
    }

    public function test_le_check_in_refuse_un_rdv_non_confirme_un_code_falsifie_et_un_autre_etablissement(): void
    {
        $service = $this->service($this->structure());
        $agent = $this->agent($service);

        // (a) RDV pas encore confirmé par l'agent.
        $enAttente = $this->rdv($service, 'en_attente');
        $code = app(RecuRdvService::class)->vue($this->recuPour($enAttente))['code'];
        $this->actingAs($agent)->post(route('portail.scan.checkin'), ['code' => $code])->assertSessionHasErrors('code');
        $this->assertNull($enAttente->fresh()->checked_in_at);

        // (b) Signature HMAC falsifiée.
        $this->actingAs($agent)
            ->post(route('portail.scan.checkin'), ['code' => explode('.', $code)[0].'.0000'])
            ->assertSessionHasErrors('code');

        // (c) Reçu d'un autre établissement : 404, on ne confirme même pas son existence.
        $autre = $this->rdv($this->service($this->structure('Clinique Autre')));
        $codeAutre = app(RecuRdvService::class)->vue($this->recuPour($autre))['code'];
        $this->actingAs($agent)->post(route('portail.scan.checkin'), ['code' => $codeAutre])->assertNotFound();
    }
}
