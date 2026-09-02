<?php

namespace Tests\Feature;

use App\Models\AbonnementStructure;
use App\Models\FacturePartenaire;
use App\Models\FacturePatient;
use App\Models\MembreFamille;
use App\Models\PlanTarifaire;
use App\Models\SpecialiteMedicale;
use App\Models\StructureSanitaire;
use App\Models\User;
use App\Services\RecouvrementPartenaireService;
use App\Services\ServiceNotification;
use App\Support\MomentPaiement;
use App\Support\StatutAbonnement;
use App\Support\StatutFacturePartenaire;
use App\Support\StatutFacturePatient;
use App\Support\TypeNotification;
use Database\Seeders\PortailRolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * Lot 9 — Notifications de facturation et confidentialité. Les 6 vecteurs nommés par le prompt.
 *
 * Aucun nouvel événement métier : ce lot déclenche des notifications à partir d'événements déjà
 * produits par les lots 1/5/8. Deux familles, deux régimes de contenu — vérifiés séparément :
 * PATIENT (montant + libellé générique, jamais d'établissement) vs BACK-OFFICE (peut nommer la
 * structure, jamais envoyé à un patient).
 */
class NotificationsFacturationTest extends TestCase
{
    use RefreshDatabase;

    private function structure(): StructureSanitaire
    {
        return StructureSanitaire::create([
            'nom' => 'Structure de test '.uniqid(), 'type' => 'chu', 'adresse' => 'Abidjan',
            'commune' => 'Cocody', 'latitude' => 5.35, 'longitude' => -3.98, 'actif' => true,
        ]);
    }

    private function membre(User $user, string $prenom = 'Awa'): MembreFamille
    {
        $membre = new MembreFamille([
            'nom' => 'Yao', 'prenom' => $prenom, 'date_naissance' => '2015-01-01', 'sexe' => 'F',
        ]);
        $membre->user_id = $user->id;
        $membre->matricule_ivs = 'IVS-2026-RC-'.uniqid();
        $membre->save();

        return $membre;
    }

    private function facturePatient(User $patient, array $overrides = []): FacturePatient
    {
        return FacturePatient::create(array_merge([
            'structure_sanitaire_id' => $this->structure()->id,
            'patient_id' => $patient->id,
            'reference' => 'FPA-'.uniqid(),
            'moment_paiement' => MomentPaiement::APRES_ACTE,
            'montant_brut' => 6000,
            'montant_pris_en_charge_cmu' => 0,
            'montant_reste_a_charge' => 6000,
            'statut' => StatutFacturePatient::A_REGLER,
            'paiement_en_ligne_autorise' => true,
            'date_emission' => now(),
        ], $overrides));
    }

    // ── 1. Contenu minimal, aucun champ interdit ────────────────────────────────────────────

    public function test_notification_facture_contenu_minimal(): void
    {
        $patient = User::factory()->create();
        $facture = $this->facturePatient($patient, ['montant_reste_a_charge' => 6000]);

        $notif = $patient->notifications()->where('type', TypeNotification::FACTURE_PATIENT_EMISE->value)->first();

        $this->assertNotNull($notif, 'La création en A_REGLER doit déclencher une notification.');
        $this->assertSame('Vous avez une nouvelle facture · 6000 FCFA', $notif->data['corps']);
        $this->assertSame($facture->id, $notif->data['facture_patient_id']);
    }

    // ── 2. Bénéficiaire différent du titulaire ─────────────────────────────────────────────

    public function test_notification_beneficiaire_different_du_titulaire(): void
    {
        $titulaire = User::factory()->create();
        $enfant = $this->membre($titulaire, 'Koffi');

        $this->facturePatient($titulaire, [
            'beneficiaire_id' => $enfant->id, 'montant_reste_a_charge' => 4500,
        ]);

        $notif = $titulaire->notifications()->where('type', TypeNotification::FACTURE_PATIENT_EMISE->value)->first();

        $this->assertSame('Facture pour Koffi · 4500 FCFA', $notif->data['corps']);
    }

    // ── 3. Une seule relance, jamais deux ───────────────────────────────────────────────────

    public function test_relance_unique_seconde_tentative_bloquee(): void
    {
        $patient = User::factory()->create();
        $facture = $this->facturePatient($patient, ['date_echeance' => now()->subDays(5)->toDateString()]);

        $premier = app(ServiceNotification::class)->relancerFacturesEnRetard();
        $this->assertSame(1, $premier);
        $this->assertNotNull($facture->fresh()->relance_envoyee_le);

        $second = app(ServiceNotification::class)->relancerFacturesEnRetard();
        $this->assertSame(0, $second, 'Une facture déjà relancée ne doit plus jamais l\'être.');

        $this->assertSame(
            1,
            $patient->notifications()->where('type', TypeNotification::FACTURE_PATIENT_RELANCE->value)->count()
        );
    }

    // ── 4. La bascule Palier 0 notifie le back-office, jamais le patient ───────────────────

