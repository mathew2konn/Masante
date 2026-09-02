<?php

namespace Tests\Feature;

use App\Models\AbonnementStructure;
use App\Models\FacturePartenaire;
use App\Models\PlanTarifaire;
use App\Models\ReglementFacturePartenaire;
use App\Models\StructureSanitaire;
use App\Services\RecouvrementPartenaireService;
use App\Support\MotifSuspension;
use App\Support\MoyenReglement;
use App\Support\StatutAbonnement;
use App\Support\StatutFacturePartenaire;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Tests\TestCase;

/**
 * Lot 1 — `RecouvrementPartenaireService` : imputation des règlements et bascule au Palier 0.
 * Cahier des charges : docs/REGLES_RECOUVREMENT_PARTENAIRE.md. Les 9 vecteurs nommés par le prompt.
 */
class RecouvrementPartenaireTest extends TestCase
{
    use RefreshDatabase;

    private function service(): RecouvrementPartenaireService
    {
        return app(RecouvrementPartenaireService::class);
    }

    private function structure(): StructureSanitaire
    {
        return StructureSanitaire::create([
            'nom' => 'Structure de test '.uniqid(),
            'type' => 'chu',
            'adresse' => 'Abidjan',
            'commune' => 'Cocody',
            'latitude' => 5.35,
            'longitude' => -3.98,
            'actif' => true,
        ]);
    }

    private function plan(): PlanTarifaire
    {
        return PlanTarifaire::create([
            'code' => 'TEST_PLAN',
            'libelle' => 'Plan de test',
            'montant_mensuel' => 15000,
            'devise' => 'XOF',
            'commission_incluse' => false,
            'actif' => true,
            'date_effet' => now()->toDateString(),
        ]);
    }

    private function abonnement(StructureSanitaire $s, array $overrides = []): AbonnementStructure
    {
        return AbonnementStructure::create(array_merge([
            'structure_sanitaire_id' => $s->id,
            'plan_tarifaire_id' => $this->plan()->id,
            'rang_signature' => 1,
            'date_debut' => now()->subMonths(2)->toDateString(),
            'date_fin_essai' => now()->subMonths(1)->toDateString(),
            'statut' => StatutAbonnement::ACTIF,
        ], $overrides));
    }

    /**
     * Compteur de période, pour que deux factures créées pour la MÊME structure dans un seul test
     * ne se percutent jamais sur `uq_facture_partenaire_periode` (structure, periode_debut,
     * periode_fin) — chaque appel prend un mois distinct, sans que l'appelant ait à le préciser.
     */
    private static int $compteurPeriode = 0;

    private function facture(StructureSanitaire $s, array $overrides = []): FacturePartenaire
    {
        self::$compteurPeriode++;
        // On se place au PREMIER du mois AVANT de reculer, jamais l'inverse.
        //
        // Défaut réel, latent et daté : PHP fait DÉBORDER l'arithmétique des mois au lieu de la
        // borner. Depuis le 31 août, `-2 mois` donne le 31 juin, qui n'existe pas et se normalise
        // en **1er juillet** — soit exactement le même mois que `-1 mois`. Les deux factures de ce
        // jeu tombaient alors sur la même période et violaient l'unicité.
        //
        // Ce test était vert le 30 août et rouge le 31, **sans qu'une ligne de code ait changé**.
        // Reculer depuis le 1er du mois ne déborde jamais.
        $debut = now()->startOfMonth()->subMonths(self::$compteurPeriode);

        return FacturePartenaire::create(array_merge([
            'structure_sanitaire_id' => $s->id,
            'reference' => 'FP-'.uniqid(),
            'periode_debut' => $debut->toDateString(),
            'periode_fin' => $debut->copy()->endOfMonth()->toDateString(),
            'montant_abonnement' => 15000,
            'montant_commissions' => 0,
            'montant_total' => 15000,
            'montant_regle' => 0,
            'statut' => StatutFacturePartenaire::EMISE,
            'date_emission' => $debut->copy()->addMonth()->toDateString(),
            'date_echeance' => now()->subDays(45)->toDateString(),
        ], $overrides));
    }

    // ── 1. Imputation sur la facture la plus ancienne ──────────────────────────────────────

    public function test_imputation_sur_facture_la_plus_ancienne(): void
    {
        $s = $this->structure();
        $ancienne = $this->facture($s, ['date_echeance' => now()->subDays(20)->toDateString()]);
        $recente = $this->facture($s, ['date_echeance' => now()->subDays(5)->toDateString()]);

        $this->service()->enregistrerReglement($s->id, 10000, MoyenReglement::WAVE->value, null, now());

        $this->assertSame(10000, $ancienne->fresh()->montant_regle, 'La plus ancienne doit décroître en premier.');
        $this->assertSame(0, $recente->fresh()->montant_regle, 'La plus récente ne doit pas encore être touchée.');
    }

    // ── 2. Report de l'excédent sur la facture suivante ────────────────────────────────────

