<?php

namespace Tests\Feature;

use App\Models\AbonnementStructure;
use App\Models\BaremeCommission;
use App\Models\CommissionTransaction;
use App\Models\FacturePartenaire;
use App\Models\FacturePatient;
use App\Models\PlanTarifaire;
use App\Models\ReglementFacturePartenaire;
use App\Models\StructureSanitaire;
use App\Models\User;
use App\Support\MomentPaiement;
use App\Support\MoyenReglement;
use App\Support\StatutAbonnement;
use App\Support\StatutCommission;
use App\Support\StatutFacturePartenaire;
use App\Support\StatutFacturePatient;
use Database\Seeders\BaremesCommissionSeeder;
use Database\Seeders\PlansTarifairesSeeder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

/**
 * Facturation MaSanté (lot Prompt_ClaudeCode_Tables_Facturation v2.1) — Phase 3.
 *
 * Couvre les huit tables (partenaire + patient), leurs garde-fous d'immutabilité et les deux
 * seeders. Ne teste AUCUNE logique d'imputation ni de bascule de palier : ce lot ne contient
 * aucun service, l'interdiction n°9 du prompt l'exclut délibérément — ces vecteurs viendront avec
 * le service, hors de ce lot.
 */
class FacturationTest extends TestCase
{
    use RefreshDatabase;

    private const TABLES_FACTURATION = [
        'plans_tarifaires',
        'abonnements_structure',
        'baremes_commission',
        'factures_partenaire',
        'reglements_facture_partenaire',
        'factures_patient',
        'lignes_facture_patient',
        'commissions_transaction',
    ];

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

    private function plan(array $overrides = []): PlanTarifaire
    {
        return PlanTarifaire::create(array_merge([
            'code' => 'TEST_PLAN',
            'libelle' => 'Plan de test',
            'montant_mensuel' => 15000,
            'devise' => 'XOF',
            'commission_incluse' => false,
            'actif' => true,
            'date_effet' => now()->toDateString(),
        ], $overrides));
    }

    private function factureEmise(StructureSanitaire $s, array $overrides = []): FacturePartenaire
    {
        return FacturePartenaire::create(array_merge([
            'structure_sanitaire_id' => $s->id,
            'reference' => 'FP-'.uniqid(),
            'periode_debut' => '2026-08-01',
            'periode_fin' => '2026-08-31',
            'montant_abonnement' => 15000,
            'montant_commissions' => 0,
            'montant_total' => 15000,
            'statut' => StatutFacturePartenaire::EMISE,
            'date_emission' => '2026-09-01',
        ], $overrides));
    }

