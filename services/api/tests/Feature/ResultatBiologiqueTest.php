<?php

namespace Tests\Feature;

use App\Models\Analyse;
use App\Models\Automate;
use App\Models\ClientApi;
use App\Models\DemandeAnalyse;
use App\Models\JournalIngestion;
use App\Models\JournalLaboratoire;
use App\Models\MembreFamille;
use App\Models\Prelevement;
use App\Models\ResultatAnalyse;
use App\Models\StructureSanitaire;
use App\Models\User;
use App\Models\ValidationBiologique;
use App\Notifications\NotificationMasante;
use App\Services\Analyse\ServiceCircuitPrelevement;
use App\Services\Analyse\ServiceValidationBiologique;
use App\Services\ServiceFicheParcours;
use App\Support\StatutDemandeAnalyse;
use App\Support\StatutPrelevement;
use App\Support\VerdictValidationBiologique;
use Database\Seeders\PortailRolesSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;

/**
 * B5-c — résultats (saisie/import), automates, validation biologique et son verrou, publication
 * au carnet, notification (plan G1 PLAN 4 §10, compléments M1→M12).
 *
 * CE QUE CETTE SUITE PROTÈGE :
 *
 *  1. Le brouillon ne vit jamais dans le carnet AVANT publication (M1) — vérifié en base directe.
 *  2. Saisie et import écrivent le MÊME couple de colonnes (L15/M2), avec une garde applicative
 *     honnête (`en_analyse` seulement) et une origine décidée par le SERVEUR.
 *  3. La validation est le VERROU (L7) : aucun résultat non validé ne se publie, aucun automate
 *     ne valide jamais.
 *  4. La publication referme K5/K11 POUR DE BON : `source='structure'` n'est posée qu'à cet
 *     endroit, jamais déclarée par un client, et le résultat publié réapparaît de lui-même dans
 *     la fiche de parcours du patient sans code neuf côté `ServiceFicheParcours`.
 *  5. `resultats_json` publié est VERBATIM depuis le brouillon figé — un changement du catalogue
 *     APRÈS la saisie ne doit RIEN changer au résultat déjà publié.
 *  6. `DemandeAnalyse::estOuverte()` ferme le cycle (M6) : une demande servie ne reprend plus de
 *     prélèvement.
 */
class ResultatBiologiqueTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PortailRolesSeeder::class);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Aides
    // ─────────────────────────────────────────────────────────────────────────

    private function laboratoire(string $nom = 'Laboratoire de test'): StructureSanitaire
    {
        return StructureSanitaire::create([
            'nom' => $nom, 'type' => 'laboratoire', 'adresse' => 'Abidjan', 'commune' => 'Cocody',
            'latitude' => 5.35, 'longitude' => -3.98, 'actif' => true,
        ]);
    }

    private function laborantin(StructureSanitaire $labo): User
    {
        $user = User::factory()->create(['structure_id' => $labo->id]);
        $user->givePermissionTo('analyse.executer');

        return $user->fresh();
    }

    /** Un biologiste qui NE PORTE QUE `analyse.valider` — jamais `admin_ivoirsante`, qui porte tout (leçon P6.6a). */
    private function biologiste(StructureSanitaire $labo): User
    {
        $user = User::factory()->create(['structure_id' => $labo->id]);
        $user->givePermissionTo('analyse.valider');

        return $user->fresh();
    }

    private function patient(): MembreFamille
    {
        return MembreFamille::factory()->for(User::factory()->create())->create();
    }

    private function analyseDuCatalogue(string $libelle = 'Glycémie à jeun', string $unite = 'g/L'): Analyse
    {
        $analyse = Analyse::create(['libelle' => $libelle, 'unite' => $unite, 'categorie' => 'biochimie']);
        $analyse->forceFill(['code' => 'ANA9000'.random_int(10, 99), 'pays_code' => 'CI'])->save();

        return $analyse;
    }

    private function demande(MembreFamille $membre, ?array $lignes = null): DemandeAnalyse
    {
        return $membre->demandesAnalyses()->create([
            'medecin_nom' => 'Dr Test', 'structure_sanitaire' => 'Structure Test',
            'date_demande' => '2026-09-05',
            'analyses_json' => $lignes ?? [['libelle' => 'Numération formule sanguine']],
        ]);
    }

    private function circuit(): ServiceCircuitPrelevement
    {
        return app(ServiceCircuitPrelevement::class);
    }

    private function validation(): ServiceValidationBiologique
    {
        return app(ServiceValidationBiologique::class);
    }

    /** Un prélèvement mené jusqu'à `en_analyse`, prêt pour une saisie. */
    private function prelevementEnAnalyse(StructureSanitaire $labo, User $laborantin, DemandeAnalyse $demande): Prelevement
    {
        $p = $this->circuit()->enregistrer($laborantin, $demande);

        return $this->circuit()->mettreEnAnalyse($laborantin, $this->circuit()->recevoir($laborantin, $p));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Schéma
    // ─────────────────────────────────────────────────────────────────────────

    public function test_les_tables_et_colonnes_de_b5c_existent(): void
    {
        $this->assertTrue(Schema::hasTable('validations_biologiques'));
        $this->assertTrue(Schema::hasTable('automates'));
        $this->assertTrue(Schema::hasColumn('prelevements', 'resultats_bruts_json'));
        $this->assertTrue(Schema::hasColumn('prelevements', 'resultats_bruts_origine'));
        $this->assertTrue(Schema::hasColumn('resultats_analyses', 'origine'));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // M1/M2 — Saisie : garde applicative, brouillon HORS du carnet, lien catalogue figé
    // ─────────────────────────────────────────────────────────────────────────

    public function test_saisir_ecrit_le_brouillon_hors_du_carnet(): void
    {
        $labo = $this->laboratoire();
        $laborantin = $this->laborantin($labo);
        $prelevement = $this->prelevementEnAnalyse($labo, $laborantin, $this->demande($this->patient()));

        $this->validation()->saisir($laborantin, $prelevement, [
            ['parametre' => 'Hémoglobine', 'valeur' => '12.5', 'unite' => 'g/dL'],
        ]);

        $this->assertSame(0, ResultatAnalyse::count(), 'Aucun résultat ne doit exister dans le carnet avant publication.');
        $this->assertTrue($prelevement->fresh()->aUnBrouillon());
        $this->assertSame('saisie', $prelevement->fresh()->resultats_bruts_origine);
    }

    public function test_saisir_avant_la_mise_en_analyse_est_refuse(): void
    {
        $labo = $this->laboratoire();
        $laborantin = $this->laborantin($labo);
        $prelevement = $this->circuit()->enregistrer($laborantin, $this->demande($this->patient()));

        $this->expectException(ValidationException::class);
        $this->validation()->saisir($laborantin, $prelevement, [['parametre' => 'X', 'valeur' => '1']]);
    }

    public function test_saisir_apres_validation_est_refuse(): void
    {
        $labo = $this->laboratoire();
        $laborantin = $this->laborantin($labo);
        $biologiste = $this->biologiste($labo);
        $prelevement = $this->prelevementEnAnalyse($labo, $laborantin, $this->demande($this->patient()));

        $this->validation()->saisir($laborantin, $prelevement, [['parametre' => 'X', 'valeur' => '1']]);
        $prelevement = $this->validation()->valider($biologiste, $prelevement->fresh());

        $this->expectException(ValidationException::class);
        $this->validation()->saisir($laborantin, $prelevement, [['parametre' => 'X', 'valeur' => '2']]);
    }

    public function test_resaisir_avant_validation_remplace_le_brouillon(): void
    {
        $labo = $this->laboratoire();
        $laborantin = $this->laborantin($labo);
        $prelevement = $this->prelevementEnAnalyse($labo, $laborantin, $this->demande($this->patient()));

        $this->validation()->saisir($laborantin, $prelevement, [['parametre' => 'X', 'valeur' => '1']]);
        $this->validation()->saisir($laborantin->fresh(), $prelevement->fresh(), [['parametre' => 'X', 'valeur' => '2']]);

        $brouillon = $prelevement->fresh()->resultats_bruts_json;
        $this->assertCount(1, $brouillon);
        $this->assertSame('2', $brouillon[0]['valeur']);
    }

    public function test_le_lien_au_catalogue_est_relu_et_fige_a_la_saisie(): void
    {
        $labo = $this->laboratoire();
        $laborantin = $this->laborantin($labo);
        $analyse = $this->analyseDuCatalogue();
        $prelevement = $this->prelevementEnAnalyse($labo, $laborantin, $this->demande($this->patient()));

        $this->validation()->saisir($laborantin, $prelevement, [
            ['parametre' => $analyse->libelle, 'valeur' => '0.95', 'analyse_id' => $analyse->id],
        ]);

        $ligne = $prelevement->fresh()->resultats_bruts_json[0];
        $this->assertSame($analyse->code, $ligne['code_national']);
        $this->assertSame($analyse->unite, $ligne['unite_catalogue']);
    }

    public function test_un_laborantin_sans_habilitation_ne_peut_pas_saisir(): void
    {
        $labo = $this->laboratoire();
        $sansPermission = User::factory()->create(['structure_id' => $labo->id]);
        $laborantin = $this->laborantin($labo);
        $prelevement = $this->prelevementEnAnalyse($labo, $laborantin, $this->demande($this->patient()));

        $this->expectException(ValidationException::class);
        $this->validation()->saisir($sansPermission, $prelevement, [['parametre' => 'X', 'valeur' => '1']]);
    }

    public function test_saisir_journalise(): void
    {
        $labo = $this->laboratoire();
        $laborantin = $this->laborantin($labo);
        $prelevement = $this->prelevementEnAnalyse($labo, $laborantin, $this->demande($this->patient()));

        $this->validation()->saisir($laborantin, $prelevement, [['parametre' => 'X', 'valeur' => '1']]);

        $this->assertSame(1, JournalLaboratoire::where('action', 'resultat_saisi')->count());
    }

    // ─────────────────────────────────────────────────────────────────────────
    // M9/L10 — Import automate : anti-usurpation, garde applicative partagée
    // ─────────────────────────────────────────────────────────────────────────

    public function test_importer_ecrit_le_brouillon_avec_origine_automate(): void
    {
        $labo = $this->laboratoire();
        $laborantin = $this->laborantin($labo);
        $automate = Automate::create(['structure_id' => $labo->id, 'libelle' => 'Sysmex XN-550']);
        $prelevement = $this->prelevementEnAnalyse($labo, $laborantin, $this->demande($this->patient()));

        $this->validation()->importer($automate, $prelevement, [['parametre' => 'X', 'valeur' => '1']]);

        $this->assertSame('automate', $prelevement->fresh()->resultats_bruts_origine);
        $this->assertNotNull($automate->fresh()->dernier_message_le);
        $this->assertSame(1, JournalLaboratoire::where('action', 'resultat_importe')->count());
        $entree = JournalLaboratoire::where('action', 'resultat_importe')->first();
        $this->assertNull($entree->acteur_user_id);
        $this->assertStringContainsString('Sysmex', $entree->acteur_nom);
    }

    /** ANTI-USURPATION (M9) : un automate déclaré ailleurs ne peut pas écrire pour ce laboratoire. */
    public function test_un_automate_d_un_autre_laboratoire_est_refuse(): void
    {
        $labo1 = $this->laboratoire('Laboratoire A');
        $labo2 = $this->laboratoire('Laboratoire B');
        $laborantin1 = $this->laborantin($labo1);
        $automateDeLaboB = Automate::create(['structure_id' => $labo2->id, 'libelle' => 'Autre appareil']);
        $prelevement = $this->prelevementEnAnalyse($labo1, $laborantin1, $this->demande($this->patient()));

        $this->expectException(ValidationException::class);
        $this->validation()->importer($automateDeLaboB, $prelevement, [['parametre' => 'X', 'valeur' => '1']]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Ingestion HTTP (clé + HMAC) — patron `ApiIngestionPartenaireTest`
    // ─────────────────────────────────────────────────────────────────────────

    private const URL_INGESTION = '/api/v1/integration/resultats-laboratoire';

    /** @return array{0: StructureSanitaire, 1: ClientApi, 2: string, 3: Automate} */
    private function contexteIngestion(): array
    {
        $labo = $this->laboratoire();
        $secret = ClientApi::genererSecret();
        $client = new ClientApi([
            'structure_id' => $labo->id, 'libelle' => 'Middleware du laboratoire',
            'domaines_json' => ['resultats_laboratoire'],
        ]);
        $client->identifiant = ClientApi::genererIdentifiant();
        $client->secret_chiffre = $secret;
        $client->save();

        $automate = Automate::create(['structure_id' => $labo->id, 'client_api_id' => $client->id, 'libelle' => 'Auto']);

        return [$labo, $client, $secret, $automate];
    }

    private function envoyer(ClientApi $client, string $secret, array $corpsArray, ?int $horodatage = null): TestResponse
    {
        $corps = json_encode($corpsArray, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $ts = $horodatage ?? now()->timestamp;
        $signature = base64_encode(hash_hmac('sha256', $ts.'.'.$corps, $secret, true));

        $serveur = [
            'HTTP_X_MASANTE_CLIENT' => $client->identifiant,
            'HTTP_X_MASANTE_TIMESTAMP' => (string) $ts,
            'HTTP_X_MASANTE_SIGNATURE' => $signature,
            'CONTENT_TYPE' => 'application/json',
            'ACCEPT' => 'application/json',
        ];

        return $this->call('POST', self::URL_INGESTION, [], [], [], $serveur, $corps);
    }

    public function test_un_automate_pousse_un_resultat_signe(): void
    {
        [$labo, $client, $secret, $automate] = $this->contexteIngestion();
        $laborantin = $this->laborantin($labo);
        $prelevement = $this->prelevementEnAnalyse($labo, $laborantin, $this->demande($this->patient()));

        $this->envoyer($client, $secret, [
            'automate_id' => $automate->id,
            'resultats' => [
                ['identifiant_prelevement' => $prelevement->identifiant, 'valeurs' => [
                    ['parametre' => 'Hémoglobine', 'valeur' => '13.2', 'unite' => 'g/dL'],
                ]],
            ],
        ])->assertOk()->assertJsonPath('acceptes', 1)->assertJsonPath('refuses', 0);

        $this->assertSame('automate', $prelevement->fresh()->resultats_bruts_origine);
    }

    /** LE SERVEUR NE DEVINE JAMAIS (L10) : un identifiant inconnu est refusé et NOMMÉ. */
    public function test_un_identifiant_de_prelevement_inconnu_est_refuse_et_nomme(): void
    {
        [, $client, $secret, $automate] = $this->contexteIngestion();

        $reponse = $this->envoyer($client, $secret, [
            'automate_id' => $automate->id,
            'resultats' => [
                ['identifiant_prelevement' => 'PRE-INVENTE0', 'valeurs' => [['parametre' => 'X', 'valeur' => '1']]],
            ],
        ]);

        $reponse->assertOk()->assertJsonPath('acceptes', 0)->assertJsonPath('refuses', 1);
        $this->assertStringContainsString('PRE-INVENTE0', $reponse->json('refus.0.motif'));
        $this->assertStringContainsString('aucun rapprochement', $reponse->json('refus.0.motif'));
    }

    /** Un automate d'un AUTRE laboratoire que le client authentifié fait échouer le LOT ENTIER. */
    public function test_un_automate_id_hors_de_la_structure_du_client_fait_echouer_le_lot(): void
    {
        [$labo1, $client, $secret] = $this->contexteIngestion();
        $labo2 = $this->laboratoire('Laboratoire B');
        $automateEtranger = Automate::create(['structure_id' => $labo2->id, 'libelle' => 'Étranger']);

        $this->envoyer($client, $secret, [
            'automate_id' => $automateEtranger->id,
            'resultats' => [['identifiant_prelevement' => 'PRE-X', 'valeurs' => [['parametre' => 'X', 'valeur' => '1']]]],
        ])->assertStatus(422);
    }

    public function test_un_automate_desactive_ne_peut_plus_pousser(): void
    {
        [$labo, $client, $secret, $automate] = $this->contexteIngestion();
        $automate->forceFill(['actif' => false])->save();

        $this->envoyer($client, $secret, [
            'automate_id' => $automate->id,
            'resultats' => [['identifiant_prelevement' => 'PRE-X', 'valeurs' => [['parametre' => 'X', 'valeur' => '1']]]],
        ])->assertStatus(422);
    }

    /** UN AUTOMATE NE VALIDE JAMAIS (garde centrale de L10) : l'import laisse le prélèvement `en_analyse`. */
    public function test_un_resultat_importe_n_est_jamais_auto_valide(): void
    {
        [$labo, $client, $secret, $automate] = $this->contexteIngestion();
        $laborantin = $this->laborantin($labo);
        $prelevement = $this->prelevementEnAnalyse($labo, $laborantin, $this->demande($this->patient()));

        $this->envoyer($client, $secret, [
            'automate_id' => $automate->id,
            'resultats' => [['identifiant_prelevement' => $prelevement->identifiant, 'valeurs' => [
                ['parametre' => 'X', 'valeur' => '1'],
            ]]],
        ])->assertOk();

        $this->assertSame(StatutPrelevement::EN_ANALYSE, $prelevement->fresh()->statut);
        $this->assertSame(0, ResultatAnalyse::count());
    }

    public function test_un_lot_avec_une_ligne_fautive_ecrit_le_reste_et_nomme_le_refus(): void
    {
        [$labo, $client, $secret, $automate] = $this->contexteIngestion();
        $laborantin = $this->laborantin($labo);
        $bon = $this->prelevementEnAnalyse($labo, $laborantin, $this->demande($this->patient()));

        $reponse = $this->envoyer($client, $secret, [
            'automate_id' => $automate->id,
            'resultats' => [
                ['identifiant_prelevement' => $bon->identifiant, 'valeurs' => [['parametre' => 'X', 'valeur' => '1']]],
                ['identifiant_prelevement' => 'PRE-INEXISTANT', 'valeurs' => [['parametre' => 'Y', 'valeur' => '2']]],
            ],
        ]);

        $reponse->assertOk()->assertJsonPath('acceptes', 1)->assertJsonPath('refuses', 1);
        $this->assertSame(1, JournalIngestion::count());
    }

    // ─────────────────────────────────────────────────────────────────────────
    // L7 — La validation est le VERROU
    // ─────────────────────────────────────────────────────────────────────────

    public function test_valider_exige_un_brouillon(): void
    {
        $labo = $this->laboratoire();
        $laborantin = $this->laborantin($labo);
        $biologiste = $this->biologiste($labo);
        $prelevement = $this->prelevementEnAnalyse($labo, $laborantin, $this->demande($this->patient()));

        $this->expectException(ValidationException::class);
        $this->validation()->valider($biologiste, $prelevement);
    }

    public function test_un_laborantin_qui_n_a_pas_analyse_valider_ne_peut_pas_valider(): void
    {
        // `analyse.executer` SEULE ne suffit pas : exécuter et valider sont deux permissions
        // distinctes (L7), et un cumul n'est jamais supposé.
        $labo = $this->laboratoire();
        $laborantin = $this->laborantin($labo);
        $prelevement = $this->prelevementEnAnalyse($labo, $laborantin, $this->demande($this->patient()));
        $this->validation()->saisir($laborantin, $prelevement, [['parametre' => 'X', 'valeur' => '1']]);

        $this->expectException(ValidationException::class);
        $this->validation()->valider($laborantin->fresh(), $prelevement->fresh());
    }

    public function test_valider_pose_le_statut_le_valideur_et_journalise(): void
    {
        $labo = $this->laboratoire();
        $laborantin = $this->laborantin($labo);
        $biologiste = $this->biologiste($labo);
        $prelevement = $this->prelevementEnAnalyse($labo, $laborantin, $this->demande($this->patient()));
        $this->validation()->saisir($laborantin, $prelevement, [['parametre' => 'X', 'valeur' => '1']]);

        $valide = $this->validation()->valider($biologiste, $prelevement->fresh());

        $this->assertSame(StatutPrelevement::VALIDE, $valide->statut);
        $this->assertSame($biologiste->id, $valide->valide_par_user_id);
        $this->assertNotNull($valide->valide_le);
        $this->assertSame(1, ValidationBiologique::where('verdict', VerdictValidationBiologique::VALIDE)->count());
        $this->assertSame(1, JournalLaboratoire::where('action', 'validation')->count());
    }

    public function test_un_biologiste_d_une_autre_structure_recoit_404(): void
    {
        $labo1 = $this->laboratoire('Laboratoire A');
        $labo2 = $this->laboratoire('Laboratoire B');
        $laborantin1 = $this->laborantin($labo1);
        $biologiste2 = $this->biologiste($labo2);
        $prelevement = $this->prelevementEnAnalyse($labo1, $laborantin1, $this->demande($this->patient()));
        $this->validation()->saisir($laborantin1, $prelevement, [['parametre' => 'X', 'valeur' => '1']]);

        $this->expectException(NotFoundHttpException::class);
        $this->validation()->valider($biologiste2, $prelevement->fresh());
    }

    // ─────────────────────────────────────────────────────────────────────────
    // M4 — Le rejet efface, journalise, exige son motif
    // ─────────────────────────────────────────────────────────────────────────

    public function test_rejeter_efface_le_brouillon_sans_motif_est_refuse(): void
    {
        $labo = $this->laboratoire();
        $laborantin = $this->laborantin($labo);
        $biologiste = $this->biologiste($labo);
        $prelevement = $this->prelevementEnAnalyse($labo, $laborantin, $this->demande($this->patient()));
        $this->validation()->saisir($laborantin, $prelevement, [['parametre' => 'X', 'valeur' => '1']]);

        $this->expectException(ValidationException::class);
        $this->validation()->rejeter($biologiste, $prelevement->fresh(), '   ');
    }

    public function test_rejeter_efface_le_brouillon_et_garde_le_statut_en_analyse(): void
    {
        $labo = $this->laboratoire();
        $laborantin = $this->laborantin($labo);
        $biologiste = $this->biologiste($labo);
        $prelevement = $this->prelevementEnAnalyse($labo, $laborantin, $this->demande($this->patient()));
        $this->validation()->saisir($laborantin, $prelevement, [['parametre' => 'X', 'valeur' => '1']]);

        $rejete = $this->validation()->rejeter($biologiste, $prelevement->fresh(), 'Valeur incohérente, à refaire.');

        $this->assertSame(StatutPrelevement::EN_ANALYSE, $rejete->statut);
        $this->assertFalse($rejete->aUnBrouillon());
        $this->assertSame(1, ValidationBiologique::where('verdict', VerdictValidationBiologique::REJETE)->count());
        $this->assertSame('Valeur incohérente, à refaire.', ValidationBiologique::first()->motif);
        $this->assertSame(1, JournalLaboratoire::where('action', 'rejet')->count());
    }

    public function test_une_nouvelle_saisie_est_possible_apres_un_rejet(): void
    {
        $labo = $this->laboratoire();
        $laborantin = $this->laborantin($labo);
        $biologiste = $this->biologiste($labo);
        $prelevement = $this->prelevementEnAnalyse($labo, $laborantin, $this->demande($this->patient()));
        $this->validation()->saisir($laborantin, $prelevement, [['parametre' => 'X', 'valeur' => '1']]);
        $this->validation()->rejeter($biologiste, $prelevement->fresh(), 'À refaire.');

        $this->validation()->saisir($laborantin->fresh(), $prelevement->fresh(), [['parametre' => 'X', 'valeur' => '2']]);

        $this->assertTrue($prelevement->fresh()->aUnBrouillon());
    }

    /** Garde du MOTEUR, pas seulement du service : un rejet sans motif inséré en direct échoue aussi. */
    public function test_le_moteur_refuse_un_rejet_sans_motif_en_sql_direct(): void
    {
        $labo = $this->laboratoire();
        $laborantin = $this->laborantin($labo);
        $prelevement = $this->prelevementEnAnalyse($labo, $laborantin, $this->demande($this->patient()));

        $this->expectException(QueryException::class);
        DB::table('validations_biologiques')->insert([
            'prelevement_id' => $prelevement->id, 'nom' => 'Test', 'verdict' => 'rejete',
            'motif' => null, 'cree_le' => now(),
        ]);
    }

    public function test_le_journal_des_validations_refuse_modification_et_suppression(): void
    {
        $labo = $this->laboratoire();
        $laborantin = $this->laborantin($labo);
        $biologiste = $this->biologiste($labo);
        $prelevement = $this->prelevementEnAnalyse($labo, $laborantin, $this->demande($this->patient()));
        $this->validation()->saisir($laborantin, $prelevement, [['parametre' => 'X', 'valeur' => '1']]);
        $this->validation()->valider($biologiste, $prelevement->fresh());

        $entree = ValidationBiologique::firstOrFail();

        $this->expectException(\RuntimeException::class);
        $entree->update(['nom' => 'Autre nom']);
    }

    public function test_le_journal_des_validations_refuse_au_niveau_du_moteur(): void
    {
        $labo = $this->laboratoire();
        $laborantin = $this->laborantin($labo);
        $biologiste = $this->biologiste($labo);
        $prelevement = $this->prelevementEnAnalyse($labo, $laborantin, $this->demande($this->patient()));
        $this->validation()->saisir($laborantin, $prelevement, [['parametre' => 'X', 'valeur' => '1']]);
        $this->validation()->valider($biologiste, $prelevement->fresh());

        $this->expectException(QueryException::class);
        DB::table('validations_biologiques')->where('id', 1)->delete();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // M7 — Publication : LE point d'accroche qui referme K5/K11
    // ─────────────────────────────────────────────────────────────────────────

    public function test_publier_exige_un_prelevement_valide(): void
    {
        $labo = $this->laboratoire();
        $laborantin = $this->laborantin($labo);
        $biologiste = $this->biologiste($labo);
        $prelevement = $this->prelevementEnAnalyse($labo, $laborantin, $this->demande($this->patient()));
        $this->validation()->saisir($laborantin, $prelevement, [['parametre' => 'X', 'valeur' => '1']]);
        // pas encore validé

        $this->expectException(ValidationException::class);
        $this->validation()->publier($biologiste, $prelevement->fresh());
    }

    public function test_publier_cree_le_resultat_avec_source_structure_jamais_du_client(): void
    {
        Notification::fake();

        $labo = $this->laboratoire('Laboratoire BIOSMOSE');
        $laborantin = $this->laborantin($labo);
        $biologiste = $this->biologiste($labo);
        $patient = $this->patient();
        $demande = $this->demande($patient, [['libelle' => 'Numération formule sanguine'], ['libelle' => 'Glycémie']]);
        $prelevement = $this->prelevementEnAnalyse($labo, $laborantin, $demande);

        $this->validation()->saisir($laborantin, $prelevement, [
            ['parametre' => 'Hémoglobine', 'valeur' => '13.0', 'unite' => 'g/dL'],
        ]);
        $prelevement = $this->validation()->valider($biologiste, $prelevement->fresh());

        $resultat = $this->validation()->publier($biologiste, $prelevement->fresh());

        $this->assertSame('structure', $resultat->source);
        $this->assertSame('saisie', $resultat->origine);
        $this->assertSame('biologique', $resultat->type_analyse);
        $this->assertSame($labo->id, $resultat->laboratoire_id);
        $this->assertSame($labo->nom, $resultat->laboratoire_nom);
        $this->assertStringContainsString('Numération formule sanguine', $resultat->intitule);

        $frais = $prelevement->fresh();
        $this->assertSame(StatutPrelevement::PUBLIE, $frais->statut);
        $this->assertSame($resultat->id, $frais->resultat_analyse_id);
        $this->assertNotNull($frais->publie_le);
        $this->assertSame(StatutDemandeAnalyse::SERVIE, $demande->fresh()->statut);
        $this->assertSame(1, JournalLaboratoire::where('action', 'publication')->count());
    }

    /** LE POINT SENSIBLE (M7) : le catalogue peut changer après la saisie sans rien changer au résultat déjà publié. */
    public function test_un_changement_du_catalogue_apres_saisie_ne_change_pas_le_resultat_publie(): void
    {
        $labo = $this->laboratoire();
        $laborantin = $this->laborantin($labo);
        $biologiste = $this->biologiste($labo);
        $analyse = $this->analyseDuCatalogue('Glycémie à jeun', 'g/L');
        $prelevement = $this->prelevementEnAnalyse($labo, $laborantin, $this->demande($this->patient()));

        $this->validation()->saisir($laborantin, $prelevement, [
            ['parametre' => $analyse->libelle, 'valeur' => '0.95', 'analyse_id' => $analyse->id],
        ]);
        $prelevement = $this->validation()->valider($biologiste, $prelevement->fresh());

        // Le catalogue change APRÈS la saisie, AVANT la publication.
        $analyse->forceFill(['unite' => 'mmol/L'])->save();

        $resultat = $this->validation()->publier($biologiste, $prelevement->fresh());

        $this->assertSame('g/L', $resultat->resultats_json[0]['unite_catalogue'] ?? $resultat->resultats_json[0]['unite']);
    }

    public function test_le_client_ne_peut_pas_declarer_source_structure_sur_son_propre_resultat(): void
    {
        // K5/K11 — deuxième couche, celle qui compte réellement : le CONTRÔLEUR (HTTP) n'accepte
        // toujours pas `source` dans ses règles de validation. Un test qui écrirait directement
        // sur le modèle (`create()`) prouverait `$fillable`, pas la garde — `$fillable` doit
        // rester ouvert pour que `ServiceValidationBiologique::publier()` puisse poser la valeur
        // lui-même ; c'est `validate()` qui protège le chemin du client.
        $patient = $this->patient();

        $this->actingAs($patient->user, 'sanctum')->postJson(
            "/api/v1/membres/{$patient->id}/resultats-analyses",
            [
                'type_analyse' => 'biologique', 'intitule' => 'Auto-déclaré', 'date_analyse' => '2026-09-05',
                'source' => 'structure',
            ],
        )->assertCreated();

        $this->assertSame('patient', ResultatAnalyse::firstOrFail()->source);
    }

    public function test_le_resultat_publie_apparait_dans_la_fiche_de_parcours_sans_code_neuf(): void
    {
        $labo = $this->laboratoire();
        $laborantin = $this->laborantin($labo);
        $biologiste = $this->biologiste($labo);
        $membre = $this->patient();
        $prelevement = $this->prelevementEnAnalyse($labo, $laborantin, $this->demande($membre));
        $this->validation()->saisir($laborantin, $prelevement, [['parametre' => 'X', 'valeur' => '1']]);
        $prelevement = $this->validation()->valider($biologiste, $prelevement->fresh());
        $resultat = $this->validation()->publier($biologiste, $prelevement->fresh());

        $fiche = app(ServiceFicheParcours::class)->pour($membre->fresh());

        $trouve = collect($fiche['autres_entrees'])
            ->first(fn ($e) => $e['section'] === 'resultats-analyses' && $e['id'] === $resultat->id);

        $this->assertNotNull($trouve, 'Le résultat publié doit apparaître dans les autres entrées de la fiche.');
    }

    public function test_publier_notifie_sans_fuite_de_contenu(): void
    {
        Notification::fake();

        $labo = $this->laboratoire();
        $laborantin = $this->laborantin($labo);
        $biologiste = $this->biologiste($labo);
        $membre = $this->patient();
        $prelevement = $this->prelevementEnAnalyse($labo, $laborantin, $this->demande($membre, [
            ['libelle' => 'Sérologie confidentielle secrete-XYZ'],
        ]));
        $this->validation()->saisir($laborantin, $prelevement, [
            ['parametre' => 'Sérologie confidentielle secrete-XYZ', 'valeur' => 'positif-secret-42'],
        ]);
        $prelevement = $this->validation()->valider($biologiste, $prelevement->fresh());
        $this->validation()->publier($biologiste, $prelevement->fresh());

        Notification::assertSentTo(
            $membre->user,
            NotificationMasante::class,
            function ($notification) {
                $charge = json_encode($notification->toArray($this->createMock(User::class)));

                return ! str_contains($charge, 'secrete-XYZ') && ! str_contains($charge, 'positif-secret-42');
            },
        );
    }

    public function test_un_biologiste_d_une_autre_structure_ne_peut_pas_publier(): void
    {
        $labo1 = $this->laboratoire('Laboratoire A');
        $labo2 = $this->laboratoire('Laboratoire B');
        $laborantin1 = $this->laborantin($labo1);
        $biologiste1 = $this->biologiste($labo1);
        $biologiste2 = $this->biologiste($labo2);
        $prelevement = $this->prelevementEnAnalyse($labo1, $laborantin1, $this->demande($this->patient()));
        $this->validation()->saisir($laborantin1, $prelevement, [['parametre' => 'X', 'valeur' => '1']]);
        $prelevement = $this->validation()->valider($biologiste1, $prelevement->fresh());

        $this->expectException(NotFoundHttpException::class);
        $this->validation()->publier($biologiste2, $prelevement->fresh());
    }

    // ─────────────────────────────────────────────────────────────────────────
    // M6 — Une demande = un cycle
    // ─────────────────────────────────────────────────────────────────────────

    public function test_une_demande_servie_ne_recoit_plus_de_prelevement(): void
    {
        $labo = $this->laboratoire();
        $laborantin = $this->laborantin($labo);
        $biologiste = $this->biologiste($labo);
        $demande = $this->demande($this->patient());
        $prelevement = $this->prelevementEnAnalyse($labo, $laborantin, $demande);
        $this->validation()->saisir($laborantin, $prelevement, [['parametre' => 'X', 'valeur' => '1']]);
        $prelevement = $this->validation()->valider($biologiste, $prelevement->fresh());
        $this->validation()->publier($biologiste, $prelevement->fresh());

        $this->expectException(ValidationException::class);
        $this->circuit()->enregistrer($laborantin->fresh(), $demande->fresh());
    }

    public function test_plusieurs_prelevements_avant_publication_restent_possibles(): void
    {
        $labo = $this->laboratoire();
        $laborantin = $this->laborantin($labo);
        $demande = $this->demande($this->patient());

        $premier = $this->circuit()->enregistrer($laborantin, $demande);
        $second = $this->circuit()->enregistrer($laborantin->fresh(), $demande->fresh());

        $this->assertNotSame($premier->id, $second->id);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Correction B5-c : `travailPour()` doit inclure `valide`
    // ─────────────────────────────────────────────────────────────────────────

    public function test_un_prelevement_valide_reste_dans_le_travail_en_cours(): void
    {
        $labo = $this->laboratoire();
        $laborantin = $this->laborantin($labo);
        $biologiste = $this->biologiste($labo);
        $prelevement = $this->prelevementEnAnalyse($labo, $laborantin, $this->demande($this->patient()));
        $this->validation()->saisir($laborantin, $prelevement, [['parametre' => 'X', 'valeur' => '1']]);
        $this->validation()->valider($biologiste, $prelevement->fresh());

        $travail = $this->circuit()->travailPour($laborantin->fresh());

        $this->assertTrue($travail->pluck('id')->contains($prelevement->id));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // La commande `masante:laboratoire:automate`
    // ─────────────────────────────────────────────────────────────────────────

    public function test_la_commande_declare_un_automate(): void
    {
        $labo = $this->laboratoire();

        Artisan::call('masante:laboratoire:automate', [
            'structure' => $labo->id, 'libelle' => 'Analyseur Sysmex XN-550', '--marque' => 'Sysmex',
        ]);

        $this->assertDatabaseHas('automates', ['structure_id' => $labo->id, 'libelle' => 'Analyseur Sysmex XN-550']);
    }

    public function test_la_commande_refuse_un_etablissement_qui_n_est_pas_un_laboratoire(): void
    {
        $pharmacie = StructureSanitaire::create([
            'nom' => 'Pharmacie', 'type' => 'pharmacie', 'adresse' => 'Abidjan', 'commune' => 'Cocody',
            'latitude' => 5.35, 'longitude' => -3.98, 'actif' => true,
        ]);

        $code = Artisan::call('masante:laboratoire:automate', [
            'structure' => $pharmacie->id, 'libelle' => 'X',
        ]);

        $this->assertSame(1, $code);
        $this->assertDatabaseCount('automates', 0);
    }

    public function test_la_commande_desactive_un_automate(): void
    {
        $labo = $this->laboratoire();
        $automate = Automate::create(['structure_id' => $labo->id, 'libelle' => 'X']);

        Artisan::call('masante:laboratoire:automate', [
            'structure' => $labo->id, 'libelle' => 'ignore', '--desactiver' => $automate->id,
        ]);

        $this->assertFalse($automate->fresh()->actif);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Écran portail — HTTP réel
    // ─────────────────────────────────────────────────────────────────────────

    public function test_le_cycle_complet_par_http_jusqu_a_la_publication(): void
    {
        Notification::fake();

        $labo = $this->laboratoire();
        $laborantin = $this->laborantin($labo);
        $laborantin->givePermissionTo('analyse.valider');
        $prelevement = $this->prelevementEnAnalyse($labo, $laborantin->fresh(), $this->demande($this->patient()));

        $this->actingAs($laborantin->fresh(), 'web')
            ->post(route('portail.laboratoire.resultat.saisir', $prelevement), [
                'valeurs' => [['parametre' => 'X', 'valeur' => '1', 'unite' => 'g/dL']],
            ])
            ->assertRedirect();

        $this->assertTrue($prelevement->fresh()->aUnBrouillon());

        $this->actingAs($laborantin->fresh(), 'web')
            ->post(route('portail.laboratoire.valider', $prelevement))
            ->assertRedirect();

        $this->assertSame(StatutPrelevement::VALIDE, $prelevement->fresh()->statut);

        $this->actingAs($laborantin->fresh(), 'web')
            ->post(route('portail.laboratoire.publier', $prelevement))
            ->assertRedirect();

        $this->assertSame(StatutPrelevement::PUBLIE, $prelevement->fresh()->statut);
        $this->assertSame(1, ResultatAnalyse::count());
    }

    public function test_un_laboratoire_d_une_autre_structure_recoit_404_sur_saisir_valider_publier(): void
    {
        $labo1 = $this->laboratoire('Laboratoire A');
        $labo2 = $this->laboratoire('Laboratoire B');
        $laborantin1 = $this->laborantin($labo1);
        $intrus = $this->laborantin($labo2);
        $intrus->givePermissionTo('analyse.valider');
        $prelevement = $this->prelevementEnAnalyse($labo1, $laborantin1, $this->demande($this->patient()));

        $this->actingAs($intrus->fresh(), 'web')
            ->post(route('portail.laboratoire.resultat.saisir', $prelevement), ['valeurs' => [['parametre' => 'X', 'valeur' => '1']]])
            ->assertNotFound();

        $this->actingAs($intrus->fresh(), 'web')
            ->post(route('portail.laboratoire.valider', $prelevement))
            ->assertNotFound();

        $this->actingAs($intrus->fresh(), 'web')
            ->post(route('portail.laboratoire.publier', $prelevement))
            ->assertNotFound();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Extension additive de `journal_laboratoire.action`
    // ─────────────────────────────────────────────────────────────────────────

    public function test_les_actes_anterieurs_de_b5b_restent_acceptes_par_le_moteur(): void
    {
        // L'extension de l'ENUM ne doit RIEN retirer : les cinq actes de B5-b restent valides.
        $labo = $this->laboratoire();
        $laborantin = $this->laborantin($labo);
        $this->circuit()->enregistrer($laborantin, $this->demande($this->patient()));

        $this->assertSame(1, JournalLaboratoire::where('action', 'prelevement_enregistre')->count());
    }
}