    public function test_report_excedent_sur_facture_suivante(): void
    {
        $s = $this->structure();
        $ancienne = $this->facture($s, ['montant_total' => 15000, 'date_echeance' => now()->subDays(20)->toDateString()]);
        $suivante = $this->facture($s, ['montant_total' => 15000, 'date_echeance' => now()->subDays(5)->toDateString()]);

        $detail = $this->service()->enregistrerReglement($s->id, 20000, MoyenReglement::WAVE->value, null, now());

        $this->assertSame(15000, $ancienne->fresh()->montant_regle);
        $this->assertSame(StatutFacturePartenaire::PAYEE, $ancienne->fresh()->statut);
        $this->assertSame(5000, $suivante->fresh()->montant_regle, 'Le reliquat de 5000 doit être reporté.');
        $this->assertSame(0, $detail['excedent_non_impute']);
        $this->assertCount(2, $detail['imputations']);
    }

    // ── 3. Excédent au-delà du dû : journalisé, jamais stocké ──────────────────────────────

    public function test_excedent_au_dela_du_du_est_journalise_sans_etre_stocke(): void
    {
        Log::spy();

        $s = $this->structure();
        $facture = $this->facture($s, ['montant_total' => 15000]);

        $detail = $this->service()->enregistrerReglement($s->id, 25000, MoyenReglement::WAVE->value, null, now());

        $this->assertSame(15000, $facture->fresh()->montant_regle, 'Jamais au-delà du total dû.');
        $this->assertSame(StatutFacturePartenaire::PAYEE, $facture->fresh()->statut);
        $this->assertSame(10000, $detail['excedent_non_impute']);
        $this->assertSame(1, ReglementFacturePartenaire::count(), 'Aucune ligne fictive pour l\'excédent.');
        $this->assertSame(15000, (int) ReglementFacturePartenaire::sum('montant'));

        Log::shouldHaveReceived('warning')->once()->withArgs(
            fn (string $message, array $contexte) => $contexte['montant_excedent'] === 10000
                && $contexte['structure_sanitaire_id'] === $s->id
        );
    }

    // ── 4. Bascule au Palier 0 à J+30, jamais avant ────────────────────────────────────────

    public function test_solde_impaye_bascule_palier0_a_30_jours_pas_avant(): void
    {
        // Deux structures indépendantes plutôt qu'une seule dont on ferait « avancer le temps » en
        // réécrivant `date_echeance` : ce champ est figé dès l'émission par le garde-fou du modèle
        // (`FacturePartenaire::MODIFIABLES_APRES_EMISSION`) — le contourner ici contredirait
        // exactement la garantie que ce lot doit respecter.
        $pasEncore = $this->structure();
        $this->abonnement($pasEncore, ['statut' => StatutAbonnement::ACTIF]);
        $factureJ29 = $this->facture($pasEncore, ['date_echeance' => now()->subDays(29)->toDateString()]);

        $bascule = $this->structure();
        $this->abonnement($bascule, ['statut' => StatutAbonnement::ACTIF]);
        $factureJ30 = $this->facture($bascule, ['date_echeance' => now()->subDays(30)->toDateString()]);

        $this->service()->verifierEcheances();

        $this->assertSame(StatutFacturePartenaire::EMISE, $factureJ29->fresh()->statut, 'À J+29, rien ne bouge.');
        $this->assertSame(
            StatutAbonnement::ACTIF,
            AbonnementStructure::where('structure_sanitaire_id', $pasEncore->id)->first()->statut
        );

        $this->assertSame(StatutFacturePartenaire::IMPAYEE, $factureJ30->fresh()->statut, 'À J+30, la facture bascule.');
        $abonnementBascule = AbonnementStructure::where('structure_sanitaire_id', $bascule->id)->first();
        $this->assertSame(StatutAbonnement::SUSPENDU, $abonnementBascule->statut);
        $this->assertSame(MotifSuspension::IMPAYE, $abonnementBascule->motif_suspension);
        $this->assertNotNull($abonnementBascule->date_bascule_palier0);
    }

    // ── 5. La bascule n'écrit jamais sur `actif` ───────────────────────────────────────────

    public function test_bascule_n_ecrit_jamais_sur_actif(): void
    {
        $s = $this->structure();
        $this->abonnement($s, ['statut' => StatutAbonnement::ACTIF]);
        $this->facture($s, ['date_echeance' => now()->subDays(30)->toDateString()]);

        $this->assertTrue($s->fresh()->actif);

        $this->service()->verifierEcheances();

        $this->assertTrue($s->fresh()->actif, 'Une bascule au Palier 0 ne touche jamais actif.');
    }

    // ── 6. Un règlement partiel ne réactive pas ────────────────────────────────────────────

