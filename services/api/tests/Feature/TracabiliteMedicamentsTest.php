<?php

namespace Tests\Feature;

use App\Models\Delivrance;
use App\Models\Medicament;
use App\Models\MembreFamille;
use App\Models\Ordonnance;
use App\Models\StructureSanitaire;
use App\Models\TraceDispensation;
use App\Models\User;
use App\Services\Medicament\AttributeurCodeMedicament;
use App\Services\Medicament\ReglesCodeBarres;
use App\Services\Medicament\ServiceCodeBarres;
use App\Services\Medicament\ServiceDelivrance;
use App\Services\Medicament\ServiceTracabiliteMedicament;
use App\Services\Referentiel\EmpreinteReferentiel;
use App\Services\Referentiel\SourceMedicaments;
use Database\Seeders\PortailRolesSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * B3-c — Code-barres + traçabilité nationale des médicaments (CDC_11 §7.6).
 *
 * CE QUE CETTE SUITE PROTÈGE. Le §7.6 tient en une phrase (« lutte contre les médicaments
 * falsifiés, suivi de consommation, statistiques nationales ») : trois finalités, aucun mécanisme.
 * DEUX VECTEURS CENTRAUX, aucun ne suffisant seul : le registre national **survit** à la
 * suppression de l'ordonnance qui l'a produit (E1) — et il ne porte **aucune donnée nominative**,
 * ce qui rend cette survie acceptable (E2). Un falsificateur recopie un code-barres : le scan
 * reconnaît un code au référentiel, il ne certifie jamais l'authenticité d'une boîte (E5).
 */
