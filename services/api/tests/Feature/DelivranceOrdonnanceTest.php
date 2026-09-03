<?php

namespace Tests\Feature;

use App\Models\MembreFamille;
use App\Models\Ordonnance;
use App\Models\StructureSanitaire;
use App\Models\User;
use App\Services\Medicament\ServiceDelivrance;
use Database\Seeders\PortailRolesSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * B3-a — servir une ordonnance en officine (CDC_11 §7.1).
 *
 * CE QUE CETTE SUITE PROTÈGE, ET LE VECTEUR CENTRAL EST UNE ABSENCE. Le seul mécanisme existant
 * (`qr.scan`) ouvre une SESSION DE DOSSIER : antécédents, vaccinations, résultats d'analyses. *Un
 * pharmacien n'a pas à lire les antécédents pour servir une boîte de paracétamol.* L'accès passe
 * donc par un jeton porté par l'ordonnance, et **aucune ligne de journal d'accès n'est créée** —
 * ce n'est pas une garde qu'on vérifie, c'est une porte qui n'existe pas.
 */
class DelivranceOrdonnanceTest extends TestCase
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
            $user->givePermissionTo(ServiceDelivrance::PERMISSION);
        }

        return $user->fresh();
    }

    private function patient(): MembreFamille
    {
        return MembreFamille::factory()->for(User::factory()->create())->create();
    }

    /** Une ordonnance avec ses lignes, écrite par le chemin ordinaire du carnet. */
    private function ordonnance(?array $medicaments = null): Ordonnance
    {
        $medicaments ??= [
            ['nom' => 'Paracétamol 500 mg', 'posologie' => '1 cp x3/j', 'quantite' => 20],
            ['nom' => 'Amoxicilline 1 g', 'posologie' => '1 cp x2/j', 'quantite' => 14],
        ];

        return $this->patient()->ordonnances()->create([
            'medecin_nom' => 'Dr Kablan Koffi',
            'structure_sanitaire' => 'CHU de Cocody',
            'date_prescription' => '2026-09-03',
            'medicaments_json' => $medicaments,
        ])->fresh();
    }

    private function service(): ServiceDelivrance
    {
        return app(ServiceDelivrance::class);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // La projection des lignes (le report de B2-c, levé)
    // ─────────────────────────────────────────────────────────────────────────

    public function test_une_ordonnance_ecrite_projette_ses_lignes(): void
    {
        $ordonnance = $this->ordonnance();

        $this->assertCount(2, $ordonnance->lignes);
        $this->assertTrue($ordonnance->estDelivrable());
        $this->assertSame('Paracétamol 500 mg', $ordonnance->lignes[0]->nom);
        $this->assertSame(20, $ordonnance->lignes[0]->quantite_prescrite);
        $this->assertSame(1, $ordonnance->lignes[0]->rang);
        $this->assertSame(2, $ordonnance->lignes[1]->rang);
    }

    /**
     * Le `PUT` est couvert au même titre que la création : une garantie qui ne vaudrait que sur
     * l'un des chemins n'en serait pas une (leçon P6.8b, où `update()` avait été oublié).
     */
    public function test_la_modification_reprojette_les_lignes(): void
    {
        $ordonnance = $this->ordonnance();

        $ordonnance->update(['medicaments_json' => [['nom' => 'Ibuprofène 400 mg']]]);

        $lignes = $ordonnance->fresh()->lignes;

        $this->assertCount(1, $lignes);
        $this->assertSame('Ibuprofène 400 mg', $lignes[0]->nom);
    }

    /**
     * UNE PRESCRIPTION SERVIE NE SE RÉÉCRIT PAS. Reprojeter changerait ce à quoi une délivrance se
     * rattache — au mieux on perdrait la trace de ce qui a été servi, au pire on l'attacherait à un
     * autre médicament. La sauvegarde reste permise (le patient peut ajouter une photo du papier) ;
     * c'est la REPROJECTION qui n'a pas lieu.
     */
    public function test_une_ordonnance_deja_servie_n_est_plus_reprojetee(): void
    {
        $ordonnance = $this->ordonnance();
        $this->service()->delivrer(
            $this->pharmacien(), $ordonnance, [$ordonnance->lignes[0]->id => 5]
        );

        $ordonnance->update(['medicaments_json' => [['nom' => 'Autre chose']]]);

        $lignes = $ordonnance->fresh()->lignes;

        $this->assertCount(2, $lignes);
        $this->assertSame('Paracétamol 500 mg', $lignes[0]->nom);
    }

    /**
     * LA DÉCISION QUE B2-c AVAIT RENVOYÉE : l'identité du produit est en clair — sans quoi ni la
     * délivrance, ni les interactions, ni le §7.6 ne sont possibles — et ce qui décrit le
     * traitement de la personne reste chiffré.
     */
    public function test_l_identite_du_produit_est_en_clair_et_le_traitement_chiffre(): void
    {
        $ordonnance = $this->ordonnance();
        $brut = DB::table('ordonnance_lignes')->where('ordonnance_id', $ordonnance->id)->first();

        $this->assertSame('Paracétamol 500 mg', $brut->nom);
        $this->assertNotSame('1 cp x3/j', $brut->posologie);
        $this->assertStringNotContainsString('cp x3', (string) $brut->posologie);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Le jeton
    // ─────────────────────────────────────────────────────────────────────────

    public function test_le_jeton_est_pose_par_le_serveur_et_ne_sort_pas_du_carnet(): void
    {
        $ordonnance = $this->ordonnance();

        $this->assertNotNull($ordonnance->jeton_partage);
        $this->assertSame(48, strlen((string) $ordonnance->jeton_partage));
        // `$hidden` : il ne fuit pas dans une lecture ordinaire du carnet.
        $this->assertArrayNotHasKey('jeton_partage', $ordonnance->toArray());
    }

    public function test_le_jeton_ne_peut_pas_etre_choisi_par_le_client(): void
    {
        $ordonnance = $this->patient()->ordonnances()->create([
            'medecin_nom' => 'Dr Kablan Koffi',
            'structure_sanitaire' => 'CHU de Cocody',
            'date_prescription' => '2026-09-03',
            'medicaments_json' => [['nom' => 'Paracétamol 500 mg']],
            'jeton_partage' => 'jeton-choisi-par-le-client',
        ]);

        $this->assertNotSame('jeton-choisi-par-le-client', $ordonnance->jeton_partage);
    }

    public function test_un_jeton_inconnu_ne_designe_rien(): void
    {
        $this->ordonnance();

        $this->assertNull($this->service()->ordonnancePourJeton('un-jeton-invente'));
        $this->assertNull($this->service()->ordonnancePourJeton(''));
        $this->assertNull($this->service()->ordonnancePourJeton(null));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // LE VECTEUR CENTRAL : ce que le pharmacien NE voit PAS
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * SERVIR UNE ORDONNANCE N'OUVRE AUCUN DOSSIER. Aucune ligne de journal d'accès n'est créée,
     * parce qu'aucun accès n'a lieu : le pharmacien atteint l'ordonnance par son jeton et rien
     * d'autre n'est joignable depuis là.
     */
    public function test_servir_une_ordonnance_n_ouvre_aucun_dossier(): void
    {
        $ordonnance = $this->ordonnance();

        $this->service()->ordonnancePourJeton($ordonnance->jeton_partage);
        $this->service()->delivrer(
            $this->pharmacien(), $ordonnance, [$ordonnance->lignes[0]->id => 20]
        );

        $this->assertDatabaseCount('acces_dossier', 0);
        $this->assertNull(session('dossier_ouvert'));
    }

    /** Le chemin HTTP réel : un jeton inconnu répond 404, jamais 403 (anti-énumération, P10a). */
    public function test_un_jeton_inconnu_repond_404_et_jamais_403(): void
    {
        $reponse = $this->actingAs($this->pharmacien(), 'web')
            ->get(route('portail.delivrance.montrer', ['jeton' => 'jeton-invente']));

        $reponse->assertNotFound();
        $this->assertNotSame(403, $reponse->getStatusCode());
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Les gardes de la délivrance — chacune son vecteur, chacune son message
    // ─────────────────────────────────────────────────────────────────────────

    public function test_un_compte_non_habilite_ne_sert_pas(): void
    {
        $ordonnance = $this->ordonnance();

        $this->attendRefus(
            fn () => $this->service()->delivrer(
                $this->pharmacien(habilite: false), $ordonnance, [$ordonnance->lignes[0]->id => 1]
            ),
            "Vous n'êtes pas habilité à servir une ordonnance."
        );

        $this->assertDatabaseCount('delivrances', 0);
    }

    /** Le compte est ici pleinement habilité : seule la nature de la structure refuse. */
    public function test_une_ordonnance_ne_se_sert_pas_dans_un_laboratoire(): void
    {
        $ordonnance = $this->ordonnance();
        $laborantin = $this->pharmacien(officine: $this->officine('laboratoire'));

        $this->attendRefus(
            fn () => $this->service()->delivrer($laborantin, $ordonnance, [$ordonnance->lignes[0]->id => 1]),
            'Une ordonnance ne se sert que dans une pharmacie.'
        );
    }

    public function test_une_ordonnance_sans_lignes_n_est_pas_servable(): void
    {
        $ordonnance = $this->ordonnance();
        // On simule une ordonnance d'avant B3-a : elle existe, elle n'a pas de lignes.
        $ordonnance->lignes()->delete();

        $this->attendRefus(
            fn () => $this->service()->delivrer($this->pharmacien(), $ordonnance->fresh(), [1 => 1]),
            'Cette ordonnance a été écrite avant la délivrance électronique : elle est consultable, '
            .'mais ne peut pas être servie ici.'
        );
    }

    public function test_une_ligne_d_une_autre_ordonnance_est_refusee(): void
    {
        $ordonnance = $this->ordonnance();
        $autre = $this->ordonnance();

        $this->attendRefus(
            fn () => $this->service()->delivrer(
                $this->pharmacien(), $ordonnance, [$autre->lignes[0]->id => 1]
            ),
            'Un des médicaments servis n\'appartient pas à cette ordonnance.'
        );

        $this->assertDatabaseCount('delivrances', 0);
    }

    public function test_on_ne_sert_pas_plus_que_ce_qui_est_prescrit(): void
    {
        $ordonnance = $this->ordonnance();

        $this->attendRefus(
            fn () => $this->service()->delivrer(
                $this->pharmacien(), $ordonnance, [$ordonnance->lignes[0]->id => 21]
            ),
            '« Paracétamol 500 mg » : il ne reste que 20 à servir sur cette ordonnance.'
        );
    }

    public function test_une_delivrance_vide_est_refusee(): void
    {
        $ordonnance = $this->ordonnance();

        $this->attendRefus(
            fn () => $this->service()->delivrer(
                $this->pharmacien(), $ordonnance, [$ordonnance->lignes[0]->id => 0]
            ),
            'Indiquez au moins un médicament servi.'
        );

        $this->assertDatabaseCount('delivrances', 0);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // La délivrance partielle — le cas normal
    // ─────────────────────────────────────────────────────────────────────────

    public function test_une_delivrance_partielle_laisse_le_reste_a_servir(): void
    {
        $ordonnance = $this->ordonnance();
        $premiere = $ordonnance->lignes[0];

        $this->service()->delivrer($this->pharmacien(), $ordonnance, [$premiere->id => 8]);

        $this->assertSame(8, $premiere->fresh()->quantiteDelivree());
        $this->assertSame(12, $premiere->fresh()->resteAServir());
        $this->assertDatabaseCount('delivrances', 1);
        $this->assertDatabaseCount('delivrance_lignes', 1);
    }

    /** Le patient repasse chercher le manquant : deux délivrances sur la même ligne. */
    public function test_une_seconde_delivrance_complete_la_premiere(): void
    {
        $ordonnance = $this->ordonnance();
        $ligne = $ordonnance->lignes[0];
        $pharmacien = $this->pharmacien();

        $this->service()->delivrer($pharmacien, $ordonnance, [$ligne->id => 8]);
        $this->service()->delivrer($pharmacien, $ordonnance->fresh(), [$ligne->id => 12]);

        $this->assertSame(20, $ligne->fresh()->quantiteDelivree());
        $this->assertSame(0, $ligne->fresh()->resteAServir());
        $this->assertDatabaseCount('delivrances', 2);
    }

    /**
     * Sans quantité prescrite, on ne borne pas ce qu'on ne sait pas : `null` n'est pas zéro
     * (précédent P10c-3-ii).
     */
    public function test_sans_quantite_prescrite_le_reste_est_inconnu_et_rien_n_est_borne(): void
    {
        $ordonnance = $this->ordonnance([['nom' => 'Sirop pour la toux', 'posologie' => '5 mL x3/j']]);
        $ligne = $ordonnance->lignes[0];

        $this->assertNull($ligne->resteAServir());

        $this->service()->delivrer($this->pharmacien(), $ordonnance, [$ligne->id => 3]);

        $this->assertSame(3, $ligne->fresh()->quantiteDelivree());
        $this->assertNull($ligne->fresh()->resteAServir());
    }

    public function test_l_acte_nomme_son_auteur_et_son_officine(): void
    {
        $officine = $this->officine();
        $pharmacien = $this->pharmacien(officine: $officine);
        $ordonnance = $this->ordonnance();

        $delivrance = $this->service()->delivrer(
            $pharmacien, $ordonnance, [$ordonnance->lignes[0]->id => 5]
        );

        $this->assertSame($officine->id, $delivrance->structure_id);
        $this->assertSame($pharmacien->id, $delivrance->pharmacien_user_id);
        $this->assertSame($pharmacien->nomLisible(), $delivrance->pharmacien_nom);
        $this->assertNotSame('', trim($delivrance->pharmacien_nom));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Les gardes du moteur
    // ─────────────────────────────────────────────────────────────────────────

    public function test_le_moteur_refuse_une_ligne_d_une_autre_ordonnance(): void
    {
        $ordonnance = $this->ordonnance();
        $autre = $this->ordonnance();
        $delivrance = $this->service()->delivrer(
            $this->pharmacien(), $ordonnance, [$ordonnance->lignes[0]->id => 1]
        );

        $this->expectException(QueryException::class);

        DB::table('delivrance_lignes')->insert([
            'delivrance_id' => $delivrance->id,
            'ordonnance_ligne_id' => $autre->lignes[0]->id,
            'quantite' => 1,
        ]);
    }

    public function test_le_moteur_refuse_une_quantite_nulle(): void
    {
        $ordonnance = $this->ordonnance();
        $delivrance = $this->service()->delivrer(
            $this->pharmacien(), $ordonnance, [$ordonnance->lignes[0]->id => 1]
        );

        $this->expectException(QueryException::class);

        DB::table('delivrance_lignes')->insert([
            'delivrance_id' => $delivrance->id,
            'ordonnance_ligne_id' => $ordonnance->lignes[1]->id,
            'quantite' => 0,
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
