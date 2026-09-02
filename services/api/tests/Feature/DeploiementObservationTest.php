<?php

namespace Tests\Feature;

use App\Models\ExportJeuEntrainement;
use App\Models\JeuDonneesEntrainement;
use App\Models\Maladie;
use App\Models\MembreFamille;
use App\Models\RetourCliniqueTriage;
use App\Models\SpecialiteMedicale;
use App\Models\StructureSanitaire;
use App\Models\Triage;
use App\Models\User;
use App\Models\VersionModeleIa;
use App\Services\Triage\ClientTriageIa;
use App\Services\Triage\DisjoncteurTriageIa;
use App\Services\Triage\JournalPredictionIa;
use App\Services\Triage\JournalRetourClinique;
use App\Services\Triage\ServiceExportJeuEntrainement;
use App\Services\Triage\ServiceGouvernanceModeleIa;
use App\Services\Triage\ServiceRetourTriage;
use App\Support\NiveauTriage;
use App\Support\RegistreRetourTriage;
use App\Support\StatutVersionModeleIa;
use Database\Seeders\PortailRolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * P10c-3-ii — Déploiement en observation et captation des trois faits manquants.
 *
 * ═══ CE QUE CETTE SUITE PROTÈGE ═══
 *
 * Trois propriétés qu'aucun commentaire ne peut garantir seul :
 *
 *   1. **le mot `observation`** — `hybride` reste inatteignable, parce qu'aucune IA ne participe
 *      à la décision (CDC_08 §3) et qu'un mot faux dans un journal médico-légal est un défaut ;
 *   2. **un seul modèle répond** — deux actifs rendraient « qui a produit cette prédiction ? »
 *      insoluble ;
 *   3. **on refuse au lieu d'arbitrer** — un verdict et un niveau qui se contredisent ne sont
 *      jamais départagés par le serveur.
 *
 * Écrite dans les deux sens : ce que le déploiement permet, et tout ce qu'il refuse.
 */
class DeploiementObservationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PortailRolesSeeder::class);
    }

    private function soignant(bool $habilite = true): User
    {
        $structure = StructureSanitaire::create([
            'nom' => 'CHU de Cocody', 'type' => 'chu', 'adresse' => 'Abidjan', 'commune' => 'Cocody',
            'latitude' => 5.35, 'longitude' => -3.98, 'actif' => true,
        ]);

        $user = User::factory()->create(['structure_id' => $structure->id]);

        if ($habilite) {
            $user->givePermissionTo('triage.retour');
        }

        return $user->fresh();
    }

    private function gouvernant(): User
    {
        $user = User::factory()->create();
        $user->givePermissionTo('ia_triage.valider');

        return $user->fresh();
    }

    /** @return array{0: User, 1: MembreFamille} */
    private function famille(): array
    {
        $parent = User::factory()->create();

        return [$parent, MembreFamille::factory()->for($parent)->create()];
    }

    private function triagePour(?MembreFamille $membre, string $niveau = NiveauTriage::RECOMMANDEE): Triage
    {
        return Triage::create([
            'membre_id' => $membre?->id,
            'symptomes_json' => [],
            'reponses_json' => [],
            'score_severite' => 42,
            'niveau' => $niveau,
            'recommandation_texte' => 'Consultez un médecin.',
        ]);
    }

    private function versionValidee(string $run = 'run_abc'): VersionModeleIa
    {
        $export = ExportJeuEntrainement::create([
            'pays_code' => 'CI', 'numero_export' => 1, 'instantane_json' => [],
            'nb_lignes' => 40, 'k_estime' => 4, 'cree_le' => now(),
        ]);

        return VersionModeleIa::create([
            'pays_code' => 'CI', 'numero_version' => 1, 'export_id' => $export->id,
            'statut' => StatutVersionModeleIa::VALIDE, 'mlflow_run_id' => $run, 'cree_le' => now(),
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // F22 — le mot juste

    public function test_une_prediction_reelle_est_enregistree_en_observation_jamais_en_hybride(): void
    {
        config(['masante.triage_ia.enabled' => true]);
        Http::fake([
            '*/api/v1/triage/score' => Http::response([
                'modele_version' => 'run_abc',
                'classe_predite' => 'sous_triage',
                'probabilites' => ['adaptee' => 0.1, 'sur_triage' => 0.2, 'sous_triage' => 0.7],
                'facteurs' => [['feature' => 'temperature', 'poids' => 0.42]],
                'confiance' => 'moderee',
                'limites' => 'Avis sur l\'orientation, jamais un diagnostic.',
            ], 200),
        ]);

        $resultat = app(ClientTriageIa::class)->scorer(['reference' => 'triage:1']);

        $this->assertSame('observation', $resultat->mode);
        $this->assertNotSame('hybride', $resultat->mode, 'hybride affirmerait une participation à la décision');
        $this->assertSame('run_abc', $resultat->modeleVersion);
        $this->assertEqualsWithDelta(0.7, $resultat->probabilite, 0.0001);
        $this->assertSame('moderee', $resultat->confiance);
        $this->assertNotEmpty($resultat->facteurs);
        $this->assertNotEmpty($resultat->limites);
    }

    public function test_une_reponse_sans_explication_est_degradee_jamais_recompletee(): void
    {
        // Rule-005 : une explication inventée côté Laravel serait pire que pas d'explication —
        // elle aurait l'air d'en être une.
        config(['masante.triage_ia.enabled' => true]);
        Http::fake([
            '*/api/v1/triage/score' => Http::response([
                'modele_version' => 'run_abc',
                'classe_predite' => 'adaptee',
                'probabilites' => ['adaptee' => 0.9],
                'facteurs' => [],
                'confiance' => 'elevee',
                'limites' => 'x',
            ], 200),
        ]);

        $resultat = app(ClientTriageIa::class)->scorer(['reference' => 'triage:1']);

        $this->assertSame('degrade', $resultat->mode);
        $this->assertSame('reponse_incomplete', $resultat->motifDegradation);
    }

    public function test_un_modele_absent_du_service_est_un_refus_honnete_pas_une_panne(): void
    {
        // F31 — le service a été atteint et a répondu : le disjoncteur ne doit pas s'ouvrir.
        config(['masante.triage_ia.enabled' => true]);
        Http::fake([
            '*/api/v1/triage/score' => Http::response(
                ['motif' => 'modele_absent_du_service', 'message' => 'run_x absent'], 503),
        ]);

        $resultat = app(ClientTriageIa::class)->scorer(['reference' => 'triage:1']);

        $this->assertSame('degrade', $resultat->mode);
        $this->assertSame('modele_absent_du_service', $resultat->motifDegradation);
        $this->assertFalse(app(DisjoncteurTriageIa::class)->estOuvert());
    }

    public function test_un_refus_de_contrat_garde_son_motif_et_n_ouvre_pas_le_circuit(): void
    {
        // ═══ TROUVÉ AU G2 LIVE ═══
        //
        // Le service NOMME la cause (`bande_age_inconnue` : les bornes ont divergé entre la config
        // Laravel et lui). Laravel l'écrasait en `reponse_inattendue_422` et comptait l'appel comme
        // une panne — une divergence de CONFIGURATION se serait déguisée en service en panne, puis
        // aurait ouvert le disjoncteur. Le service, lui, fonctionne parfaitement.
        config(['masante.triage_ia.enabled' => true]);
        Http::fake([
            '*/api/v1/triage/score' => Http::response(
                ['motif' => 'bande_age_inconnue', 'message' => 'Bande « 30-40 » inconnue.'], 422),
        ]);

        $resultat = app(ClientTriageIa::class)->scorer(['reference' => 'triage:1']);

        $this->assertSame('bande_age_inconnue', $resultat->motifDegradation);
        $this->assertFalse(app(\App\Services\Triage\DisjoncteurTriageIa::class)->estOuvert());
    }

    public function test_une_reponse_vraiment_inattendue_compte_bien_comme_une_panne(): void
    {
        // Le pendant du vecteur précédent : sans lui, on aurait pu croire que plus rien n'ouvre le
        // circuit. Un 500 sans motif reste un signal de panne.
        config(['masante.triage_ia.enabled' => true, 'masante.triage_ia.disjoncteur_seuil_echecs' => 1]);
        Http::fake(['*/api/v1/triage/score' => Http::response('erreur interne', 500)]);

        $resultat = app(ClientTriageIa::class)->scorer(['reference' => 'triage:1']);

        $this->assertSame('reponse_inattendue_500', $resultat->motifDegradation);
        $this->assertTrue(app(\App\Services\Triage\DisjoncteurTriageIa::class)->estOuvert());
    }

    // ─────────────────────────────────────────────────────────────────────────
    // F24 — un seul modèle répond

    public function test_activer_archive_le_precedent_actif(): void
    {
        $operateur = $this->gouvernant();
        $service = app(ServiceGouvernanceModeleIa::class);

        $premiere = $this->versionValidee('run_1');
        $service->activer($operateur, $premiere);

        $export = ExportJeuEntrainement::first();
        $seconde = VersionModeleIa::create([
            'pays_code' => 'CI', 'numero_version' => 2, 'export_id' => $export->id,
            'statut' => StatutVersionModeleIa::VALIDE, 'mlflow_run_id' => 'run_2', 'cree_le' => now(),
        ]);
        $service->activer($operateur, $seconde);

        $this->assertSame(StatutVersionModeleIa::ARCHIVE, $premiere->fresh()->statut);
        $this->assertSame(StatutVersionModeleIa::ACTIF, $seconde->fresh()->statut);
        $this->assertSame(1, VersionModeleIa::where('statut', StatutVersionModeleIa::ACTIF)->count());
    }

    public function test_un_candidat_ne_peut_pas_etre_mis_en_service(): void
    {
        $version = $this->versionValidee();
        $version->statut = StatutVersionModeleIa::CANDIDAT;
        $version->save();

        $this->expectExceptionMessageMatches('/validée cliniquement/');
        app(ServiceGouvernanceModeleIa::class)->activer($this->gouvernant(), $version);
    }

    public function test_le_rollback_du_paragraphe_8_reactive_une_version_archivee(): void
    {
        $operateur = $this->gouvernant();
        $service = app(ServiceGouvernanceModeleIa::class);

        $version = $this->versionValidee();
        $service->activer($operateur, $version);

        $version->statut = StatutVersionModeleIa::ARCHIVE;
        $version->save();

        // `archive` n'est PAS terminal : c'est ce qui rend le rollback possible (§8).
        $this->assertSame(StatutVersionModeleIa::ACTIF, $service->activer($operateur, $version)->statut);
    }

    public function test_un_compte_non_habilite_ne_peut_pas_mettre_en_service(): void
    {
        $this->expectExceptionMessageMatches('/ia_triage\.valider/');
        app(ServiceGouvernanceModeleIa::class)->activer(User::factory()->create(), $this->versionValidee());
    }

    // ─────────────────────────────────────────────────────────────────────────
    // F28 — la chaîne des prédictions

    public function test_la_chaine_des_predictions_est_intacte_puis_rompue_par_une_modification(): void
    {
        $journal = app(JournalPredictionIa::class);

        foreach (['degrade', 'observation'] as $i => $mode) {
            $journal->inscrire([
                'triage_id' => $i + 1,
                'mode' => $mode,
                'modele_version' => $mode === 'observation' ? 'run_abc' : null,
                'motif_degradation' => $mode === 'degrade' ? 'desactive' : null,
                'latence_ms' => 12,
                'probabilite' => $mode === 'observation' ? 0.71 : null,
                'facteurs_json' => $mode === 'observation' ? [['feature' => 'pouls', 'poids' => 0.3]] : [],
                'explication_json' => $mode === 'observation' ? ['classe_predite' => 'adaptee'] : [],
                'confiance' => $mode === 'observation' ? 'moderee' : null,
                'limites' => $mode === 'observation' ? 'Avis sur l\'orientation.' : null,
            ]);
        }

        $this->assertTrue($journal->verifierChaine()['intacte']);

        // ═══ SIMULER L'ALTÉRATION EXIGE DE DÉSARMER LE DÉCLENCHEUR — ET C'EST LE POINT ═══
        //
        // La table est append-only À DEUX NIVEAUX : Eloquent refuse, et le moteur refuse. Pour
        // éprouver la chaîne il faut donc faire ce que ferait un attaquant qui tient la base :
        // retirer la garde du moteur. Que ce détour soit NÉCESSAIRE est en soi la démonstration que
        // la première barrière tient.
        $this->desarmerAppendOnly('predictions_ia');
        DB::table('predictions_ia')->where('id', 2)->update(['confiance' => 'elevee']);

        $etat = $journal->verifierChaine();
        $this->assertFalse($etat['intacte']);
        $this->assertSame('CONTENU', $etat['rupture']['type']);
    }

    public function test_la_probabilite_est_normalisee_a_la_precision_de_sa_colonne(): void
    {
        // ═══ AJOUTÉ APRÈS UN ÉCHEC EN BASE RÉELLE ═══
        //
        // La colonne est un `decimal(5,4)`. Le service rend `0.752762`, MySQL stocke `0.7528` : la
        // première version hachait la valeur envoyée et la base en gardait une autre, donc la
        // chaîne s'accusait elle-même d'altération sur une entrée intacte. SQLite ne tronquant pas,
        // la suite était verte — d'où ce vecteur, qui vérifie la normalisation EN PHP et vaut donc
        // sur les deux moteurs.
        $journal = app(JournalPredictionIa::class);

        $prediction = $journal->inscrire([
            'triage_id' => 1, 'mode' => 'observation', 'modele_version' => 'run_abc',
            'latence_ms' => 10, 'probabilite' => 0.752762,
            'facteurs_json' => [['feature' => 'pouls', 'poids' => 0.3]],
            'explication_json' => ['classe_predite' => 'adaptee'],
            'confiance' => 'elevee', 'limites' => 'Avis sur l\'orientation.',
        ]);

        $this->assertEqualsWithDelta(0.7528, (float) $prediction->probabilite, 0.00001);
        $this->assertTrue(
            $journal->verifierChaine()['intacte'],
            'la chaîne ne doit jamais accuser une entrée que personne n\'a touchée',
        );
    }

    public function test_une_prediction_ne_se_modifie_ni_ne_se_supprime(): void
    {
        $prediction = app(JournalPredictionIa::class)->inscrire([
            'triage_id' => 1, 'mode' => 'degrade', 'motif_degradation' => 'desactive', 'latence_ms' => 3,
        ]);

        $this->expectExceptionMessageMatches('/append-only/');
        $prediction->update(['confiance' => 'elevee']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // F33 — on refuse au lieu d'arbitrer

    public function test_un_verdict_et_un_niveau_qui_se_contredisent_sont_refuses_en_nommant_la_contradiction(): void
    {
        [, $membre] = $this->famille();
        $triage = $this->triagePour($membre, NiveauTriage::FAIBLE);

        try {
            $this->service()->enregistrer(
                $this->soignant(), $membre, $triage, RegistreRetourTriage::ADAPTEE, null,
                ['niveau_reel' => NiveauTriage::URGENCE],
            );
            $this->fail('la contradiction aurait dû être refusée');
        } catch (\RuntimeException $e) {
            // Le refus NOMME les deux moitiés — refuser sans dire ce qui cloche laisserait chercher.
            $this->assertStringContainsString('contredisent', $e->getMessage());
            $this->assertStringContainsString(NiveauTriage::FAIBLE, $e->getMessage());
            $this->assertStringContainsString(NiveauTriage::URGENCE, $e->getMessage());
            $this->assertStringContainsString(RegistreRetourTriage::SOUS_TRIAGE, $e->getMessage());
        }

        $this->assertSame(0, RetourCliniqueTriage::count(), 'rien ne doit être écrit sur un refus');
    }

    public function test_un_verdict_coherent_avec_le_niveau_est_accepte(): void
    {
        [, $membre] = $this->famille();
        $triage = $this->triagePour($membre, NiveauTriage::FAIBLE);

        $this->service()->enregistrer(
            $this->soignant(), $membre, $triage, RegistreRetourTriage::SOUS_TRIAGE,
            'Détresse respiratoire non signalée.',
            ['niveau_reel' => NiveauTriage::URGENCE],
        );

        $this->assertSame(NiveauTriage::URGENCE, RetourCliniqueTriage::first()->niveau_reel);
    }

    public function test_un_niveau_herite_ne_declenche_aucun_controle(): void
    {
        // Un triage du Module 1 porte `leger`/`modere`/`urgent`, hors de l'échelle patient. On se
        // tait plutôt que de conclure sur une échelle qu'on ne connaît pas.
        [, $membre] = $this->famille();
        $triage = $this->triagePour($membre, 'modere');

        $this->service()->enregistrer(
            $this->soignant(), $membre, $triage, RegistreRetourTriage::ADAPTEE, null,
            ['niveau_reel' => NiveauTriage::URGENCE],
        );

        $this->assertSame(1, RetourCliniqueTriage::count());
    }

    // ─────────────────────────────────────────────────────────────────────────
    // F34 — le diagnostic est un lien, jamais du texte libre

    public function test_le_diagnostic_est_relu_au_referentiel_et_fige(): void
    {
        [, $membre] = $this->famille();
        $triage = $this->triagePour($membre);
        $maladie = $this->maladie();
        $specialite = $this->specialite();

        $this->service()->enregistrer(
            $this->soignant(), $membre, $triage, RegistreRetourTriage::ADAPTEE, null,
            ['maladie_id' => $maladie->id, 'specialite_id' => $specialite->id],
        );

        $retour = RetourCliniqueTriage::first();
        $this->assertSame('MAL000001', $retour->maladie_code);
        $this->assertSame('Paludisme', $retour->maladie_libelle);
        $this->assertSame('cardiologie', $retour->specialite_code);

        // FIGÉ : corriger le référentiel ne réécrit pas ce qu'un médecin a consigné.
        $maladie->update(['libelle' => 'Paludisme grave']);
        $this->assertSame('Paludisme', $retour->fresh()->maladie_libelle);
    }

    public function test_un_diagnostic_inconnu_est_refuse(): void
    {
        [, $membre] = $this->famille();

        $this->expectExceptionMessageMatches('/référentiel national des maladies/');
        $this->service()->enregistrer(
            $this->soignant(), $membre, $this->triagePour($membre), RegistreRetourTriage::ADAPTEE, null,
            ['maladie_id' => 999999],
        );
    }

    public function test_le_serveur_ne_devine_jamais_un_diagnostic(): void
    {
        // Sans `maladie_id`, rien n'est rattaché — même si le libellé d'une maladie apparaissait
        // mot pour mot dans le triage. Rapprocher serait un diagnostic posé par une machine.
        [, $membre] = $this->famille();
        $this->maladie();

        $this->service()->enregistrer(
            $this->soignant(), $membre, $this->triagePour($membre), RegistreRetourTriage::ADAPTEE);

        $this->assertNull(RetourCliniqueTriage::first()->maladie_code);
    }

    public function test_les_trois_faits_traversent_jusquau_jeu_dapprentissage(): void
    {
        [, $membre] = $this->famille();
        $maladie = $this->maladie();

        $this->service()->enregistrer(
            $this->soignant(), $membre, $this->triagePour($membre, NiveauTriage::FAIBLE),
            RegistreRetourTriage::SOUS_TRIAGE, 'Motif.',
            ['niveau_reel' => NiveauTriage::URGENCE, 'maladie_id' => $maladie->id],
        );

        $ligne = JeuDonneesEntrainement::first();
        // Le piège du `$fillable` : une colonne non déclarée est écartée SANS UN MOT (défaut trouvé
        // deux fois en P10c-3-i). Ce vecteur est le seul à pouvoir le voir.
        $this->assertSame(NiveauTriage::URGENCE, $ligne->niveau_reel);
        $this->assertSame('MAL000001', $ligne->maladie_code);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // F35 — le diagnostic dégrade l'anonymat, et le chiffre doit le dire

    public function test_k_estime_baisse_quand_un_diagnostic_rare_entre_dans_lexport(): void
    {
        $service = app(ServiceExportJeuEntrainement::class);

        $lignes = [];
        for ($i = 0; $i < 4; $i++) {
            $lignes[] = ['bande_age' => '25-44', 'sexe' => 'F', 'annee_mois' => '2026-08', 'maladie_code' => null];
        }

        $this->assertSame(4, $service->kEstime($lignes));

        // Une seule ligne porte un diagnostic : elle devient un groupe à elle seule.
        $lignes[0]['maladie_code'] = 'MAL000042';

        $this->assertSame(
            1, $service->kEstime($lignes),
            'ignorer le label le plus discriminant ferait annoncer un k confortable et faux',
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // La chaîne des retours cliniques

    public function test_la_chaine_des_retours_cliniques_est_intacte_et_nomme_le_soignant(): void
    {
        [, $membre] = $this->famille();
        $soignant = $this->soignant();

        $this->service()->enregistrer(
            $soignant, $membre, $this->triagePour($membre), RegistreRetourTriage::ADAPTEE);

        $this->assertTrue(app(JournalRetourClinique::class)->verifierChaine()['intacte']);
        $this->assertSame($soignant->nomLisible(), RetourCliniqueTriage::first()->soignant_nom);

        // `soignant_nom` entre dans l'empreinte : le réécrire doit rompre la chaîne (leçon P6.3,
        // où l'absence du nom laissait remplacer « Dr X » par « Système » sans un bruit).
        $this->desarmerAppendOnly('retours_cliniques_triage');
        DB::table('retours_cliniques_triage')
            ->where('id', 1)->update(['soignant_nom' => 'Système']);

        $this->assertFalse(app(JournalRetourClinique::class)->verifierChaine()['intacte']);
    }

    public function test_un_retour_clinique_ne_se_modifie_ni_ne_se_supprime(): void
    {
        [, $membre] = $this->famille();
        $this->service()->enregistrer(
            $this->soignant(), $membre, $this->triagePour($membre), RegistreRetourTriage::ADAPTEE);

        $this->expectExceptionMessageMatches('/append-only/');
        RetourCliniqueTriage::first()->update(['niveau_reel' => NiveauTriage::URGENCE]);
    }

    /**
     * Une maladie du référentiel — `code` posé par `forceFill`, et ce n'est pas un contournement.
     *
     * P6.8c le garde délibérément HORS `$fillable` pour qu'aucun client ne choisisse son propre
     * code national ; en production c'est un backfill qui le pose. Un test qui passerait par
     * `create(['code' => …])` obtiendrait un code NULL **sans un mot** — le piège du `$fillable`,
     * exactement celui qui a mordu deux fois en P10c-3-i.
     */
    private function maladie(string $code = 'MAL000001', string $libelle = 'Paludisme'): Maladie
    {
        $maladie = new Maladie(['libelle' => $libelle, 'source' => 'demonstration', 'actif' => true]);
        $maladie->forceFill(['code' => $code])->save();

        return $maladie;
    }

    /** Idem pour la spécialité : `code` et `pays_code` sont hors `$fillable` depuis P6.8a. */
    private function specialite(string $code = 'cardiologie'): SpecialiteMedicale
    {
        $specialite = new SpecialiteMedicale([
            'libelle' => 'Cardiologie', 'nature' => 'specialite_medicale', 'actif' => true,
        ]);
        $specialite->forceFill(['code' => $code, 'pays_code' => 'CI'])->save();

        return $specialite;
    }

    /** Retire la garde du MOTEUR — le seul moyen de simuler ce que ferait qui tient la base. */
    private function desarmerAppendOnly(string $table): void
    {
        foreach (['update', 'delete'] as $evenement) {
            DB::unprepared(
                "DROP TRIGGER IF EXISTS ck_{$table}_append_only_{$evenement}");
        }
    }

    private function service(): ServiceRetourTriage
    {
        return app(ServiceRetourTriage::class);
    }
}