    public function test_bascule_palier0_notifie_le_backoffice_pas_le_patient(): void
    {
        $this->seed(PortailRolesSeeder::class);

        $s = $this->structure();
        $plan = PlanTarifaire::create([
            'code' => 'TEST_PLAN', 'libelle' => 'Plan de test', 'montant_mensuel' => 15000,
            'devise' => 'XOF', 'commission_incluse' => false, 'actif' => true,
            'date_effet' => now()->subYear()->toDateString(),
        ]);
        AbonnementStructure::create([
            'structure_sanitaire_id' => $s->id, 'plan_tarifaire_id' => $plan->id, 'rang_signature' => 1,
            'date_debut' => now()->subMonths(2)->toDateString(), 'date_fin_essai' => now()->subMonths(1)->toDateString(),
            'statut' => StatutAbonnement::ACTIF,
        ]);
        FacturePartenaire::create([
            'structure_sanitaire_id' => $s->id, 'reference' => 'FP-'.uniqid(),
            'periode_debut' => now()->subMonths(2)->startOfMonth()->toDateString(),
            'periode_fin' => now()->subMonths(2)->endOfMonth()->toDateString(),
            'montant_abonnement' => 15000, 'montant_commissions' => 0, 'montant_total' => 15000,
            'montant_regle' => 0, 'statut' => StatutFacturePartenaire::EMISE,
            'date_emission' => now()->subMonths(2)->toDateString(),
            'date_echeance' => now()->subDays(45)->toDateString(),
        ]);

        $backOffice = User::factory()->create();
        $backOffice->assignRole('admin_ivoirsante');

        $gestionnaire = User::factory()->create(['structure_id' => $s->id]);
        $gestionnaire->assignRole('gestionnaire_etablissement');

        app(RecouvrementPartenaireService::class)->verifierEcheances();

        $this->assertSame(
            1,
            $backOffice->notifications()->where('type', TypeNotification::STRUCTURE_SUSPENDUE_IMPAYE->value)->count()
        );
        $this->assertSame(0, $gestionnaire->notifications()->count(), 'Le patient/établissement ne doit jamais recevoir cette alerte.');
    }

    // ── 5. Le garde-fou de contenu bloque un libellé interdit ──────────────────────────────

    public function test_garde_fou_contenu_bloque_un_libelle_interdit(): void
    {
        // `code` est volontairement hors `$fillable` (P6.8a — un client ne choisit pas la clé
        // d'un terme de nomenclature nationale) : posé directement, comme le fait le seeder réel.
        $specialite = new SpecialiteMedicale([
            'libelle' => 'Cardiologie', 'nature' => 'specialite_medicale', 'ordre' => 1, 'actif' => true,
        ]);
        $specialite->code = 'cardiologie_test';
        $specialite->save();

        $titulaire = User::factory()->create();
        // Injection volontaire : le prénom du bénéficiaire coïncide avec un libellé de spécialité
        // réel — le seul point d'interpolation libre du corps de ce type de notification.
        $enfant = $this->membre($titulaire, 'Cardiologie');

        $facture = FacturePatient::create([
            'structure_sanitaire_id' => $this->structure()->id,
            'patient_id' => $titulaire->id,
            'beneficiaire_id' => $enfant->id,
            'reference' => 'FPA-'.uniqid(),
            'moment_paiement' => MomentPaiement::APRES_ACTE,
            'montant_brut' => 6000,
            'montant_pris_en_charge_cmu' => 0,
            'montant_reste_a_charge' => 6000,
            'statut' => StatutFacturePatient::PAYEE, // évite le crochet auto (created), on teste le garde-fou directement
            'paiement_en_ligne_autorise' => true,
            'date_emission' => now(),
            'date_reglement' => now(),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/libellé interdit/');

        app(ServiceNotification::class)->facturePatientEmise($facture);
    }

    // ── 6. La réactivation notifie le back-office ───────────────────────────────────────────

    public function test_reactivation_notifie_le_backoffice(): void
    {
        $this->seed(PortailRolesSeeder::class);

        $s = $this->structure();
        $plan = PlanTarifaire::create([
            'code' => 'TEST_PLAN', 'libelle' => 'Plan de test', 'montant_mensuel' => 15000,
            'devise' => 'XOF', 'commission_incluse' => false, 'actif' => true,
            'date_effet' => now()->subYear()->toDateString(),
        ]);
        AbonnementStructure::create([
            'structure_sanitaire_id' => $s->id, 'plan_tarifaire_id' => $plan->id, 'rang_signature' => 1,
            'date_debut' => now()->subMonths(2)->toDateString(), 'date_fin_essai' => now()->subMonths(1)->toDateString(),
            'statut' => StatutAbonnement::SUSPENDU,
            'motif_suspension' => \App\Support\MotifSuspension::IMPAYE,
            'date_bascule_palier0' => now()->subDays(5),
        ]);

        $backOffice = User::factory()->create();
        $backOffice->assignRole('admin_ivoirsante');

        app(RecouvrementPartenaireService::class)->reactiver($s->id);

        $this->assertSame(
            1,
            $backOffice->notifications()->where('type', TypeNotification::STRUCTURE_REACTIVEE->value)->count()
        );
    }
}
