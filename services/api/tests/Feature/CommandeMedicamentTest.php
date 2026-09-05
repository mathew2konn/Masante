<?php

namespace Tests\Feature;

use App\Models\Commande;
use App\Models\Medicament;
use App\Models\MembreFamille;
use App\Models\Ordonnance;
use App\Models\StructureSanitaire;
use App\Models\User;
use App\Notifications\NotificationMasante;
use App\Services\Medicament\ServiceCommande;
use App\Services\Medicament\ServiceDelivrance;
use App\Services\Medicament\ServiceStockOfficine;
use App\Services\Medicament\ServiceTraitementCommande;
use App\Services\PrixMedicamentService;
use Database\Seeders\PortailRolesSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;

/**
 * B3-d — panier et commande de médicaments (CDC_11 §9.5, §10.5).
 *
 * CE QUE CETTE SUITE PROTÈGE. `ordonnance_requise` cesse d'être décorative (F3) : un produit qui
 * l'exige est refusé sans ordonnance désignée ET PRESCRIVANT CE PRODUIT — le refus nomme le
 * produit dans les deux cas. Le règlement en ligne (F6, réécrit) emprunte le canal réel de B4 :
 * aucun encaissement simulé, aucun drapeau — la disponibilité est une propriété de l'officine.
 */
class CommandeMedicamentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PortailRolesSeeder::class);
    }

    private function officine(array $attrs = []): StructureSanitaire
    {
        return StructureSanitaire::create(array_merge([
            'nom' => 'Pharmacie du Plateau', 'type' => 'pharmacie', 'adresse' => 'Abidjan',
            'commune' => 'Plateau', 'latitude' => 5.32, 'longitude' => -4.02, 'actif' => true,
        ], $attrs));
    }

    private function patientEtMembre(): array
    {
        $user = User::factory()->create();
        $membre = MembreFamille::factory()->for($user)->create();

        return [$user, $membre];
    }

    private function pharmacien(bool $traiter = true, bool $delivrer = false, ?StructureSanitaire $officine = null): User
    {
        $officine ??= $this->officine();
        $user = User::factory()->create(['structure_id' => $officine->id]);

        if ($traiter) {
            $user->givePermissionTo(ServiceTraitementCommande::PERMISSION);
        }
        if ($delivrer) {
            $user->givePermissionTo(ServiceDelivrance::PERMISSION);
        }

        return $user->fresh();
    }

    private function medicament(array $attrs = []): Medicament
    {
        return Medicament::create(array_merge([
            'nom_generique' => 'Paracétamol', 'nom_commercial' => 'Doliprane',
            'dosage' => '500 mg', 'categorie' => 'antalgique',
            'ordonnance_requise' => false, 'disponible_generique' => true,
        ], $attrs));
    }

    /** Relève un prix pharmacien-portail pour ce couple (officine, médicament) — source d'autorité,
     *  par le VRAI service (celui que le comparateur lit), jamais une écriture directe. */
    private function releverPrix(StructureSanitaire $officine, Medicament $medicament, int $prixCfa): void
    {
        app(PrixMedicamentService::class)->releverPrix(
            $medicament, $officine, $prixCfa, 'pharmacie_portail',
        );
    }

    /** Une ordonnance dont les lignes portent un `medicament_id` réel (résolu au référentiel). */
    private function ordonnancePour(MembreFamille $membre, Medicament $medicament, int $quantite = 20): Ordonnance
    {
        return $membre->ordonnances()->create([
            'medecin_nom' => 'Dr Kablan Koffi',
            'structure_sanitaire' => 'CHU de Cocody',
            'date_prescription' => '2026-09-05',
            'medicaments_json' => [[
                'nom' => $medicament->libelle,
                'medicament_id' => $medicament->id,
                'code_national' => $medicament->code,
                'dci' => $medicament->nom_generique,
                'dosage_referentiel' => $medicament->dosage,
                'posologie' => '1 cp x3/j',
                'quantite' => $quantite,
            ]],
        ])->fresh();
    }

    private function service(): ServiceCommande
    {
        return app(ServiceCommande::class);
    }

    private function traitement(): ServiceTraitementCommande
    {
        return app(ServiceTraitementCommande::class);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // F3 — LE VECTEUR CENTRAL : ordonnance_requise cesse d'être décorative
    // ─────────────────────────────────────────────────────────────────────────

    public function test_un_produit_sous_ordonnance_sans_ordonnance_designee_est_refuse_en_nommant_le_produit(): void
    {
        [$user, $membre] = $this->patientEtMembre();
        $officine = $this->officine();
        $medicament = $this->medicament(['ordonnance_requise' => true, 'nom_generique' => 'Amoxicilline']);
        $this->releverPrix($officine, $medicament, 3000);

        try {
            $this->service()->passer(
                $user, $membre, $officine,
                [['medicament_id' => $medicament->id, 'quantite' => 2]],
                'retrait', null, null, null,
            );
            $this->fail('Devait être refusé.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('Amoxicilline', collect($e->errors())->flatten()->implode(' '));
        }

        $this->assertDatabaseCount('commandes', 0);
    }

    public function test_un_produit_sous_ordonnance_avec_ordonnance_designee_et_prescrivant_passe(): void
    {
        [$user, $membre] = $this->patientEtMembre();
        $officine = $this->officine();
        $medicament = $this->medicament(['ordonnance_requise' => true, 'nom_generique' => 'Amoxicilline']);
        $this->releverPrix($officine, $medicament, 3000);
        $ordonnance = $this->ordonnancePour($membre, $medicament);

        $commande = $this->service()->passer(
            $user, $membre, $officine,
            [['medicament_id' => $medicament->id, 'quantite' => 2]],
            'retrait', null, $ordonnance, null,
        );

        $this->assertSame('en_attente', $commande->statut->value);
        $this->assertSame(6000, $commande->montant_indicatif_cfa);
        $this->assertNotNull($commande->lignes->first()->ordonnance_ligne_id);
    }

    public function test_une_ordonnance_qui_ne_prescrit_pas_ce_produit_est_refusee_en_nommant_le_produit(): void
    {
        [$user, $membre] = $this->patientEtMembre();
        $officine = $this->officine();
        $medicamentCommande = $this->medicament(['ordonnance_requise' => true, 'nom_generique' => 'Amoxicilline']);
        $medicamentPrescrit = $this->medicament(['ordonnance_requise' => true, 'nom_generique' => 'Ibuprofène']);
        $this->releverPrix($officine, $medicamentCommande, 3000);
        // L'ordonnance prescrit un AUTRE produit.
        $ordonnance = $this->ordonnancePour($membre, $medicamentPrescrit);

        try {
            $this->service()->passer(
                $user, $membre, $officine,
                [['medicament_id' => $medicamentCommande->id, 'quantite' => 2]],
                'retrait', null, $ordonnance, null,
            );
            $this->fail('Devait être refusé.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('Amoxicilline', collect($e->errors())->flatten()->implode(' '));
        }
    }

    public function test_un_produit_libre_ne_necessite_aucune_ordonnance(): void
    {
        [$user, $membre] = $this->patientEtMembre();
        $officine = $this->officine();
        $medicament = $this->medicament();
        $this->releverPrix($officine, $medicament, 500);

        $commande = $this->service()->passer(
            $user, $membre, $officine,
            [['medicament_id' => $medicament->id, 'quantite' => 3]],
            'retrait', null, null, null,
        );

        $this->assertSame(1500, $commande->montant_indicatif_cfa);
    }

    /**
     * DÉFAUT RÉEL TROUVÉ AU G2 LIVE, PAS PAR UN TEST : `medicament_id` était absent du
     * `$fillable` de `CommandeLigne` — chaque ligne naissait avec `medicament_id = NULL`, ce qui
     * rendait `UNIQUE(commande_id, medicament_id)` inopérant (MySQL/SQLite autorisent plusieurs
     * NULL sous un index unique) et cassait en silence `sortirVenteLibre()`, qui se tait quand
     * `medicament_id` est nul. Vecteur ajouté pour que ça ne revienne jamais sans bruit.
     */
    public function test_medicament_id_est_reellement_enregistre_sur_la_ligne(): void
    {
        [$user, $membre] = $this->patientEtMembre();
        $officine = $this->officine();
        $medicament = $this->medicament();
        $this->releverPrix($officine, $medicament, 500);

        $commande = $this->service()->passer(
            $user, $membre, $officine,
            [['medicament_id' => $medicament->id, 'quantite' => 1]],
            'retrait', null, null, null,
        );

        $this->assertSame($medicament->id, $commande->lignes->first()->medicament_id);
        $this->assertDatabaseHas('commande_lignes', [
            'commande_id' => $commande->id,
            'medicament_id' => $medicament->id,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Le client ne décide pas — tout est figé au moment de la commande
    // ─────────────────────────────────────────────────────────────────────────

    public function test_les_valeurs_figees_survivent_au_renommage_du_produit(): void
    {
        [$user, $membre] = $this->patientEtMembre();
        $officine = $this->officine();
        $medicament = $this->medicament(['nom_generique' => 'Paracétamol']);
        $this->releverPrix($officine, $medicament, 500);

        $commande = $this->service()->passer(
            $user, $membre, $officine,
            [['medicament_id' => $medicament->id, 'quantite' => 1]],
            'retrait', null, null, null,
        );

        $medicament->update(['nom_generique' => 'RENOMMÉ', 'nom_commercial' => 'RENOMMÉ']);

        $this->assertSame('Doliprane (Paracétamol)', $commande->fresh('lignes')->lignes->first()->nom);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // F7 — livraison uniquement si l'officine la propose
    // ─────────────────────────────────────────────────────────────────────────

    public function test_livraison_refusee_chez_une_officine_qui_ne_la_propose_pas(): void
    {
        [$user, $membre] = $this->patientEtMembre();
        $officine = $this->officine(); // livraison_disponible = false par défaut
        $medicament = $this->medicament();
        $this->releverPrix($officine, $medicament, 500);

        $this->expectException(ValidationException::class);

        $this->service()->passer(
            $user, $membre, $officine,
            [['medicament_id' => $medicament->id, 'quantite' => 1]],
            'livraison', 'Cocody, Rue des Jardins', null, null,
        );
    }

    public function test_livraison_acceptee_chez_une_officine_qui_la_propose(): void
    {
        [$user, $membre] = $this->patientEtMembre();
        $officine = $this->officine();
        $officine->livraison_disponible = true;
        $officine->save();
        $medicament = $this->medicament();
        $this->releverPrix($officine, $medicament, 500);

        $commande = $this->service()->passer(
            $user, $membre, $officine,
            [['medicament_id' => $medicament->id, 'quantite' => 1]],
            'livraison', 'Cocody, Rue des Jardins', null, null,
        );

        $this->assertSame('livraison', $commande->mode_retrait->value);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // F8 — le stock est consulté, jamais engagé à la commande
    // ─────────────────────────────────────────────────────────────────────────

    public function test_le_stock_ne_bouge_pas_a_la_commande(): void
    {
        [$user, $membre] = $this->patientEtMembre();
        $officine = $this->officine();
        $pharmacien = $this->pharmacien(officine: $officine);
        $pharmacien->givePermissionTo(ServiceStockOfficine::PERMISSION);
        $medicament = $this->medicament();
        $this->releverPrix($officine, $medicament, 500);

        $article = app(ServiceStockOfficine::class)->article($officine, $medicament);
        app(ServiceStockOfficine::class)->mouvement($pharmacien, $article, 'entree', 50);

        $this->service()->passer(
            $user, $membre, $officine,
            [['medicament_id' => $medicament->id, 'quantite' => 5]],
            'retrait', null, null, null,
        );

        $this->assertSame(50, $article->fresh()->stockCourant());
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Le cycle — chaque transition refusée par son motif
    // ─────────────────────────────────────────────────────────────────────────

    private function commandeEnAttente(?StructureSanitaire $officine = null): Commande
    {
        [$user, $membre] = $this->patientEtMembre();
        $officine ??= $this->officine();
        $medicament = $this->medicament();
        $this->releverPrix($officine, $medicament, 500);

        return $this->service()->passer(
            $user, $membre, $officine,
            [['medicament_id' => $medicament->id, 'quantite' => 2]],
            'retrait', null, null, null,
        );
    }

    public function test_cycle_complet_jusqu_a_la_remise(): void
    {
        $commande = $this->commandeEnAttente();
        $officine = $commande->structure;
        $pharmacien = $this->pharmacien(officine: $officine);

        $accepte = $this->traitement()->accepter($pharmacien, $commande);
        $this->assertSame('acceptee', $accepte->statut->value);

        $prete = $this->traitement()->preparer($pharmacien, $accepte);
        $this->assertSame('prete', $prete->statut->value);

        $remise = $this->traitement()->remettre($pharmacien, $prete);
        $this->assertSame('remise', $remise->statut->value);
        $this->assertNotNull($remise->remise_le);
    }

    public function test_refus_sans_motif_est_refuse_par_son_message(): void
    {
        $commande = $this->commandeEnAttente();
        $pharmacien = $this->pharmacien(officine: $commande->structure);

        $this->expectException(ValidationException::class);
        $this->traitement()->refuser($pharmacien, $commande, '');
    }

    public function test_refus_avec_motif_est_persiste(): void
    {
        $commande = $this->commandeEnAttente();
        $pharmacien = $this->pharmacien(officine: $commande->structure);

        $refusee = $this->traitement()->refuser($pharmacien, $commande, 'Rupture définitive');

        $this->assertSame('refusee', $refusee->statut->value);
        $this->assertSame('Rupture définitive', $refusee->motif_refus);
    }

    public function test_preparer_avant_acceptation_est_refuse(): void
    {
        $commande = $this->commandeEnAttente();
        $pharmacien = $this->pharmacien(officine: $commande->structure);

        $this->expectException(ValidationException::class);
        $this->traitement()->preparer($pharmacien, $commande);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Anti-IDOR — F11
    // ─────────────────────────────────────────────────────────────────────────

    public function test_pharmacien_d_une_autre_officine_recoit_404(): void
    {
        $commande = $this->commandeEnAttente();
        $autre = $this->pharmacien(); // officine différente par défaut

        $this->expectException(NotFoundHttpException::class);
        $this->traitement()->accepter($autre, $commande);
    }

    public function test_non_habilite_est_refuse(): void
    {
        $commande = $this->commandeEnAttente();
        $nonHabilite = $this->pharmacien(traiter: false, officine: $commande->structure);

        $this->expectException(ValidationException::class);
        $this->traitement()->accepter($nonHabilite, $commande);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // F9 — la remise sous ordonnance crée une délivrance + trace ; la vente libre non
    // ─────────────────────────────────────────────────────────────────────────

    public function test_remise_sous_ordonnance_cree_une_delivrance_et_sa_trace(): void
    {
        [$user, $membre] = $this->patientEtMembre();
        $officine = $this->officine();
        $medicament = $this->medicament(['ordonnance_requise' => true, 'nom_generique' => 'Amoxicilline']);
        $this->releverPrix($officine, $medicament, 3000);
        $ordonnance = $this->ordonnancePour($membre, $medicament, quantite: 10);

        $commande = $this->service()->passer(
            $user, $membre, $officine,
            [['medicament_id' => $medicament->id, 'quantite' => 2]],
            'retrait', null, $ordonnance, null,
        );

        $pharmacien = $this->pharmacien(delivrer: true, officine: $officine);
        $this->traitement()->accepter($pharmacien, $commande);
        $this->traitement()->preparer($pharmacien, $commande->fresh());
        $this->traitement()->remettre($pharmacien, $commande->fresh());

        $this->assertDatabaseCount('delivrances', 1);
        $this->assertDatabaseCount('traces_dispensation', 1);
    }

    public function test_remise_sous_ordonnance_sans_permission_delivrer_est_refusee(): void
    {
        [$user, $membre] = $this->patientEtMembre();
        $officine = $this->officine();
        $medicament = $this->medicament(['ordonnance_requise' => true, 'nom_generique' => 'Amoxicilline']);
        $this->releverPrix($officine, $medicament, 3000);
        $ordonnance = $this->ordonnancePour($membre, $medicament, quantite: 10);

        $commande = $this->service()->passer(
            $user, $membre, $officine,
            [['medicament_id' => $medicament->id, 'quantite' => 2]],
            'retrait', null, $ordonnance, null,
        );

        // `commande.traiter` SEUL, pas `ordonnance.delivrer` (F10).
        $pharmacien = $this->pharmacien(delivrer: false, officine: $officine);
        $this->traitement()->accepter($pharmacien, $commande);
        $this->traitement()->preparer($pharmacien, $commande->fresh());

        $this->expectException(ValidationException::class);
        $this->traitement()->remettre($pharmacien, $commande->fresh());
    }

    public function test_remise_vente_libre_ne_cree_aucune_trace(): void
    {
        $commande = $this->commandeEnAttente();
        $officine = $commande->structure;
        $pharmacien = $this->pharmacien(officine: $officine);

        $this->traitement()->accepter($pharmacien, $commande);
        $this->traitement()->preparer($pharmacien, $commande->fresh());
        $this->traitement()->remettre($pharmacien, $commande->fresh());

        $this->assertDatabaseCount('delivrances', 0);
        $this->assertDatabaseCount('traces_dispensation', 0);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // La notification ne porte aucun nom de produit
    // ─────────────────────────────────────────────────────────────────────────

    public function test_la_notification_ne_porte_aucun_nom_de_produit(): void
    {
        [$user, $membre] = $this->patientEtMembre();
        $officine = $this->officine();
        $medicament = $this->medicament(['nom_generique' => 'AmoxicillineUnique']);
        $this->releverPrix($officine, $medicament, 3000);

        $commande = $this->service()->passer(
            $user, $membre, $officine,
            [['medicament_id' => $medicament->id, 'quantite' => 1]],
            'retrait', null, null, null,
        );

        Notification::fake();
        $pharmacien = $this->pharmacien(officine: $officine);
        $this->traitement()->accepter($pharmacien, $commande);

        Notification::assertSentTo(
            $user,
            NotificationMasante::class,
            function ($notification) {
                $charge = json_encode($notification->toArray($this->createMock(User::class)));

                return ! str_contains($charge, 'AmoxicillineUnique');
            },
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Garde du moteur — quantité ≥ 1
    // ─────────────────────────────────────────────────────────────────────────

    public function test_le_moteur_refuse_une_ligne_de_quantite_nulle(): void
    {
        $commande = $this->commandeEnAttente();

        $this->expectException(QueryException::class);
        $commande->lignes()->create([
            'medicament_id' => null, 'nom' => 'Test', 'quantite' => 0,
        ]);
    }
}
