<?php

namespace Tests\Feature;

use App\Models\AlerteDerive;
use App\Models\ExportJeuEntrainement;
use App\Models\JeuDonneesEntrainement;
use App\Models\MetriqueModeleIa;
use App\Models\PredictionIa;
use App\Models\Triage;
use App\Models\TriageConstante;
use App\Models\TriageReponse;
use App\Models\User;
use App\Models\VersionModeleIa;
use App\Notifications\NotificationMasante;
use App\Services\Triage\JournalPredictionIa;
use App\Services\Triage\ReglesDerive;
use App\Services\Triage\ServiceComparaisonModeleIa;
use App\Services\Triage\ServiceDeriveModeleIa;
use App\Services\Triage\ServiceExportJeuEntrainement;
use App\Support\NiveauTriage;
use App\Support\RegistreRetourTriage;
use App\Support\StatutVersionModeleIa;
use App\Support\TypeNotification;
use Database\Seeders\PortailRolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * P10c-3-ii lot B — comparaison prédiction ⇄ verdict, et surveillance de dérive (CDC_05 §8).
 *
 * ═══ CE QUE CETTE SUITE PROTÈGE ═══
 *
 *   1. **on ne départage jamais deux verdicts** d'un même triage — les taire fausserait la mesure
 *      dans le sens le plus flatteur ;
 *   2. **`null` n'est pas `0`** — un rappel « 0 % » alors qu'aucun sous-triage n'est survenu serait
 *      une accusation gratuite ;
 *   3. **détection seule** — aucune dérive ne retire un modèle du service ;
 *   4. **le silence est une réponse** — une journée stable n'écrit rien.
 */
class DeriveModeleIaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PortailRolesSeeder::class);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // ReglesDerive — la classe pure

    public function test_psi_est_nul_sur_deux_distributions_identiques(): void
    {
        $distribution = ['a' => 50, 'b' => 30, 'c' => 20];

        $this->assertSame(0.0, ReglesDerive::psi($distribution, $distribution));
    }

    public function test_psi_grandit_avec_l_ecart(): void
    {
        $reference = ['a' => 50, 'b' => 50];

        $leger = ReglesDerive::psi($reference, ['a' => 55, 'b' => 45]);
        $fort = ReglesDerive::psi($reference, ['a' => 90, 'b' => 10]);

        $this->assertGreaterThan(0, $leger);
        $this->assertGreaterThan($leger, $fort);
    }

    public function test_une_categorie_absente_ne_fait_pas_exploser_l_indice(): void
    {
        // ═══ LE LISSAGE N'EST PAS COSMÉTIQUE ═══
        //
        // PSI divise et prend un logarithme : sans lissage, `ln(0)` rendrait l'infini, et **une
        // seule catégorie jamais rencontrée noierait la vraie dérive** sous un chiffre
        // ininterprétable.
        $psi = ReglesDerive::psi(['a' => 50, 'b' => 50], ['a' => 100]);

        $this->assertIsFloat($psi);
        $this->assertTrue(is_finite($psi), 'un indice infini ne se lit pas');
    }

    public function test_psi_est_null_et_non_zero_sur_un_echantillon_vide(): void
    {
        // Un indice calculé sur rien n'est pas nul : il n'existe pas. L'afficher « 0,00 » dirait
        // « aucune dérive » là où la vérité est « aucune mesure ».
        $this->assertNull(ReglesDerive::psi([], ['a' => 10]));
        $this->assertNull(ReglesDerive::psi(['a' => 10], []));
    }

    public function test_la_chute_de_rappel_ne_se_lit_que_dans_un_sens(): void
    {
        // Un rappel qui MONTE n'est pas une dérive à signaler ; signaler symétriquement noierait le
        // seul cas dangereux sous des alertes sans conséquence.
        $this->assertSame(0.3, ReglesDerive::chuteDeRappel(0.8, 0.5));
        $this->assertSame(0.0, ReglesDerive::chuteDeRappel(0.5, 0.8));
        $this->assertNull(ReglesDerive::chuteDeRappel(null, 0.5));
        $this->assertNull(ReglesDerive::chuteDeRappel(0.5, null));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // La comparaison

    public function test_deux_verdicts_sur_un_meme_triage_comptent_deux_fois(): void
    {
        // Cohérence stricte avec F13 : écarter l'un reviendrait à choisir à la place du médecin qui
        // l'a validé. Un médecin qui se ravise dit quelque chose.
        $version = $this->versionActive();
        $triage = $this->triage();

        $this->prediction($triage, 'adaptee');
        $this->ligneApprentissage($triage, RegistreRetourTriage::ADAPTEE);
        $this->ligneApprentissage($triage, RegistreRetourTriage::SOUS_TRIAGE);

        $comparaison = app(ServiceComparaisonModeleIa::class)->pour($version);

        $this->assertSame(2, $comparaison['nb_couples']);
        $this->assertSame(1, $comparaison['matrice']['adaptee']['adaptee']);
        $this->assertSame(1, $comparaison['matrice']['adaptee']['sous_triage']);
    }

    public function test_le_rappel_sous_triage_est_null_quand_aucun_cas_n_est_survenu(): void
    {
        // `null` et non `0` : afficher « 0 % » alors qu'il n'y a rien à rappeler serait une
        // accusation gratuite — même prudence que `zero_division=0` à l'entraînement.
        $version = $this->versionActive();
        $triage = $this->triage();
        $this->prediction($triage, 'adaptee');
        $this->ligneApprentissage($triage, RegistreRetourTriage::ADAPTEE);

        $this->assertNull(app(ServiceComparaisonModeleIa::class)->pour($version)['rappel_sous_triage_production']);
    }

    public function test_le_rappel_sous_triage_en_production_est_calcule_quand_il_y_a_des_cas(): void
    {
        $version = $this->versionActive();

        // Deux sous-triages réels, un seul vu par le modèle → rappel = 0,5.
        $vu = $this->triage();
        $this->prediction($vu, 'sous_triage');
        $this->ligneApprentissage($vu, RegistreRetourTriage::SOUS_TRIAGE);

        $rate = $this->triage();
        $this->prediction($rate, 'adaptee');
        $this->ligneApprentissage($rate, RegistreRetourTriage::SOUS_TRIAGE);

        $this->assertSame(0.5, app(ServiceComparaisonModeleIa::class)->pour($version)['rappel_sous_triage_production']);
    }

    public function test_seules_les_predictions_du_modele_examine_sont_comptees(): void
    {
        $version = $this->versionActive('run_a');
        $triage = $this->triage();
        $this->prediction($triage, 'adaptee', 'run_b'); // un AUTRE modèle
        $this->ligneApprentissage($triage, RegistreRetourTriage::ADAPTEE);

        $this->assertSame(0, app(ServiceComparaisonModeleIa::class)->pour($version)['nb_couples']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // La dérive

    public function test_sans_modele_actif_on_le_dit_plutot_que_de_rendre_un_rapport_vide(): void
    {
        // Un rapport vide ressemblerait à « tout va bien » ; il n'y a simplement rien à surveiller.
        $this->assertSame('aucun_modele_actif', app(ServiceDeriveModeleIa::class)->analyser()['statut']);
    }

    public function test_un_echantillon_vide_ne_produit_aucun_indice(): void
    {
        $this->versionActive();

        $rapport = app(ServiceDeriveModeleIa::class)->analyser();

        $this->assertSame('echantillon_insuffisant', $rapport['statut']);
        $this->assertSame(0, $rapport['alertes']);
    }

    public function test_une_population_identique_n_ecrit_aucune_alerte(): void
    {
        // ═══ LE SILENCE EST UNE RÉPONSE ═══
        //
        // Remplir la table de « stable » la rendrait illisible, et un rapport qu'on ne lit plus ne
        // prévient plus.
        $this->versionActive(instantane: $this->lignesExport(30, 34));
        $this->triagesReels(30, 34);

        $rapport = app(ServiceDeriveModeleIa::class)->analyser();

        $this->assertSame('analyse', $rapport['statut']);
        $this->assertSame(0, $rapport['alertes']);
        $this->assertSame(0, AlerteDerive::count());
    }

    public function test_une_population_deplacee_produit_une_alerte_qui_nomme_la_feature(): void
    {
        // Le modèle a appris sur des adultes ; la population d'aujourd'hui est âgée.
        $this->versionActive(instantane: $this->lignesExport(30, 34));
        $this->triagesReels(30, 78);

        $rapport = app(ServiceDeriveModeleIa::class)->analyser();

        $this->assertGreaterThan(0, $rapport['alertes']);

        $alerte = AlerteDerive::where('indicateur', 'bande_age')->first();
        $this->assertNotNull($alerte, 'la dérive doit NOMMER la feature qui a bougé');
        $this->assertSame('entree', $alerte->nature);
        $this->assertContains($alerte->niveau, ['leger', 'fort']);
        // Le détail porte les deux distributions comparées — des COMPTES, jamais des lignes.
        $this->assertArrayHasKey('reference', $alerte->detail_json);
        $this->assertArrayHasKey('observee', $alerte->detail_json);
    }

    public function test_une_derive_ne_desactive_jamais_le_modele(): void
    {
        // F39, la ligne tenue depuis ADR-017 : détection seule, jamais de gel.
        $version = $this->versionActive(instantane: $this->lignesExport(30, 34));
        $this->triagesReels(30, 78);

        app(ServiceDeriveModeleIa::class)->analyser();

        $this->assertSame(StatutVersionModeleIa::ACTIF, $version->fresh()->statut);
    }

    public function test_le_rapport_est_idempotent_sur_une_meme_journee(): void
    {
        $this->versionActive(instantane: $this->lignesExport(30, 34));
        $this->triagesReels(30, 78);

        $service = app(ServiceDeriveModeleIa::class);
        $service->analyser();
        $premier = AlerteDerive::count();
        $service->analyser();

        $this->assertSame($premier, AlerteDerive::count(), 'rejouer un rapport ne l\'empile pas');
    }

    public function test_rejouer_le_rapport_ne_reprevient_personne(): void
    {
        // ═══ DÉFAUT TROUVÉ AU G2 LIVE, ET AUCUN VECTEUR NE LE COUVRAIT ═══
        //
        // Les LIGNES étaient idempotentes, les NOTIFICATIONS non : le G2 a produit trois messages
        // identiques pour la même journée, parce que le rapport avait tourné trois fois. Un
        // contrôleur qui reçoit le même avertissement à chaque passage cesse de les lire — et
        // c'est ainsi qu'une alerte devient invisible.
        //
        // Précédent : le drapeau `notifiee` du routage de fraude (B1), le rejeu muet du partage en
        // masse (P7-D1).
        Notification::fake();
        $gouvernant = User::factory()->create();
        $gouvernant->givePermissionTo('ia_triage.valider');

        $this->versionActive(instantane: $this->lignesExport(30, 34));
        $this->triagesReels(30, 78);

        $service = app(ServiceDeriveModeleIa::class);
        $service->analyser();

        // Le nombre exact dépend du nombre de détenteurs de la permission — ce n'est PAS ce que ce
        // vecteur protège, et l'affirmer aurait été mesurer autre chose que la garantie visée.
        $apresLePremier = $this->nombreDeNotifications();
        $this->assertGreaterThan(0, $apresLePremier, 'la première dérive doit prévenir');

        // Le SECOND passage met les lignes à jour et ne dit rien de neuf.
        $service->analyser();

        $this->assertSame($apresLePremier, $this->nombreDeNotifications(),
            'rejouer un rapport ne doit reprévenir personne');
    }

    /**
     * Le nombre RÉEL de notifications émises.
     *
     * ═══ PIÈGE ATTRAPÉ PAR LA MUTATION, ET IL VALAIT LA PEINE ═══
     *
     * `Notification::sentNotifications()` rend un tableau imbriqué à quatre niveaux
     * (classe du destinataire → identifiant → classe de notification → envois). Un `count()` dessus
     * compte donc les **classes de destinataires** — toujours 1 ici, avant comme après. Le vecteur
     * passait en ne mesurant rien, et seule la mutation « on re-prévient à chaque passage » l'a
     * révélé en SURVIVANT.
     */
    private function nombreDeNotifications(): int
    {
        $total = 0;

        foreach (Notification::sentNotifications() as $parIdentifiant) {
            foreach ($parIdentifiant as $parClasse) {
                foreach ($parClasse as $envois) {
                    $total += count($envois);
                }
            }
        }

        return $total;
    }

    public function test_une_derive_previent_les_gouvernants(): void
    {
        Notification::fake();
        $gouvernant = User::factory()->create();
        $gouvernant->givePermissionTo('ia_triage.valider');

        $this->versionActive(instantane: $this->lignesExport(30, 34));
        $this->triagesReels(30, 78);

        app(ServiceDeriveModeleIa::class)->analyser();

        Notification::assertSentTo($gouvernant->fresh(), NotificationMasante::class,
            function ($notif): bool {
                // Aucun contenu clinique : la règle inviolable de P7-D1 vaut ici aussi.
                $this->assertStringNotContainsString('symptome', mb_strtolower($notif->corps));
                $this->assertStringNotContainsString('temperature', mb_strtolower($notif->corps));

                return $notif->type === TypeNotification::DERIVE_MODELE_IA;
            });
    }

    // ─────────────────────────────────────────────────────────────────────────

    private function versionActive(string $run = 'run_a', array $instantane = []): VersionModeleIa
    {
        $export = ExportJeuEntrainement::create([
            'pays_code' => 'CI', 'numero_export' => 1, 'instantane_json' => $instantane,
            'nb_lignes' => count($instantane), 'k_estime' => 3, 'cree_le' => now(),
        ]);

        $version = VersionModeleIa::create([
            'pays_code' => 'CI', 'numero_version' => 1, 'export_id' => $export->id,
            'statut' => StatutVersionModeleIa::ACTIF, 'mlflow_run_id' => $run, 'cree_le' => now(),
        ]);

        MetriqueModeleIa::create([
            'version_id' => $version->id, 'cle' => 'rappel_sous_triage', 'valeur' => 0.8,
            'mesure_le' => now(),
        ]);

        return $version;
    }

    private function triage(int $age = 34): Triage
    {
        return Triage::create([
            'symptomes_json' => [['id' => 1, 'nom' => 'x', 'poids' => 2]], 'reponses_json' => [],
            'score_severite' => 40, 'niveau' => NiveauTriage::RECOMMANDEE,
            'recommandation_texte' => 'Test.', 'patient_age' => $age, 'patient_sexe' => 'F',
            'score_antecedents' => 3,
        ]);
    }

    private function prediction(Triage $triage, string $classe, string $run = 'run_a'): PredictionIa
    {
        return app(JournalPredictionIa::class)->inscrire([
            'triage_id' => $triage->id, 'mode' => 'observation', 'modele_version' => $run,
            'latence_ms' => 20, 'probabilite' => 0.7,
            'facteurs_json' => [['feature' => 'pouls', 'poids' => 0.2]],
            'explication_json' => ['classe_predite' => $classe],
            'confiance' => 'moderee', 'limites' => 'Avis sur l\'orientation.',
        ]);
    }

    private function ligneApprentissage(Triage $triage, string $label): JeuDonneesEntrainement
    {
        return JeuDonneesEntrainement::create([
            'triage_id' => $triage->id, 'age' => $triage->patient_age, 'sexe' => 'F',
            'symptomes_json' => [], 'label' => $label, 'niveau_protocole' => $triage->niveau,
            'cree_le' => now(),
        ]);
    }

    /** @return array<int, array<string, mixed>> */
    private function lignesExport(int $nombre, int $age): array
    {
        $bande = app(ServiceExportJeuEntrainement::class)->bandePour($age);

        return array_fill(0, $nombre, [
            'bande_age' => $bande, 'sexe' => 'F', 'symptomes' => [1],
            'constantes' => ['temperature' => 38.0, 'pouls' => 80],
            'duree_jours' => 2, 'intensite' => 5, 'grossesse' => false, 'score_antecedents' => 3,
            'label' => 'adaptee', 'annee_mois' => '2026-08',
        ]);
    }

    /**
     * Des triages réels PORTANT LES MÊMES DONNÉES que les lignes d'export de référence.
     *
     * Ce détail a fait échouer le vecteur « population identique » : mes triages n'avaient ni pouls
     * ni réponses, donc quatre features basculaient en catégorie `absent` et la dérive était réelle
     * — mais elle venait de la fixture, pas du code. C'est la démonstration en creux de ce que
     * `TraitsDepuisTriage` existe pour éviter : **si les deux côtés ne décrivent pas la même chose,
     * une part de l'écart mesuré est la nôtre.**
     */
    private function triagesReels(int $nombre, int $age): void
    {
        for ($i = 0; $i < $nombre; $i++) {
            $t = $this->triage($age);

            foreach ([['temperature', 38.0, '°C'], ['pouls', 80.0, 'bpm']] as [$type, $valeur, $unite]) {
                TriageConstante::create([
                    'triage_id' => $t->id, 'type_mesure' => $type, 'valeur' => $valeur,
                    'unite' => $unite, 'origine' => 'saisie', 'referentiel_version' => 1,
                ]);
            }

            foreach ([['duree_jours', '2'], ['intensite', '5'], ['grossesse', 'non']] as [$cle, $valeur]) {
                TriageReponse::create([
                    'triage_id' => $t->id, 'question_cle' => $cle, 'question_libelle' => $cle,
                    'valeur' => $valeur, 'protocole_code' => 'TRIAGE-QUESTIONNAIRE',
                    'protocole_version' => 1,
                ]);
            }
        }
    }
}
