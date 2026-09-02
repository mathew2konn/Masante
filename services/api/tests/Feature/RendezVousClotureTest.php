<?php

namespace Tests\Feature;

use App\Models\FacturePatient;
use App\Models\Medecin;
use App\Models\MembreFamille;
use App\Models\Paiement;
use App\Models\RendezVous;
use App\Models\ServiceEtablissement;
use App\Models\StructureSanitaire;
use App\Models\User;
use App\Services\RecuRdvService;
use App\Services\RendezVousValidationService;
use App\Support\TypeNotification;
use Database\Seeders\PortailRolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * B1-d — Clôture du rendez-vous (D10), prévalidateur distinct du check-in (D11), notification de
 * clôture (D15). Voir {@see RendezVousValidationService::terminer()} pour la conception, et la
 * correction qu'elle porte au plan G1 : la facture n'est plus « générée à la clôture » — depuis
 * B1-c le règlement précède TOUJOURS le check-in, donc elle existe déjà. Ce qui restait réellement
 * à faire, c'est `honore` (clé morte depuis B1-a) et la trace de qui a clos, quand.
 */
class RendezVousClotureTest extends TestCase
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

    private function service(StructureSanitaire $s, ?int $tarif = 5000): ServiceEtablissement
    {
        return ServiceEtablissement::create([
            'structure_id' => $s->id, 'nom_service' => 'Cardiologie', 'specialite' => 'cardiologie',
            'actif' => true, 'tarif_consultation_cfa' => $tarif,
        ]);
    }

    private function medecinUser(ServiceEtablissement $service): User
    {
        $user = User::factory()->create(['structure_id' => $service->structure_id, 'service_id' => $service->id]);
        $user->assignRole('medecin');

        Medecin::create([
            'structure_id' => $service->structure_id, 'service_id' => $service->id, 'user_id' => $user->id,
            'nom' => 'Koffi', 'prenom' => 'Aya', 'titre' => 'Dr', 'specialite' => 'cardiologie', 'actif' => true,
        ]);

        return $user->fresh();
    }

    private function accueilUser(StructureSanitaire $structure): User
    {
        $user = User::factory()->create(['structure_id' => $structure->id]);
        $user->assignRole('personnel_accueil');

        return $user;
    }

    private function membre(?User $titulaire = null): MembreFamille
    {
        $titulaire ??= User::factory()->create();
        $membre = new MembreFamille([
            'nom' => 'Yao', 'prenom' => 'Awa', 'date_naissance' => '2000-01-01', 'sexe' => 'F',
        ]);
        $membre->user_id = $titulaire->id;
        $membre->matricule_ivs = 'IVS-2026-RC-'.uniqid();
        $membre->save();

        return $membre;
    }

    private function rdv(
        ServiceEtablissement $service,
        string $statut = 'confirme',
        ?MembreFamille $membre = null,
        bool $enregistre = true,
    ): RendezVous {
        return RendezVous::create([
            'membre_id' => ($membre ?? $this->membre())->id,
            'structure_id' => $service->structure_id, 'service_id' => $service->id,
            'motif' => 'Suivi', 'date_souhaitee' => now()->addDays(2)->toDateString(),
            'mode_attribution' => 'etablissement_attribue', 'statut' => $statut,
            'date_confirmee' => $statut === 'confirme' ? now()->addDay() : null,
            // Les tests de `terminer()` isolent chaque garde séparément (permission/statut/
            // check-in/règlement) : le check-in est donc posé DIRECTEMENT ici plutôt que rejoué
            // via le flux réel (`ScanController::checkIn`, hors périmètre de ce fichier).
            'checked_in_at' => $enregistre ? now() : null,
        ]);
    }

    /** Règle le RDV (crée `Paiement` + `FacturePatient` PAYEE) — même chemin que le patient réel. */
    private function regler(RendezVous $rdv): void
    {
        app(RecuRdvService::class)->payer($rdv, 'especes');
    }

    private function rdvs(): RendezVousValidationService
    {
        return app(RendezVousValidationService::class);
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // D11 — prévalidateur distinct du check-in
    // ─────────────────────────────────────────────────────────────────────────────

    public function test_previsalider_capture_l_agent_qui_pre_valide(): void
    {
        $service = $this->service($this->structure());
        $accueil = $this->accueilUser($service->structure);
        $rdv = $this->rdv($service, 'en_attente');

        $this->rdvs()->previsalider($accueil, $rdv);

        $this->assertSame($accueil->id, $rdv->fresh()->prevalide_par_agent_id);
    }

    /** Le prévalidateur et l'agent de check-in ne sont pas forcément la même personne. */
    public function test_prevalidateur_et_check_in_restent_deux_colonnes_distinctes(): void
    {
        $service = $this->service($this->structure());
        $accueilMatin = $this->accueilUser($service->structure);
        $accueilSoir = $this->accueilUser($service->structure);
        $rdv = $this->rdv($service, 'en_attente');

        $this->rdvs()->previsalider($accueilMatin, $rdv);
        $rdv->fresh()->update(['checked_in_at' => now(), 'checked_in_by_agent_id' => $accueilSoir->id]);

        $frais = $rdv->fresh();
        $this->assertSame($accueilMatin->id, $frais->prevalide_par_agent_id);
        $this->assertSame($accueilSoir->id, $frais->checked_in_by_agent_id);
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // D10 — terminer() : quatre garanties, aucune ne rattrape les autres
    // ─────────────────────────────────────────────────────────────────────────────

    public function test_terminer_cloture_le_rdv_et_trace_l_agent(): void
    {
        $service = $this->service($this->structure());
        $medecin = $this->medecinUser($service);
        $rdv = $this->rdv($service);
        $this->regler($rdv);

        $resultat = $this->rdvs()->terminer($medecin, $rdv);

        $this->assertSame('honore', $resultat->statut);
        $this->assertNotNull($resultat->termine_le);
        $this->assertSame($medecin->id, $resultat->termine_par_agent_id);
    }

    public function test_terminer_refuse_sans_la_permission_rdv_validate(): void
    {
        $service = $this->service($this->structure());
        $accueil = $this->accueilUser($service->structure); // rdv.prevalider, pas rdv.validate
        $rdv = $this->rdv($service);
        $this->regler($rdv);

        try {
            $this->rdvs()->terminer($accueil, $rdv);
            $this->fail('Aurait dû être refusé (403).');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
    }

    /**
     * 409 est PARTAGÉ par les trois gardes de `terminer()` (statut, check-in, règlement) : un
     * vecteur qui ne vérifie que le code laisserait une mutation élargissant `assertStatut()`
     * survivre en silence, rattrapée par une AUTRE garde pour une raison sans rapport (famille
     * « le vecteur prouve autre chose », déjà rencontrée huit fois dans ce projet — trouvée ICI
     * PAR LA MUTATION elle-même). Les deux autres gardes sont donc délibérément SATISFAITES
     * (check-in + réglé), pour que seul le statut puisse expliquer le refus — et le message,
     * propre à cette garde, est vérifié.
     */
    public function test_terminer_refuse_hors_confirme(): void
    {
        $service = $this->service($this->structure());
        $medecin = $this->medecinUser($service);
        $rdv = $this->rdv($service, 'prevalide'); // enregistré par défaut
        $this->regler($rdv);

        try {
            $this->rdvs()->terminer($medecin, $rdv);
            $this->fail('Aurait dû être refusé (409).');
        } catch (HttpException $e) {
            $this->assertSame(409, $e->getStatusCode());
            $this->assertSame('Ce rendez-vous doit être confirmé avant d\'être clos.', $e->getMessage());
        }
    }

    public function test_terminer_refuse_tant_que_le_patient_n_est_pas_enregistre_a_l_accueil(): void
    {
        $service = $this->service($this->structure());
        $medecin = $this->medecinUser($service);
        $rdv = $this->rdv($service, enregistre: false);
        $this->regler($rdv); // réglé, mais jamais enregistré à l'accueil

        try {
            $this->rdvs()->terminer($medecin, $rdv->fresh());
            $this->fail('Aurait dû être refusé (409).');
        } catch (HttpException $e) {
            $this->assertSame(409, $e->getStatusCode());
            $this->assertSame(
                'Le patient doit être enregistré à l\'accueil avant la clôture du rendez-vous.',
                $e->getMessage(),
            );
        }
    }

    /**
     * Aucun chemin du portail ne peut aujourd'hui atteindre un `confirme` enregistré mais NON
     * réglé (le check-in exige déjà un reçu payé) — mais la garde est structurelle, pas supposée :
     * un RDV construit directement (contournant le flux normal, comme ici) doit rester refusé.
     */
    public function test_terminer_refuse_si_le_reglement_n_est_pas_verifie(): void
    {
        $service = $this->service($this->structure());
        $medecin = $this->medecinUser($service);
        $rdv = $this->rdv($service); // confirmé, enregistré, mais JAMAIS réglé

        try {
            $this->rdvs()->terminer($medecin, $rdv);
            $this->fail('Aurait dû être refusé (409).');
        } catch (HttpException $e) {
            $this->assertSame(409, $e->getStatusCode());
            $this->assertSame(
                'Le règlement de ce rendez-vous doit être vérifié avant sa clôture.',
                $e->getMessage(),
            );
        }
    }

    /**
     * Anti-IDOR : un médecin habilité (`rdv.validate`) mais d'un AUTRE service ne doit pas pouvoir
     * clore un RDV qui n'est pas du sien — 404 (anti-énumération), pas 403, même famille que
     * `RendezVousValidationService::assertPerimetre()` sur `previsalider()`/`confirmer()`/
     * `refuser()`. Exercé via la VRAIE route Blade (`assertPerimetre()` vit dans le CONTRÔLEUR, pas
     * dans `terminer()` lui-même — un appel direct au service ne l'aurait pas prouvé).
     */
    public function test_terminer_refuse_a_un_medecin_d_un_autre_service(): void
    {
        $structure = $this->structure();
        $serviceA = $this->service($structure);
        $serviceB = ServiceEtablissement::create([
            'structure_id' => $structure->id, 'nom_service' => 'ORL',
            'specialite' => 'orl', 'actif' => true, 'tarif_consultation_cfa' => 4000,
        ]);
        $medecinA = $this->medecinUser($serviceA);
        $rdvServiceB = $this->rdv($serviceB);
        $this->regler($rdvServiceB);

        $this->actingAs($medecinA, 'web')
            ->from("/portail/rendez-vous/{$rdvServiceB->id}")
            ->patch("/portail/rendez-vous/{$rdvServiceB->id}/terminer")
            ->assertNotFound();

        $this->assertSame('confirme', $rdvServiceB->fresh()->statut);
    }

    public function test_terminer_deux_fois_refuse_la_seconde(): void
    {
        $service = $this->service($this->structure());
        $medecin = $this->medecinUser($service);
        $rdv = $this->rdv($service);
        $this->regler($rdv);
        $this->rdvs()->terminer($medecin, $rdv->fresh());

        try {
            $this->rdvs()->terminer($medecin, $rdv->fresh());
            $this->fail('Aurait dû être refusé (409) : déjà honoré.');
        } catch (HttpException $e) {
            $this->assertSame(409, $e->getStatusCode());
        }
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // D15 — notification de clôture
    // ─────────────────────────────────────────────────────────────────────────────

    public function test_terminer_notifie_le_titulaire_avec_le_montant_deja_regle(): void
    {
        $service = $this->service($this->structure(), 5000);
        $medecin = $this->medecinUser($service);
        $titulaire = User::factory()->create();
        $membre = $this->membre($titulaire);
        $rdv = $this->rdv($service, membre: $membre);
        $this->regler($rdv);

        $this->rdvs()->terminer($medecin, $rdv->fresh());

        $notif = $titulaire->notifications()->where('type', TypeNotification::RENDEZ_VOUS_TERMINE->value)->first();
        $this->assertNotNull($notif);
        $this->assertSame('Votre rendez-vous est terminé · 5000 FCFA réglés.', $notif->data['corps']);
        $this->assertSame($rdv->id, $notif->data['rendez_vous_id']);
    }

    /**
     * `facturePatientEmise()` n'est PAS rejouée ici : la facture existait déjà avant la clôture
     * (elle a rendu le check-in possible), donc l'annoncer comme « nouvelle » serait faux. Une
     * seule notification `RENDEZ_VOUS_TERMINE` doit exister, jamais une seconde `FACTURE_PATIENT_EMISE`
     * déclenchée par ce chemin.
     */
    public function test_terminer_ne_reemet_pas_une_notification_de_facture_nouvelle(): void
    {
        $service = $this->service($this->structure());
        $medecin = $this->medecinUser($service);
        $titulaire = User::factory()->create();
        $rdv = $this->rdv($service, membre: $this->membre($titulaire));
        $this->regler($rdv); // payer() crée déjà la facture PAYEE, sans notification (statut ≠ A_REGLER)

        $this->rdvs()->terminer($medecin, $rdv->fresh());

        $this->assertSame(
            0,
            $titulaire->notifications()->where('type', TypeNotification::FACTURE_PATIENT_EMISE->value)->count(),
        );
        $this->assertSame(
            1,
            $titulaire->notifications()->where('type', TypeNotification::RENDEZ_VOUS_TERMINE->value)->count(),
        );
    }

    /** Aucun établissement, aucune spécialité, aucun acte — même garde-fou que la facturation (§2.7). */
    public function test_notification_de_cloture_ne_nomme_ni_etablissement_ni_specialite(): void
    {
        $service = $this->service($this->structure());
        $medecin = $this->medecinUser($service);
        $titulaire = User::factory()->create();
        $rdv = $this->rdv($service, membre: $this->membre($titulaire));
        $this->regler($rdv);

        $this->rdvs()->terminer($medecin, $rdv->fresh());

        $notif = $titulaire->notifications()->where('type', TypeNotification::RENDEZ_VOUS_TERMINE->value)->first();
        $this->assertStringNotContainsStringIgnoringCase('cocody', $notif->data['corps']);
        $this->assertStringNotContainsStringIgnoringCase('cardiologie', $notif->data['corps']);
    }

    /** Un ancien RDV réglé par le seul chemin legacy (sans `FacturePatient`) ne fait pas planter la notification. */
    public function test_notification_de_cloture_sans_facture_patient_reste_generique(): void
    {
        $service = $this->service($this->structure());
        $medecin = $this->medecinUser($service);
        $titulaire = User::factory()->create();
        $rdv = $this->rdv($service, membre: $this->membre($titulaire));

        // Chemin legacy : un `Paiement` payé, mais AUCUNE ligne `FacturePatient` (comme avant le
        // lot de reprise du flux RDV) — `estRegle()` doit rester vrai par son repli documenté.
        Paiement::create([
            'rendez_vous_id' => $rdv->id, 'montant' => 5000, 'mode' => 'especes',
            'statut' => 'paye', 'transaction_ref' => 'SIM-LEGACY',
        ]);

        $this->rdvs()->terminer($medecin, $rdv->fresh());

        $notif = $titulaire->notifications()->where('type', TypeNotification::RENDEZ_VOUS_TERMINE->value)->first();
        $this->assertSame('Votre rendez-vous est terminé.', $notif->data['corps']);
        $this->assertNull($notif->data['facture_patient_id']);
    }
}