    private function facturePatient(array $overrides = []): FacturePatient
    {
        $s = $overrides['structure_sanitaire_id'] ?? $this->structure()->id;
        $u = User::factory()->create();

        return FacturePatient::create(array_merge([
            'structure_sanitaire_id' => $s,
            'patient_id' => $u->id,
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

    // ── 1. Égalité du reçu transparent ─────────────────────────────────────────────────────

    public function test_montants_commission_equilibres(): void
    {
        $s = $this->structure();
        $c = CommissionTransaction::create([
            'structure_sanitaire_id' => $s->id,
            'reference_interne_paiement' => 'MS-'.uniqid(),
            'montant_brut' => 6000,
            'frais_passerelle' => 50,
            'frais_prestataire' => 160,
            'taux_bps_applique' => 250,
            'volume_cumule_au_calcul' => 6000,
            'montant_commission' => 150,
            'montant_net_structure' => 5640,
            'statut' => StatutCommission::CALCULEE,
            'date_transaction' => now(),
        ]);

        $this->assertSame(
            $c->montant_brut,
            $c->frais_passerelle + $c->frais_prestataire + $c->montant_commission + $c->montant_net_structure
        );
    }

    // ── 2. Aucun flottant — borné aux 8 tables de ce lot ───────────────────────────────────

    /**
     * Bornée aux HUIT tables de ce lot, délibérément. Le reste du schéma porte 19 colonnes
     * `decimal` légitimes (coordonnées GPS, note moyenne d'un établissement, seuils cliniques
     * de `referentiels_mesure`, bornes de `analyse_references`, `mesures_sante.valeur`,
     * `triage_constantes.valeur`) — ce sont des mesures, pas de l'argent, et elles ont
     * parfaitement le droit d'avoir des décimales. Élargir ce test au schéma entier le rendrait
     * rouge dès son premier lancement, pour une raison qui n'a rien à voir avec la facturation.
     * Si un jour quelqu'un « généralise » ce test, qu'il lise ce commentaire avant.
     */
    public function test_aucun_flottant_dans_les_tables_de_facturation(): void
    {
        $flottants = [];

        foreach (self::TABLES_FACTURATION as $table) {
            foreach (Schema::getColumns($table) as $colonne) {
                if (in_array($colonne['type_name'], ['float', 'double', 'decimal', 'real', 'numeric'], true)) {
                    $flottants[] = $table.'.'.$colonne['name'].' ('.$colonne['type_name'].')';
                }
            }
        }

        $this->assertEmpty($flottants, 'Colonnes en virgule flottante trouvées : '.implode(', ', $flottants));
    }

    // ── 3. Facture patient payée non modifiable ────────────────────────────────────────────

    public function test_facture_patient_payee_non_modifiable(): void
    {
        $facture = $this->facturePatient(['statut' => StatutFacturePatient::PAYEE, 'date_reglement' => now()]);

        $this->expectException(RuntimeException::class);
        $facture->update(['montant_brut' => 1]);
    }

    // ── 4. Plancher de paiement en ligne (R17) ─────────────────────────────────────────────

    public function test_plancher_paiement_en_ligne(): void
    {
        // R17 est une règle de SERVICE (montant < plancher => paiement_en_ligne_autorise=false),
        // qui n'existe pas dans ce lot. Ce test vérifie que la COLONNE peut porter la valeur que
        // ce service produira, dans les deux sens — l'assertion positive n'a de valeur que si
        // l'assertion inverse existe aussi.
        $sous_plancher = $this->facturePatient(['montant_brut' => 3000, 'paiement_en_ligne_autorise' => false]);
        $au_dessus = $this->facturePatient(['montant_brut' => 15000, 'paiement_en_ligne_autorise' => true]);

        $this->assertFalse($sous_plancher->fresh()->paiement_en_ligne_autorise);
        $this->assertTrue($au_dessus->fresh()->paiement_en_ligne_autorise);
    }

    // ── 5. Sélection de barème par volume, aux bornes exactes ──────────────────────────────

    public function test_bareme_selectionne_selon_volume(): void
    {
        $this->seed(BaremesCommissionSeeder::class);

        $cas = [
            [0, 1],
            [250000, 1],
            [250001, 2],
            [1000000, 2],
            [1000001, 3],
            [3000000, 3],
            [3000001, 4],
            [50000000, 4],
        ];

        foreach ($cas as [$volume, $palierAttendu]) {
            $palier = BaremeCommission::where('volume_mensuel_min', '<=', $volume)
                ->where(function ($q) use ($volume) {
                    $q->whereNull('volume_mensuel_max')->orWhere('volume_mensuel_max', '>=', $volume);
                })
                ->whereNull('date_fin')
                ->orderByDesc('volume_mensuel_min')
                ->first();

            $this->assertNotNull($palier, "Aucun palier trouvé pour le volume {$volume}");
            $this->assertSame($palierAttendu, $palier->palier_ordre, "Volume {$volume} : palier attendu {$palierAttendu}");
        }
    }

    // ── 6. Pharmacie hors ligne sans commission ────────────────────────────────────────────

    public function test_pharmacie_sans_commission_hors_ligne(): void
    {
        // Une facture patient réglée hors ligne (sur place) : rien dans ce lot ne produit de
        // ligne de commission tant qu'aucune transaction de passerelle n'a eu lieu. On le
        // vérifie négativement — aucune commission ne doit exister pour cette facture.
        $facture = $this->facturePatient([
            'statut' => StatutFacturePatient::PAYEE,
            'date_reglement' => now(),
            'paiement_en_ligne_autorise' => false,
        ]);

        $this->assertSame(0, CommissionTransaction::where('facture_patient_id', $facture->id)->count());
    }

    // ── 7. Relance unique ───────────────────────────────────────────────────────────────────

    public function test_relance_unique(): void
    {
        // R18 (« une seule relance ») est une règle de SERVICE. Ce lot ne l'implémente pas ; ce
        // test protège le GARDE-FOU dont ce service aura besoin : l'horodatage, une fois posé,
        // ne doit jamais silencieusement repartir à null par un simple update partiel.
        $facture = $this->facturePatient(['relance_envoyee_le' => now()]);
        $horodatageInitial = $facture->relance_envoyee_le;

        $facture->update(['montant_reste_a_charge' => 5000]);

        $this->assertEquals($horodatageInitial, $facture->fresh()->relance_envoyee_le);
    }

    // ── 8. Durée d'essai par défaut, indépendante du rang de signature ────────────────────

    public function test_duree_essai_par_defaut_30_jours(): void
    {
        $s = $this->structure();
        $plan = $this->plan();

        foreach ([1, 15, 20, 21, 500] as $rang) {
            $abonnement = AbonnementStructure::create([
                'structure_sanitaire_id' => $s->id,
                'plan_tarifaire_id' => $plan->id,
                'rang_signature' => $rang,
                'date_debut' => now()->toDateString(),
                'date_fin_essai' => now()->addDays(30)->toDateString(),
                'statut' => StatutAbonnement::ESSAI,
            ]);

            $this->assertSame(
                30,
                $abonnement->fresh()->duree_essai_jours,
                "rang_signature={$rang} : la durée d'essai doit rester 30, quel que soit le rang"
            );
        }
    }

    /**
     * Le test doit échouer si 90 réapparaît quelque part (checklist finale). On l'éprouve en
     * SENS INVERSE : si un jour une migration réintroduit un défaut à 90, ce test doit rougir.
     */
    public function test_duree_essai_par_defaut_nest_jamais_90(): void
    {
        $abonnement = AbonnementStructure::create([
            'structure_sanitaire_id' => $this->structure()->id,
            'plan_tarifaire_id' => $this->plan()->id,
            'rang_signature' => 1,
            'date_debut' => now()->toDateString(),
            'date_fin_essai' => now()->addDays(30)->toDateString(),
            'statut' => StatutAbonnement::ESSAI,
        ]);

        $this->assertNotSame(90, $abonnement->fresh()->duree_essai_jours);
    }

    // ── 9. Solde dérivé, aucune colonne stockée ────────────────────────────────────────────

    public function test_solde_derive_du_total_et_du_regle(): void
    {
        $facture = $this->factureEmise($this->structure(), [
            'montant_total' => 20000,
            'montant_regle' => 7500,
            'statut' => StatutFacturePartenaire::PARTIELLEMENT_REGLEE,
        ]);

        $this->assertSame(12500, $facture->solde);
        $this->assertFalse($facture->est_soldee);
        $this->assertNotContains('solde', Schema::getColumnListing('factures_partenaire'));
    }

    // ── 10. montant_regle == somme des règlements ──────────────────────────────────────────

    public function test_montant_regle_egale_somme_des_reglements(): void
    {
        $facture = $this->factureEmise($this->structure(), ['montant_total' => 30000]);

        ReglementFacturePartenaire::create([
            'facture_partenaire_id' => $facture->id, 'montant' => 10000,
            'moyen' => MoyenReglement::WAVE, 'date_reglement' => now(),
        ]);
        ReglementFacturePartenaire::create([
            'facture_partenaire_id' => $facture->id, 'montant' => 5000,
            'moyen' => MoyenReglement::VIREMENT, 'date_reglement' => now(),
        ]);
        $facture->update(['montant_regle' => 15000, 'statut' => StatutFacturePartenaire::PARTIELLEMENT_REGLEE]);

        $sommeLignes = ReglementFacturePartenaire::where('facture_partenaire_id', $facture->id)->sum('montant');

        $this->assertSame(15000, $sommeLignes);
        $this->assertSame($sommeLignes, $facture->fresh()->montant_regle);
    }

    // ── 11. Règlement immuable ──────────────────────────────────────────────────────────────

    public function test_reglement_immuable_en_modification(): void
    {
        $reglement = ReglementFacturePartenaire::create([
            'facture_partenaire_id' => $this->factureEmise($this->structure())->id,
            'montant' => 5000, 'moyen' => MoyenReglement::ESPECES, 'date_reglement' => now(),
        ]);

        $this->expectException(RuntimeException::class);
        $reglement->update(['montant' => 1]);
    }

    public function test_reglement_immuable_en_suppression(): void
    {
        $reglement = ReglementFacturePartenaire::create([
            'facture_partenaire_id' => $this->factureEmise($this->structure())->id,
            'montant' => 5000, 'moyen' => MoyenReglement::ESPECES, 'date_reglement' => now(),
        ]);

        $this->expectException(RuntimeException::class);
        $reglement->delete();
    }

    // ── 12. Montants figés dès émission, montant_regle libre ──────────────────────────────

    public function test_montants_factures_figes_des_emission(): void
    {
        $facture = $this->factureEmise($this->structure(), ['montant_total' => 15000]);

        try {
            $facture->update(['montant_total' => 99000]);
            $this->fail('La modification de montant_total aurait dû être refusée.');
        } catch (RuntimeException) {
            $facture->refresh();
        }

        $facture->update(['montant_regle' => 5000]);

        $this->assertSame(15000, $facture->fresh()->montant_total);
        $this->assertSame(5000, $facture->fresh()->montant_regle);
    }

    // ── 13. Facture partenaire payée non modifiable ────────────────────────────────────────

    public function test_facture_partenaire_payee_non_modifiable(): void
    {
        $facture = $this->factureEmise($this->structure(), [
            'montant_total' => 15000, 'montant_regle' => 15000,
            'statut' => StatutFacturePartenaire::PAYEE, 'date_paiement' => now()->toDateString(),
        ]);

        $this->expectException(RuntimeException::class);
        $facture->update(['montant_regle' => 1]);
    }

    // ── 14. Commission facturée pointe une facture ─────────────────────────────────────────

    public function test_commission_facturee_pointe_une_facture(): void
    {
        $s = $this->structure();
        $facturePartenaire = $this->factureEmise($s);

        $commission = CommissionTransaction::create([
            'structure_sanitaire_id' => $s->id,
            'reference_interne_paiement' => 'MS-'.uniqid(),
            'montant_brut' => 1000, 'frais_passerelle' => 0, 'frais_prestataire' => 0,
            'taux_bps_applique' => 250, 'volume_cumule_au_calcul' => 1000,
            'montant_commission' => 25, 'montant_net_structure' => 975,
            'statut' => StatutCommission::CALCULEE, 'date_transaction' => now(),
        ]);
        $commission->update(['statut' => StatutCommission::FACTUREE, 'facture_partenaire_id' => $facturePartenaire->id]);

        $this->assertSame(StatutCommission::FACTUREE, $commission->fresh()->statut);
        $this->assertNotNull($commission->fresh()->facture_partenaire_id);
    }

    // ── 15. reference_interne_paiement unique (idempotence de la notification Java) ───────

    public function test_reference_interne_paiement_unique(): void
    {
        $s = $this->structure();
        CommissionTransaction::create([
            'structure_sanitaire_id' => $s->id,
            'reference_interne_paiement' => 'MS-DOUBLON-0001',
            'montant_brut' => 1000, 'taux_bps_applique' => 250, 'volume_cumule_au_calcul' => 1000,
            'montant_commission' => 25, 'montant_net_structure' => 975,
            'statut' => StatutCommission::CALCULEE, 'date_transaction' => now(),
        ]);

        $this->expectException(UniqueConstraintViolationException::class);
        CommissionTransaction::create([
            'structure_sanitaire_id' => $s->id,
            'reference_interne_paiement' => 'MS-DOUBLON-0001',
            'montant_brut' => 2000, 'taux_bps_applique' => 250, 'volume_cumule_au_calcul' => 2000,
            'montant_commission' => 50, 'montant_net_structure' => 1950,
            'statut' => StatutCommission::CALCULEE, 'date_transaction' => now(),
        ]);
    }

    // ── Seeders (hors des 15, garde-fous d'idempotence) ────────────────────────────────────

    public function test_seeder_plans_tarifaires_idempotent(): void
    {
        $this->seed(PlansTarifairesSeeder::class);
        $premierPassage = PlanTarifaire::count();

        $this->seed(PlansTarifairesSeeder::class);
        $secondPassage = PlanTarifaire::count();

        $this->assertSame(5, $premierPassage);
        $this->assertSame($premierPassage, $secondPassage);
    }

    public function test_seeder_baremes_commission_idempotent(): void
    {
        $this->seed(BaremesCommissionSeeder::class);
        $premierPassage = BaremeCommission::count();

        $this->seed(BaremesCommissionSeeder::class);
        $secondPassage = BaremeCommission::count();

        $this->assertSame(4, $premierPassage);
        $this->assertSame($premierPassage, $secondPassage);
    }
}