class TracabiliteMedicamentsTest extends TestCase
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
            'identifiant_national' => 'ETS000042',
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

    /** Un agent porteur de l'habilitation nationale, accordée nominativement (aucun rôle ne la porte). */
    private function agentReferentiel(): User
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $user = User::factory()->create();
        $user->givePermissionTo('medicament.referentiel');

        return $user->fresh();
    }

    /** Un gestionnaire d'officine : `medicament.manage`, jamais `medicament.referentiel`. */
    private function gestionnaireOfficine(): User
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $user = User::factory()->create();
        $user->assignRole('gestionnaire_etablissement');

        return $user->fresh();
    }

    private function patient(): MembreFamille
    {
        return MembreFamille::factory()->for(User::factory()->create())->create();
    }

    private function medicament(array $remplacements = []): Medicament
    {
        return Medicament::create(array_merge([
            'nom_generique' => 'Paracétamol',
            'categorie'     => 'Analgésique',
        ], $remplacements));
    }

    /** Un médicament dont le CODE NATIONAL est réellement attribué, pas seulement créé. */
    private function medicamentAvecCode(array $remplacements = []): Medicament
    {
        $medicament = $this->medicament($remplacements);
        app(AttributeurCodeMedicament::class)->attribuer($medicament);

        return $medicament->fresh();
    }

    /**
     * Une ordonnance dont les lignes sont DÉJÀ RÉSOLUES au référentiel — le contrat de
     * `medicaments_json` après passage par `ServiceLienMedicament` (P6.6b), reproduit ici sans
     * emprunter ce chemin, exactement comme `DelivranceOrdonnanceTest`.
     */
    private function ordonnance(array $medicaments): Ordonnance
    {
        return $this->patient()->ordonnances()->create([
            'medecin_nom' => 'Dr Kablan Koffi',
            'structure_sanitaire' => 'CHU de Cocody',
            'date_prescription' => '2026-09-03',
            'medicaments_json' => $medicaments,
        ])->fresh();
    }

    /** Une ligne rattachée au référentiel — les valeurs figées telles que P6.6b les écrirait. */
    private function ligneRattachee(Medicament $medicament, string $nom, int $quantite): array
    {
        return [
            'nom' => $nom,
            'posologie' => '1 cp x3/j',
            'quantite' => $quantite,
            'medicament_id' => $medicament->id,
            'code_national' => $medicament->code,
            'dci' => $medicament->nom_generique,
            'dosage_referentiel' => $medicament->dosage,
        ];
    }

    private function delivrances(): ServiceDelivrance
    {
        return app(ServiceDelivrance::class);
    }

    private function tracabilite(): ServiceTracabiliteMedicament
    {
        return app(ServiceTracabiliteMedicament::class);
    }

    private function codesBarres(): ServiceCodeBarres
    {
        return app(ServiceCodeBarres::class);
    }

    private function source(): SourceMedicaments
    {
        return new SourceMedicaments;
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // Gouvernance du code-barres
    // ─────────────────────────────────────────────────────────────────────────────

    public function test_un_code_barres_non_gtin_est_refuse_par_son_message(): void
    {
        try {
            $this->codesBarres()->assertSaisieValide('123456789');
            $this->fail('Un code de 9 chiffres a été accepté.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('code_barres', $e->errors());
        }
    }

    public function test_un_doublon_de_code_barres_dans_le_meme_pays_est_refuse_par_le_moteur(): void
    {
        $this->medicament(['nom_generique' => 'Ibuprofène', 'code_barres' => '4006381333931']);

        $this->expectException(QueryException::class);

        $this->medicament(['nom_generique' => 'Paracétamol', 'code_barres' => '4006381333931']);
    }

    public function test_le_meme_gtin_est_accepte_dans_un_autre_pays(): void
    {
        // `pays_code` est HORS `$fillable` (Medicament, précédent P6.5a) : on le pose par
        // `forceFill`, patron exact de `test_le_meme_code_dans_deux_pays_n_est_pas_un_doublon`
        // (ReferentielMedicamentsTest).
        $this->medicament(['nom_generique' => 'Ibuprofène', 'code_barres' => '4006381333931']);

        $senegalais = $this->medicament(['nom_generique' => 'Ibuprofène']);
        $senegalais->forceFill(['pays_code' => 'SN'])->save();
        $senegalais->update(['code_barres' => '4006381333931']);

        $this->assertSame('4006381333931', $senegalais->fresh()->code_barres);
    }

    public function test_sans_la_permission_du_referentiel_la_saisie_est_refusee(): void
    {
        $medicament = $this->medicament();
        $agent = $this->gestionnaireOfficine();

        $this->actingAs($agent, 'web')
            ->put(route('portail.medicaments.update', $medicament), [
                'nom_generique' => 'Paracétamol', 'categorie' => 'Analgésique',
                'statut_marche' => 'autorise', 'code_barres' => '4006381333931',
            ])
            ->assertForbidden();

        $this->assertNull($medicament->fresh()->code_barres);
    }

    public function test_l_agent_habilite_peut_enregistrer_un_code_barres_valide(): void
    {
        $medicament = $this->medicament();
        $agent = $this->agentReferentiel();

        $this->actingAs($agent, 'web')
            ->put(route('portail.medicaments.update', $medicament), [
                'nom_generique' => 'Paracétamol', 'categorie' => 'Analgésique',
                'statut_marche' => 'autorise', 'code_barres' => '4006381333931',
            ])
            ->assertRedirect();

        $this->assertSame('4006381333931', $medicament->fresh()->code_barres);
    }

    public function test_une_saisie_malformee_est_refusee_par_son_message_a_l_ecran(): void
    {
        $medicament = $this->medicament();
        $agent = $this->agentReferentiel();

        $this->actingAs($agent, 'web')
            ->put(route('portail.medicaments.update', $medicament), [
                'nom_generique' => 'Paracétamol', 'categorie' => 'Analgésique',
                'statut_marche' => 'autorise', 'code_barres' => '4006381333932', // clé fausse
            ])
            ->assertSessionHasErrors('code_barres');

        $this->assertNull($medicament->fresh()->code_barres);
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // Le registre — servir inscrit des traces
    // ─────────────────────────────────────────────────────────────────────────────

    public function test_servir_deux_lignes_inscrit_deux_traces(): void
    {
        $a = $this->medicamentAvecCode(['nom_generique' => 'Paracétamol']);
        $b = $this->medicamentAvecCode(['nom_generique' => 'Amoxicilline']);

        $ordonnance = $this->ordonnance([
            $this->ligneRattachee($a, 'Paracétamol 500 mg', 20),
            $this->ligneRattachee($b, 'Amoxicilline 1 g', 14),
        ]);

        $pharmacien = $this->pharmacien();
        $lignes = $ordonnance->lignes()->pluck('id');

        $this->delivrances()->delivrer($pharmacien, $ordonnance, [
            $lignes[0] => 20,
            $lignes[1] => 14,
        ]);

        $this->assertSame(2, TraceDispensation::count());
    }

    public function test_une_ligne_non_rattachee_inscrit_une_trace_a_code_null(): void
    {
        // E8 — aucun `medicament_id` : le lien au référentiel est facultatif (B3-a). La trace
        // s'inscrit quand même — ne rien écrire rendrait la consommation nationale fausse en
        // silence.
        $ordonnance = $this->ordonnance([
            ['nom' => 'Sirop non identifié', 'posologie' => '1 c. à café', 'quantite' => 1],
        ]);

        $pharmacien = $this->pharmacien();
        $ligneId = $ordonnance->lignes()->value('id');

        $this->delivrances()->delivrer($pharmacien, $ordonnance, [$ligneId => 1]);

        $trace = TraceDispensation::sole();
        $this->assertNull($trace->medicament_code);
        $this->assertNull($trace->medicament_id);
        $this->assertSame('Sirop non identifié', $trace->medicament_nom);
    }

    public function test_vecteur_central_supprimer_l_ordonnance_laisse_la_trace_intacte(): void
    {
        $medicament = $this->medicamentAvecCode();
        $ordonnance = $this->ordonnance([$this->ligneRattachee($medicament, 'Paracétamol 500 mg', 20)]);
        $pharmacien = $this->pharmacien();
        $ligneId = $ordonnance->lignes()->value('id');

        $this->delivrances()->delivrer($pharmacien, $ordonnance, [$ligneId => 20]);

        $this->assertSame(1, TraceDispensation::count());
        $this->assertSame(1, Delivrance::count());
        $traceAvant = TraceDispensation::sole()->replicate();

        // Le patient est maître de son carnet (loi 2013-450) : supprimer l'ordonnance cascade sur
        // `ordonnance_lignes`, puis sur `delivrances` et `delivrance_lignes`.
        $ordonnance->delete();

        $this->assertSame(0, Ordonnance::count());
        $this->assertSame(0, Delivrance::count());

        $traceApres = TraceDispensation::sole();
        $this->assertSame($traceAvant->medicament_nom, $traceApres->medicament_nom);
        $this->assertSame($traceAvant->medicament_code, $traceApres->medicament_code);
        $this->assertSame($traceAvant->quantite, $traceApres->quantite);
    }

    public function test_vecteur_central_la_trace_ne_porte_aucune_donnee_nominative(): void
    {
        $medicament = $this->medicamentAvecCode();
        $patient = $this->patient();
        $patient->update(['nom' => 'Kouassi-Zébrissime', 'prenom' => 'Adjoua-Singulier']);
        $ordonnance = $patient->ordonnances()->create([
            'medecin_nom' => 'Dr Kablan Koffi',
            'structure_sanitaire' => 'CHU de Cocody',
            'date_prescription' => '2026-09-03',
            'medicaments_json' => [$this->ligneRattachee($medicament, 'Paracétamol 500 mg', 20)],
        ])->fresh();
        $pharmacien = $this->pharmacien();
        $ligneId = $ordonnance->lignes()->value('id');

        $this->delivrances()->delivrer($pharmacien, $ordonnance, [$ligneId => 20]);

        // On cherche, dans TOUTE la ligne persistée, un fragment identifiant la personne — motif du
        // vecteur anti-fuite de P7-D1. Un nom distinctif évite qu'une sous-chaîne banale (un « 1 »
        // d'identifiant technique) fasse échouer le test pour la mauvaise raison.
        $trace = DB::table('traces_dispensation')->first();
        $charge = json_encode($trace);

        $this->assertStringNotContainsString('Kouassi-Zébrissime', $charge, 'le nom du patient fuit.');
        $this->assertStringNotContainsString('Adjoua-Singulier', $charge, 'le prénom du patient fuit.');
        $this->assertStringNotContainsString('1 cp x3/j', $charge, 'la posologie fuit.');

        // Structurellement, aucune de ces colonnes n'existe sur la table (E2) : ce n'est pas une
        // fuite qu'on masque au rendu, la colonne elle-même n'a jamais été créée.
        $this->assertObjectNotHasProperty('membre_id', $trace);
        $this->assertObjectNotHasProperty('ordonnance_id', $trace);
        $this->assertObjectNotHasProperty('posologie', $trace);
        $this->assertObjectNotHasProperty('duree', $trace);
        $this->assertObjectNotHasProperty('instructions', $trace);
    }

    public function test_retirer_le_produit_du_referentiel_laisse_le_code_et_le_nom_figes(): void
    {
        $medicament = $this->medicamentAvecCode(['nom_generique' => 'Paracétamol']);
        $ordonnance = $this->ordonnance([$this->ligneRattachee($medicament, 'Paracétamol 500 mg', 20)]);
        $pharmacien = $this->pharmacien();
        $ligneId = $ordonnance->lignes()->value('id');

        $this->delivrances()->delivrer($pharmacien, $ordonnance, [$ligneId => 20]);

        $codeAvant = $medicament->fresh()->code;
        $idAvant = $medicament->id;

        // `medicament_id` N'EST PAS une clé étrangère (défaut trouvé au G3, voir la migration) :
        // la suppression du produit ne lève RIEN — une FK `nullOnDelete` aurait fait exécuter par
        // le moteur une mise à NULL sur cette ligne, qu'un déclencheur append-only bloquant tout
        // aurait alors refusée, empêchant purement et simplement de retirer un produit.
        $medicament->delete();

        $this->assertSame(0, Medicament::count());

        $trace = TraceDispensation::sole();
        $this->assertSame($idAvant, $trace->medicament_id, 'medicament_id reste un identifiant, même devenu orphelin.');
        $this->assertSame($codeAvant, $trace->medicament_code);
        $this->assertSame('Paracétamol 500 mg', $trace->medicament_nom);
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // Append-only, à deux niveaux
    // ─────────────────────────────────────────────────────────────────────────────

    public function test_le_modele_refuse_la_modification_d_une_trace(): void
    {
        $medicament = $this->medicament();
        $ordonnance = $this->ordonnance([$this->ligneRattachee($medicament, 'Paracétamol 500 mg', 20)]);
        $pharmacien = $this->pharmacien();
        $ligneId = $ordonnance->lignes()->value('id');
        $this->delivrances()->delivrer($pharmacien, $ordonnance, [$ligneId => 20]);

        $trace = TraceDispensation::sole();

        $this->expectException(RuntimeException::class);
        $trace->quantite = 999;
        $trace->save();
    }

    public function test_le_modele_refuse_la_suppression_d_une_trace(): void
    {
        $medicament = $this->medicament();
        $ordonnance = $this->ordonnance([$this->ligneRattachee($medicament, 'Paracétamol 500 mg', 20)]);
        $pharmacien = $this->pharmacien();
        $ligneId = $ordonnance->lignes()->value('id');
        $this->delivrances()->delivrer($pharmacien, $ordonnance, [$ligneId => 20]);

        $trace = TraceDispensation::sole();

        $this->expectException(RuntimeException::class);
        $trace->delete();
    }

    public function test_le_moteur_refuse_une_quantite_nulle_meme_par_acces_direct(): void
    {
        // Le MODÈLE n'impose rien sur `quantite` : c'est le MOTEUR qui garde, y compris contre un
        // accès qui contournerait Eloquent (import, correction directe) — précédent B3-b.
        $this->expectException(QueryException::class);

        DB::table('traces_dispensation')->insert([
            'medicament_nom' => 'Test', 'quantite' => 0, 'dispensee_le' => now(), 'created_at' => now(),
        ]);
    }

    public function test_idempotence_par_l_unicite_du_lien_de_delivrance(): void
    {
        DB::table('traces_dispensation')->insert([
            'medicament_nom' => 'Paracétamol', 'quantite' => 5, 'dispensee_le' => now(),
            'delivrance_ligne_id' => 42, 'created_at' => now(),
        ]);

        $this->expectException(QueryException::class);

        DB::table('traces_dispensation')->insert([
            'medicament_nom' => 'Paracétamol', 'quantite' => 5, 'dispensee_le' => now(),
            'delivrance_ligne_id' => 42, 'created_at' => now(),
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // Le scan (E5, E9)
    // ─────────────────────────────────────────────────────────────────────────────

    public function test_un_code_connu_identifie_le_produit(): void
    {
        $medicament = $this->medicament(['code_barres' => '4006381333931']);

        $trouve = $this->codesBarres()->identifier('4006381333931');

        $this->assertNotNull($trouve);
        $this->assertSame($medicament->id, $trouve->id);
    }

    public function test_un_code_inconnu_ne_leve_rien_et_ne_bloque_rien(): void
    {
        $this->medicament(['code_barres' => '4006381333931']);

        // Un GTIN bien formé, mais jamais attribué : `identifier()` répond `null`, jamais une
        // exception — E5, la délivrance ne s'arrête pas là-dessus.
        $this->assertNull($this->codesBarres()->identifier('96385074'));
    }

    public function test_un_code_mal_forme_au_scan_repond_null(): void
    {
        $this->assertNull($this->codesBarres()->identifier('pas-un-code'));
        $this->assertNull($this->codesBarres()->identifier(''));
        $this->assertNull($this->codesBarres()->identifier(null));
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // Miroirs de projection — E4
    // ─────────────────────────────────────────────────────────────────────────────

    public function test_renseigner_un_code_barres_fait_diverger_le_referentiel(): void
    {
        $medicament = $this->medicament();
        $avant = EmpreinteReferentiel::duContenu($this->source()->extraire());

        $medicament->update(['code_barres' => '4006381333931']);

        $apres = EmpreinteReferentiel::duContenu($this->source()->extraire());
        $this->assertNotSame($avant, $apres, 'Un code-barres renseigné n\'a pas fait diverger le référentiel.');
    }

    public function test_une_dispensation_ne_fait_pas_diverger_le_referentiel(): void
    {
        $medicament = $this->medicament();
        $ordonnance = $this->ordonnance([$this->ligneRattachee($medicament, 'Paracétamol 500 mg', 20)]);
        $pharmacien = $this->pharmacien();
        $ligneId = $ordonnance->lignes()->value('id');

        $avant = EmpreinteReferentiel::duContenu($this->source()->extraire());

        $this->delivrances()->delivrer($pharmacien, $ordonnance, [$ligneId => 20]);

        $apres = EmpreinteReferentiel::duContenu($this->source()->extraire());
        $this->assertSame($avant, $apres, 'Une dispensation a fait diverger le référentiel national.');
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // Statistiques — dérivées, jamais stockées
    // ─────────────────────────────────────────────────────────────────────────────

    public function test_la_consommation_est_agregee_par_produit(): void
    {
        // `consommation()` filtre sur `medicament_code IS NOT NULL` : un code réellement attribué
        // est nécessaire pour que la ligne apparaisse dans l'agrégat (sinon elle compte parmi les
        // non-rattachées, testées séparément ci-dessous).
        $medicament = $this->medicamentAvecCode(['nom_generique' => 'Paracétamol']);
        $ordonnance = $this->ordonnance([$this->ligneRattachee($medicament, 'Paracétamol 500 mg', 20)]);
        $pharmacien = $this->pharmacien();
        $ligneId = $ordonnance->lignes()->value('id');
        $this->delivrances()->delivrer($pharmacien, $ordonnance, [$ligneId => 12]);

        $consommation = $this->tracabilite()->consommation();

        $this->assertCount(1, $consommation['par_produit']);
        $this->assertSame(12, $consommation['par_produit'][0]['quantite']);
        $this->assertSame(1, $consommation['par_produit'][0]['dispensations']);
    }

    public function test_le_compteur_des_non_rattachees(): void
    {
        $ordonnance = $this->ordonnance([
            ['nom' => 'Sirop non identifié', 'posologie' => '—', 'quantite' => 1],
        ]);
        $pharmacien = $this->pharmacien();
        $ligneId = $ordonnance->lignes()->value('id');
        $this->delivrances()->delivrer($pharmacien, $ordonnance, [$ligneId => 1]);

        $this->assertSame(1, $this->tracabilite()->consommation()['non_rattachees']);
    }

    public function test_la_couverture_code_barres(): void
    {
        $this->medicament(['nom_generique' => 'A', 'code_barres' => '4006381333931']);
        $this->medicament(['nom_generique' => 'B']);

        $couverture = $this->tracabilite()->couvertureCodeBarres();

        $this->assertSame(1, $couverture['avec_code_barres']);
        $this->assertSame(2, $couverture['total']);
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // La classe pure, en réutilisation directe (couverte en isolation par ReglesCodeBarresTest)
    // ─────────────────────────────────────────────────────────────────────────────

    public function test_le_service_delegue_bien_a_la_classe_pure(): void
    {
        $this->assertTrue(ReglesCodeBarres::estGtin('4006381333931'));
        $this->assertNotNull($this->codesBarres()->identifier(null) === null);
    }
}
