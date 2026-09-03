<?php

namespace Tests\Feature;

use App\Models\Medicament;
use App\Models\MembreFamille;
use App\Models\PrixPharmacie;
use App\Models\StockOfficine;
use App\Models\StructureSanitaire;
use App\Models\User;
use App\Services\Medicament\ServiceDelivrance;
use App\Services\Medicament\ServiceStockOfficine;
use Database\Seeders\PortailRolesSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Tests\TestCase;

/**
 * B3-b — l'inventaire d'une officine (CDC_11 §7.3 et §7.5).
 *
 * CE QUE CETTE SUITE PROTÈGE. `prix_pharmacie` est un RELEVÉ, pas un stock : ni lot, ni péremption,
 * ni entrée/sortie. Et l'écran qui s'appelait « stock » déclarait un prix. Ce lot livre le vrai
 * inventaire — et la garantie centrale est que **le stock est une SOMME de mouvements**, jamais une
 * valeur qu'on écrase : une erreur se corrige par un ajustement, qui la laisse visible.
 */
class StockOfficineTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PortailRolesSeeder::class);
    }

    private function officine(string $type = 'pharmacie'): StructureSanitaire
    {
        return StructureSanitaire::create([
            'nom' => 'Pharmacie du Plateau', 'type' => $type, 'adresse' => 'Abidjan',
            'commune' => 'Plateau', 'latitude' => 5.32, 'longitude' => -4.02, 'actif' => true,
        ]);
    }

    private function pharmacien(bool $habilite = true, ?StructureSanitaire $officine = null): User
    {
        $officine ??= $this->officine();
        $user = User::factory()->create(['structure_id' => $officine->id]);

        if ($habilite) {
            $user->givePermissionTo(ServiceStockOfficine::PERMISSION);
        }

        return $user->fresh();
    }

    private function medicament(string $nom = 'Paracétamol'): Medicament
    {
        return Medicament::create([
            'nom_generique' => $nom, 'nom_commercial' => $nom, 'categorie' => 'antalgique',
            'ordonnance_requise' => false, 'disponible_generique' => true,
        ]);
    }

    private function service(): ServiceStockOfficine
    {
        return app(ServiceStockOfficine::class);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // LA GARANTIE CENTRALE : le stock est une somme
    // ─────────────────────────────────────────────────────────────────────────

    public function test_le_stock_est_la_somme_des_mouvements(): void
    {
        $officine = $this->officine();
        $agent = $this->pharmacien(officine: $officine);
        $article = $this->service()->article($officine, $this->medicament());

        $this->service()->mouvement($agent, $article, 'entree', 100);
        $this->service()->mouvement($agent, $article, 'sortie', 30);
        $this->service()->mouvement($agent, $article, 'peremption', 5);

        $this->assertSame(65, $article->fresh()->stockCourant());
        $this->assertDatabaseCount('mouvements_stock', 3);
    }

    /**
     * LE SIGNE EST DÉDUIT DU TYPE, jamais demandé à l'appelant : une « entrée de -5 » n'a pas de
     * sens, et laisser l'appelant choisir ferait dépendre l'intégrité du stock de la discipline de
     * chaque site d'appel.
     */
    public function test_le_signe_est_deduit_du_type_et_non_de_l_appelant(): void
    {
        $officine = $this->officine();
        $agent = $this->pharmacien(officine: $officine);
        $article = $this->service()->article($officine, $this->medicament());

        // On envoie délibérément des signes absurdes : ils sont corrigés, pas acceptés.
        $entree = $this->service()->mouvement($agent, $article, 'entree', -40);
        $sortie = $this->service()->mouvement($agent, $article, 'sortie', 10);

        $this->assertSame(40, $entree->quantite);
        $this->assertSame(-10, $sortie->quantite);
        $this->assertSame(30, $article->fresh()->stockCourant());
    }

    /** L'ajustement est le seul qui va dans les deux sens : c'est sa raison d'être. */
    public function test_l_ajustement_va_dans_les_deux_sens(): void
    {
        $officine = $this->officine();
        $agent = $this->pharmacien(officine: $officine);
        $article = $this->service()->article($officine, $this->medicament());

        $this->service()->mouvement($agent, $article, 'entree', 10);
        $this->service()->mouvement($agent, $article, 'ajustement', -3, ['motif' => 'Écart d\'inventaire']);

        $this->assertSame(7, $article->fresh()->stockCourant());
    }

    public function test_le_stock_ne_descend_pas_sous_zero(): void
    {
        $officine = $this->officine();
        $agent = $this->pharmacien(officine: $officine);
        $article = $this->service()->article($officine, $this->medicament());
        $this->service()->mouvement($agent, $article, 'entree', 5);

        $this->attendRefus(
            fn () => $this->service()->mouvement($agent, $article->fresh(), 'sortie', 8),
            'Le stock de « Paracétamol » est de 5 : impossible d\'en sortir 8.'
        );

        $this->assertSame(5, $article->fresh()->stockCourant());
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Append-only : deux niveaux, aucun ne rattrape l'autre
    // ─────────────────────────────────────────────────────────────────────────

    public function test_un_mouvement_ne_se_modifie_pas(): void
    {
        $officine = $this->officine();
        $agent = $this->pharmacien(officine: $officine);
        $article = $this->service()->article($officine, $this->medicament());
        $mouvement = $this->service()->mouvement($agent, $article, 'entree', 10);

        $this->expectException(RuntimeException::class);

        $mouvement->update(['quantite' => 999]);
    }

    public function test_un_mouvement_ne_s_efface_pas(): void
    {
        $officine = $this->officine();
        $agent = $this->pharmacien(officine: $officine);
        $article = $this->service()->article($officine, $this->medicament());
        $mouvement = $this->service()->mouvement($agent, $article, 'entree', 10);

        $this->expectException(RuntimeException::class);

        $mouvement->delete();
    }

    /** Le second niveau : le moteur refuse aussi, même face à un accès direct. */
    public function test_le_moteur_refuse_lui_aussi_une_modification(): void
    {
        $officine = $this->officine();
        $agent = $this->pharmacien(officine: $officine);
        $article = $this->service()->article($officine, $this->medicament());
        $mouvement = $this->service()->mouvement($agent, $article, 'entree', 10);

        $this->expectException(QueryException::class);

        DB::table('mouvements_stock')->where('id', $mouvement->id)->update(['quantite' => 999]);
    }

    public function test_le_moteur_refuse_un_signe_contraire_au_type(): void
    {
        $officine = $this->officine();
        $article = $this->service()->article($officine, $this->medicament());

        $this->expectException(QueryException::class);

        DB::table('mouvements_stock')->insert([
            'stock_id' => $article->id, 'type' => 'entree', 'quantite' => -5,
            'agent_nom' => 'Test', 'created_at' => now(),
        ]);
    }

    public function test_le_moteur_refuse_une_quantite_nulle(): void
    {
        $officine = $this->officine();
        $article = $this->service()->article($officine, $this->medicament());

        $this->expectException(QueryException::class);

        DB::table('mouvements_stock')->insert([
            'stock_id' => $article->id, 'type' => 'ajustement', 'quantite' => 0,
            'agent_nom' => 'Test', 'created_at' => now(),
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // L'inventaire alimente le relevé public — il ne le double pas
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * UNE SEULE VALEUR PUBLIQUE. Sans cette répercussion, le comparateur et la fiche officine
     * pourraient se contredire, et *le patient ne saurait pas laquelle croire* (motif P6.7b).
     */
    public function test_une_entree_avec_prix_alimente_le_releve_public(): void
    {
        $officine = $this->officine();
        $agent = $this->pharmacien(officine: $officine);
        $medicament = $this->medicament();
        $article = $this->service()->article($officine, $medicament);

        $this->service()->fixerPrix($agent, $article, 1500);
        $this->service()->mouvement($agent, $article->fresh(), 'entree', 20);

        $dernier = PrixPharmacie::where('structure_id', $officine->id)
            ->where('medicament_id', $medicament->id)
            ->orderByDesc('id')->first();

        $this->assertNotNull($dernier);
        $this->assertSame(1500, $dernier->prix_cfa);
        $this->assertTrue((bool) $dernier->disponible);
        $this->assertSame('pharmacie_portail', $dernier->source);
    }

    public function test_un_stock_epuise_signale_une_rupture_au_comparateur(): void
    {
        $officine = $this->officine();
        $agent = $this->pharmacien(officine: $officine);
        $medicament = $this->medicament();
        $article = $this->service()->article($officine, $medicament);

        $this->service()->fixerPrix($agent, $article, 1500);
        $this->service()->mouvement($agent, $article->fresh(), 'entree', 5);
        $this->service()->mouvement($agent, $article->fresh(), 'sortie', 5);

        $dernier = PrixPharmacie::where('structure_id', $officine->id)
            ->where('medicament_id', $medicament->id)
            ->orderByDesc('id')->first();

        $this->assertFalse((bool) $dernier->disponible);
    }

    /** Un prix non fixé ne se devine pas : aucun montant n'est inventé. */
    public function test_sans_prix_fixe_aucun_montant_n_est_invente(): void
    {
        $officine = $this->officine();
        $agent = $this->pharmacien(officine: $officine);
        $medicament = $this->medicament();
        $article = $this->service()->article($officine, $medicament);

        $this->service()->mouvement($agent, $article, 'entree', 20);

        $releves = PrixPharmacie::where('structure_id', $officine->id)
            ->where('medicament_id', $medicament->id)->get();

        // Aucun relevé de PRIX n'est écrit — on ne publie pas un montant qu'on n'a pas.
        $this->assertTrue($releves->every(fn ($r): bool => $r->prix_cfa === null));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Seuils et péremptions (§7.3)
    // ─────────────────────────────────────────────────────────────────────────

    /** `null` quand aucun seuil n'est fixé : on ne prétend pas savoir si un stock est bas. */
    public function test_sans_seuil_on_ne_pretend_pas_savoir_si_le_stock_est_bas(): void
    {
        $officine = $this->officine();
        $agent = $this->pharmacien(officine: $officine);
        $article = $this->service()->article($officine, $this->medicament());
        $this->service()->mouvement($agent, $article, 'entree', 1);

        $this->assertNull($article->fresh()->sousLeSeuil());
        $this->assertCount(0, $this->service()->alertes($officine));
    }

    public function test_les_alertes_ne_listent_que_les_articles_sous_leur_seuil(): void
    {
        $officine = $this->officine();
        $agent = $this->pharmacien(officine: $officine);

        $bas = $this->service()->article($officine, $this->medicament('Amoxicilline'));
        $this->service()->fixerPrix($agent, $bas, 2000, 10);
        $this->service()->mouvement($agent, $bas->fresh(), 'entree', 8);

        $haut = $this->service()->article($officine, $this->medicament('Ibuprofène'));
        $this->service()->fixerPrix($agent, $haut, 1000, 10);
        $this->service()->mouvement($agent, $haut->fresh(), 'entree', 50);

        $alertes = $this->service()->alertes($officine);

        $this->assertCount(1, $alertes);
        $this->assertSame('Amoxicilline', $alertes->first()->medicament->nom_generique);
    }

    public function test_les_peremptions_proches_sont_listees(): void
    {
        $officine = $this->officine();
        $agent = $this->pharmacien(officine: $officine);
        $article = $this->service()->article($officine, $this->medicament());

        $this->service()->mouvement($agent, $article, 'entree', 10, [
            'lot' => 'LOT-A', 'date_peremption' => now()->addDays(30)->toDateString(),
        ]);
        $this->service()->mouvement($agent, $article->fresh(), 'entree', 10, [
            'lot' => 'LOT-B', 'date_peremption' => now()->addYears(2)->toDateString(),
        ]);

        $proches = $this->service()->peremptions($officine);

        $this->assertCount(1, $proches);
        $this->assertSame('LOT-A', $proches->first()->lot);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Les gardes
    // ─────────────────────────────────────────────────────────────────────────

    public function test_un_compte_non_habilite_ne_tient_pas_le_stock(): void
    {
        $officine = $this->officine();
        $article = $this->service()->article($officine, $this->medicament());

        $this->attendRefus(
            fn () => $this->service()->mouvement(
                $this->pharmacien(habilite: false, officine: $officine), $article, 'entree', 5
            ),
            "Vous n'êtes pas habilité à tenir ce stock."
        );

        $this->assertDatabaseCount('mouvements_stock', 0);
    }

    public function test_un_stock_d_officine_ne_se_tient_pas_dans_un_chu(): void
    {
        $this->attendRefus(
            fn () => $this->service()->article($this->officine('chu'), $this->medicament()),
            'Un stock d\'officine ne se tient que dans une pharmacie.'
        );
    }

    public function test_un_seul_article_par_produit_et_par_officine(): void
    {
        $officine = $this->officine();
        $medicament = $this->medicament();

        $premier = $this->service()->article($officine, $medicament);
        $second = $this->service()->article($officine, $medicament);

        $this->assertSame($premier->id, $second->id);
        $this->assertDatabaseCount('stocks_officine', 1);
    }

    /** **404 et jamais 403** : un 403 dirait ce qu'un confrère tient en rayon. */
    public function test_l_article_d_un_confrere_repond_404(): void
    {
        $mien = $this->officine();
        $confrere = StructureSanitaire::create([
            'nom' => 'Pharmacie de Cocody', 'type' => 'pharmacie', 'adresse' => 'Abidjan',
            'commune' => 'Cocody', 'latitude' => 5.35, 'longitude' => -3.98, 'actif' => true,
        ]);

        $sien = StockOfficine::create([
            'structure_id' => $confrere->id, 'medicament_id' => $this->medicament()->id,
        ]);

        $reponse = $this->actingAs($this->pharmacien(officine: $mien), 'web')
            ->post(route('portail.stock-officine.mouvement', $sien), ['type' => 'entree', 'quantite' => 5]);

        $reponse->assertNotFound();
        $this->assertNotSame(403, $reponse->getStatusCode());
    }

    // ─────────────────────────────────────────────────────────────────────────
    // B3-a → B3-b : la délivrance sort du stock
    // ─────────────────────────────────────────────────────────────────────────

    public function test_une_delivrance_sort_du_stock_quand_l_officine_le_tient(): void
    {
        $officine = $this->officine();
        $pharmacien = $this->pharmacien(officine: $officine);
        $pharmacien->givePermissionTo(ServiceDelivrance::PERMISSION);
        $medicament = $this->medicament();

        $article = $this->service()->article($officine, $medicament);
        $this->service()->mouvement($pharmacien, $article, 'entree', 50);

        $patient = MembreFamille::factory()->for(User::factory()->create())->create();
        $ordonnance = $patient->ordonnances()->create([
            'medecin_nom' => 'Dr Kablan', 'structure_sanitaire' => 'CHU',
            'date_prescription' => '2026-09-03',
            'medicaments_json' => [[
                'nom' => 'Paracétamol', 'medicament_id' => $medicament->id, 'quantite' => 20,
            ]],
        ])->fresh();

        app(ServiceDelivrance::class)->delivrer(
            $pharmacien->fresh(), $ordonnance, [$ordonnance->lignes[0]->id => 20]
        );

        $this->assertSame(30, $article->fresh()->stockCourant());
        $this->assertDatabaseHas('mouvements_stock', ['type' => 'sortie', 'quantite' => -20]);
    }

    /**
     * SI L'OFFICINE NE TIENT PAS SON INVENTAIRE, LA DÉLIVRANCE PASSE QUAND MÊME. Refuser de servir
     * parce qu'une pharmacie ne tient pas son stock dans notre application priverait un patient de
     * son traitement pour une raison qui ne le concerne pas.
     */
    public function test_une_delivrance_passe_meme_sans_inventaire_tenu(): void
    {
        $officine = $this->officine();
        $pharmacien = $this->pharmacien(officine: $officine);
        $pharmacien->givePermissionTo(ServiceDelivrance::PERMISSION);
        $medicament = $this->medicament();

        $patient = MembreFamille::factory()->for(User::factory()->create())->create();
        $ordonnance = $patient->ordonnances()->create([
            'medecin_nom' => 'Dr Kablan', 'structure_sanitaire' => 'CHU',
            'date_prescription' => '2026-09-03',
            'medicaments_json' => [[
                'nom' => 'Paracétamol', 'medicament_id' => $medicament->id, 'quantite' => 20,
            ]],
        ])->fresh();

        app(ServiceDelivrance::class)->delivrer(
            $pharmacien->fresh(), $ordonnance, [$ordonnance->lignes[0]->id => 20]
        );

        $this->assertDatabaseCount('delivrances', 1);
        $this->assertDatabaseCount('mouvements_stock', 0);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // La fiche officine (§7.4)
    // ─────────────────────────────────────────────────────────────────────────

    public function test_la_fiche_officine_porte_ses_quatre_champs(): void
    {
        $officine = $this->officine();

        $officine->forceFill([
            'pharmacien_responsable' => 'Dr Fatou Diallo',
            'numero_licence' => 'LIC-2026-0042',
            'livraison_disponible' => true,
            'rayon_livraison_km' => 8,
        ])->save();

        $this->assertDatabaseHas('structures_sanitaires', [
            'id' => $officine->id,
            'pharmacien_responsable' => 'Dr Fatou Diallo',
            'numero_licence' => 'LIC-2026-0042',
            'rayon_livraison_km' => 8,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────

    /** Vérifie qu'un refus survient ET qu'il survient pour la BONNE raison (leçon B1-d). */
    private function attendRefus(callable $action, string $message): void
    {
        try {
            $action();
            $this->fail("Refus attendu : {$message}");
        } catch (ValidationException $e) {
            $this->assertContains(
                $message,
                collect($e->errors())->flatten()->all(),
                'Le refus a bien eu lieu, mais pas pour la raison attendue.'
            );
        }
    }
}