    public function test_reglement_partiel_ne_reactive_pas(): void
    {
        $s = $this->structure();
        $this->abonnement($s, [
            'statut' => StatutAbonnement::SUSPENDU,
            'motif_suspension' => MotifSuspension::IMPAYE,
            'date_bascule_palier0' => now()->subDays(1),
        ]);
        $ancienne = $this->facture($s, ['montant_total' => 15000, 'statut' => StatutFacturePartenaire::IMPAYEE, 'date_echeance' => now()->subDays(40)->toDateString()]);
        $this->facture($s, ['montant_total' => 15000, 'statut' => StatutFacturePartenaire::IMPAYEE, 'date_echeance' => now()->subDays(35)->toDateString()]);

        // Ne règle que la première des deux factures dues : le solde global reste positif.
        $this->service()->enregistrerReglement($s->id, 15000, MoyenReglement::WAVE->value, null, now());

        $this->assertSame(StatutFacturePartenaire::PAYEE, $ancienne->fresh()->statut);
        $this->assertSame(
            StatutAbonnement::SUSPENDU,
            AbonnementStructure::where('structure_sanitaire_id', $s->id)->first()->statut,
            'Un solde encore positif ailleurs ne doit jamais réactiver.'
        );
    }

    // ── 7. La réactivation efface le motif mais conserve la date de bascule ───────────────

    public function test_reactivation_efface_motif_mais_conserve_date_bascule(): void
    {
        $dateBascule = now()->subDays(10);

        $s = $this->structure();
        $this->abonnement($s, [
            'statut' => StatutAbonnement::SUSPENDU,
            'motif_suspension' => MotifSuspension::IMPAYE,
            'date_bascule_palier0' => $dateBascule,
        ]);
        $this->facture($s, ['montant_total' => 15000, 'statut' => StatutFacturePartenaire::IMPAYEE, 'date_echeance' => now()->subDays(40)->toDateString()]);

        $this->service()->enregistrerReglement($s->id, 15000, MoyenReglement::WAVE->value, null, now());

        $abonnement = AbonnementStructure::where('structure_sanitaire_id', $s->id)->first();

        $this->assertSame(StatutAbonnement::ACTIF, $abonnement->statut);
        $this->assertNull($abonnement->motif_suspension, 'motif_suspension doit être effacé.');
        $this->assertNotNull($abonnement->date_bascule_palier0, 'date_bascule_palier0 ne doit JAMAIS être effacée.');
        $this->assertEquals($dateBascule->toDateTimeString(), $abonnement->date_bascule_palier0->toDateTimeString());
    }

    // ── 8. Le règlement produit par le service reste immuable ─────────────────────────────

    public function test_reglement_immuable_apres_imputation(): void
    {
        $s = $this->structure();
        $this->facture($s, ['montant_total' => 15000]);

        $this->service()->enregistrerReglement($s->id, 5000, MoyenReglement::WAVE->value, 'REF-TEST', now());

        $reglement = ReglementFacturePartenaire::sole();

        $this->expectException(RuntimeException::class);
        $reglement->update(['montant' => 1]);
    }

    // ── 9. Simulation de concurrence : aucun double comptage ──────────────────────────────

    /**
     * Une vraie concurrence multi-processus se prouve sur MySQL réel (précédent du projet, hors
     * de la portée d'un test Feature sur SQLite en mémoire). Ce que ce test vérifie : l'invariant
     * que `lockForUpdate()` existe pour garantir — deux appels imputés sur la même structure ne
     * produisent ni double comptage, ni double réactivation, ni ligne perdue — en les enchaînant
     * volontairement sur le même solde partagé, comme le ferait une file d'attente sérialisée par
     * le verrou en production.
     */
    public function test_verrou_concurrentiel(): void
    {
        $s = $this->structure();
        $this->abonnement($s, [
            'statut' => StatutAbonnement::SUSPENDU,
            'motif_suspension' => MotifSuspension::IMPAYE,
            'date_bascule_palier0' => now()->subDays(5),
        ]);
        $facture = $this->facture($s, ['montant_total' => 20000, 'statut' => StatutFacturePartenaire::IMPAYEE, 'date_echeance' => now()->subDays(40)->toDateString()]);

        $premier = $this->service()->enregistrerReglement($s->id, 10000, MoyenReglement::WAVE->value, 'REF-A', now());
        $abonnementApresPremier = AbonnementStructure::where('structure_sanitaire_id', $s->id)->first();

        $second = $this->service()->enregistrerReglement($s->id, 10000, MoyenReglement::ORANGE_MONEY->value, 'REF-B', now());
        $abonnementApresSecond = AbonnementStructure::where('structure_sanitaire_id', $s->id)->first();

        $this->assertSame(20000, $facture->fresh()->montant_regle, 'Ni perdu, ni compté deux fois.');
        $this->assertSame(StatutFacturePartenaire::PAYEE, $facture->fresh()->statut);
        $this->assertSame(2, ReglementFacturePartenaire::count());
        $this->assertSame(0, $premier['excedent_non_impute']);
        $this->assertSame(0, $second['excedent_non_impute']);

        $this->assertSame(StatutAbonnement::SUSPENDU, $abonnementApresPremier->statut, 'Solde encore positif après le premier appel.');
        $this->assertSame(StatutAbonnement::ACTIF, $abonnementApresSecond->statut, 'Réactivée une fois, exactement.');
    }
}
