<?php

namespace Tests\Feature;

use App\Models\Protocole;
use App\Models\ProtocoleQuestion;
use App\Models\ProtocoleVersion;
use App\Models\Symptome;
use App\Models\Triage;
use App\Models\TriageReponse;
use App\Services\Protocole\CompilateurProtocole;
use App\Services\Protocole\ControleQualiteProtocole;
use App\Services\Protocole\ProtocoleException;
use App\Services\Protocole\ServiceGouvernanceProtocole;
use App\Services\Referentiel\SourceSymptomesTriage;
use App\Services\Triage\ServiceNiveauTriage;
use App\Services\Triage\ServiceQuestionnaire;
use App\Support\MoteurProtocole;
use App\Support\RegistreActionsProtocole;
use App\Support\RegistreFaitsProtocole;
use Database\Seeders\PortailRolesSeeder;
use Database\Seeders\ProtocoleSeeder;
use Database\Seeders\SpecialiteMedicaleSeeder;
use Database\Seeders\SymptomeSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\GouverneUnReferentiel;
use Tests\Concerns\PublieLeProtocoleDeTriage;
use Tests\TestCase;

/**
 * P10b-3-i — Le questionnaire adaptatif (CDC_08 §4.3b, §4.4 ; §13 étape 4 ; CDC_04 §115).
 *
 * Les vecteurs sont écrits DANS LES DEUX SENS : ce qui doit être accepté et ce qui doit être
 * refusé, chaque refus étant vérifié par SON MOTIF — un refus rendu pour la bonne conclusion mais
 * la mauvaise raison ne prouve rien (leçon P6.5b sur la révocation, P6.8e et P10b-1 sur le
 * quatre-yeux).
 */
class QuestionnaireAdaptatifTest extends TestCase
{
    use GouverneUnReferentiel;
    use PublieLeProtocoleDeTriage;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(SpecialiteMedicaleSeeder::class);
        $this->seed(SymptomeSeeder::class);
    }

    // ═════════════════════════════════════════════════════════════════════════════════════
    // 1. Le moteur — vecteurs PURS, sans base (le §12 exige des tests unitaires du moteur)
    // ═════════════════════════════════════════════════════════════════════════════════════

    public function test_une_question_n_est_posee_que_si_sa_condition_est_remplie(): void
    {
        $regles = [$this->regleDePose('respiratoire', 'au_repos')];

        $avec = MoteurProtocole::evaluer($regles, ['symptome_categorie' => ['respiratoire']]);
        $sans = MoteurProtocole::evaluer($regles, ['symptome_categorie' => ['dentaire']]);

        $this->assertSame(['au_repos'], $this->questionsPosees($avec['actions']));
        $this->assertSame([], $this->questionsPosees($sans['actions']));
    }

    /**
     * UN `reponse.<cle>` INCONNU LÈVE — IL NE VAUT PAS « FAUX ».
     *
     * C'est la décision centrale héritée de P10b-1, étendue au questionnaire. Traiter l'inconnu
     * comme « condition non remplie » rendrait la règle inerte **sans qu'aucun écran ne change** :
     * personne ne saurait que l'impact d'une réponse a cessé de compter.
     */
    public function test_un_fait_de_reponse_mal_forme_leve_au_lieu_de_valoir_faux(): void
    {
        $regles = [[
            'ordre' => 1,
            'libelle' => 'Règle portant une clé impossible',
            'conditions' => [['fait' => 'reponse.CLÉ INVALIDE', 'operateur' => '=', 'valeur' => true]],
            'actions' => [],
        ]];

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/[Ff]ait de protocole inconnu/');

        MoteurProtocole::evaluer($regles, []);
    }

    public function test_les_ajouts_au_score_se_cumulent_dans_une_evaluation(): void
    {
        $regles = [
            $this->regleDImpact(1, 'reponse.a', '=', true, 5),
            $this->regleDImpact(2, 'reponse.b', '=', true, 7),
        ];

        $resultat = MoteurProtocole::evaluer($regles, ['reponse.a' => true, 'reponse.b' => true]);

        $this->assertSame(12, $this->pointsDe($resultat['actions']));
    }

    /**
     * « PAS RÉPONDU » ET « RÉPONDU NON » SONT DEUX FAITS DIFFÉRENTS.
     *
     * Le questionnaire est facultatif depuis le Module 1 : les confondre ferait compter comme une
     * négation clinique le silence d'un patient qui a simplement passé la question.
     */
    public function test_une_question_sans_reponse_n_est_pas_une_reponse_negative(): void
    {
        $regles = [[
            'ordre' => 1,
            'libelle' => 'La question a été renseignée',
            'conditions' => [['fait' => 'reponse.au_repos', 'operateur' => 'existe', 'valeur' => null]],
            'actions' => [[
                'type' => RegistreActionsProtocole::AJOUTER_SCORE, 'valeur' => 3, 'justification' => null,
            ]],
        ]];

        $repondueNon = MoteurProtocole::evaluer($regles, ['reponse.au_repos' => false]);
        $nonRepondue = MoteurProtocole::evaluer($regles, []);

        $this->assertSame(3, $this->pointsDe($repondueNon['actions']));
        $this->assertSame(0, $this->pointsDe($nonRepondue['actions']));
    }

    // ═════════════════════════════════════════════════════════════════════════════════════
    // 2. La boucle de point fixe — R5
    // ═════════════════════════════════════════════════════════════════════════════════════

    /**
     * ═══ LE VECTEUR OBLIGATOIRE DE CET INCRÉMENT ═══
     *
     * Les mêmes réponses, obtenues en UN tour ou en QUATRE, doivent produire exactement le même
     * score et le même niveau. Sans la décision R5 — une seule évaluation finale fait autorité —
     * les `AJOUTER_SCORE` d'une règle déclenchée à plusieurs tours se cumuleraient, et le score
     * dépendrait de la façon dont le patient a répondu plutôt que de ce qu'il a répondu.
     */
    public function test_le_score_ne_depend_pas_du_nombre_de_tours(): void
    {
        $this->preparerTriage();

        $toux = $this->symptome('Toux')->id;
        $reponses = [
            ['cle' => 'duree_jours', 'valeur' => 5],
            ['cle' => 'type_toux', 'valeur' => 'grasse'],
        ];

        // En un seul envoi.
        $direct = $this->postJson('/api/v1/triage/analyser', [
            'symptomes' => [$toux],
            'reponses' => $reponses,
        ])->assertStatus(201);

        // Puis en déroulant réellement la boucle, tour après tour.
        $this->simulerNouvelleRequete();
        $accumulees = [];
        $tours = 0;

        do {
            $tour = $this->postJson('/api/v1/triage/questions', [
                'symptomes' => [$toux],
                'reponses' => $accumulees,
            ])->assertOk();

            $tours++;

            foreach ($tour->json('questions') as $question) {
                foreach ($reponses as $reponse) {
                    if ($reponse['cle'] === $question['cle']) {
                        $accumulees[] = $reponse;
                    }
                }
            }
        } while (! $tour->json('termine') && $tours < 10);

        $boucle = $this->postJson('/api/v1/triage/analyser', [
            'symptomes' => [$toux],
            'reponses' => $accumulees,
        ])->assertStatus(201);

        $this->assertGreaterThan(1, $tours, 'La boucle doit avoir fait au moins deux tours.');
        $this->assertSame($direct->json('score_severite'), $boucle->json('score_severite'));
        $this->assertSame($direct->json('niveau'), $boucle->json('niveau'));
    }

    public function test_une_question_deja_repondue_n_est_jamais_reposee(): void
    {
        $this->preparerTriage();

        $charge = ['symptomes' => [$this->symptome('Toux')->id]];

        $premier = $this->postJson('/api/v1/triage/questions', $charge)->assertOk();
        $this->assertContains('duree_jours', array_column($premier->json('questions'), 'cle'));

        $second = $this->postJson('/api/v1/triage/questions', $charge + [
            'reponses' => [
                ['cle' => 'duree_jours', 'valeur' => 2],
                ['cle' => 'type_toux', 'valeur' => 'seche'],
            ],
        ])->assertOk();

        $this->assertSame([], $second->json('questions'));
        $this->assertTrue($second->json('termine'));
    }

    /**
     * L'ARBRE DU §4.3b — une réponse débloque une question qu'aucun symptôme n'aurait posée.
     */
    public function test_une_reponse_debloque_une_question_supplementaire(): void
    {
        $this->preparerTriage();

        $charge = ['symptomes' => [$this->symptome('Difficulté respiratoire (essoufflement)')->id]];

        $premier = $this->postJson('/api/v1/triage/questions', $charge)->assertOk();
        $this->assertSame(['au_repos'], array_column($premier->json('questions'), 'cle'));

        $second = $this->postJson('/api/v1/triage/questions', $charge + [
            'reponses' => [['cle' => 'au_repos', 'valeur' => true]],
        ])->assertOk();

        $this->assertSame(['intensite'], array_column($second->json('questions'), 'cle'));
        $this->assertFalse($second->json('termine'));
    }

    // ═════════════════════════════════════════════════════════════════════════════════════
    // 3. Les bornes publiées deviennent opposables — R7 (referme le constat X4)
    // ═════════════════════════════════════════════════════════════════════════════════════

    /**
     * ═══ ON REFUSE, ON N'ÉCRÊTE PAS ═══
     *
     * Avant cet incrément, `intensite = 100` sur une échelle publiée de 1 à 10 était multipliée
     * par le coefficient et saturait le score : le patient obtenait le niveau le plus urgent avec
     * une valeur hors de la plage que le référentiel publiait.
     *
     * Ramener 100 à 10 serait pire que refuser : le patient croirait avoir répondu 100 et son
     * dossier porterait 10.
     */
    public function test_une_valeur_hors_de_l_echelle_publiee_est_refusee_et_nommee(): void
    {
        $this->preparerTriage();

        $reponse = $this->postJson('/api/v1/triage/analyser', [
            'symptomes' => [$this->symptome('Douleur thoracique')->id],
            'reponses' => [['cle' => 'intensite', 'valeur' => 100]],
        ]);

        $reponse->assertStatus(422);
        $this->assertStringContainsString('Intensité', $this->premierMessage($reponse));
        $this->assertSame(0, Triage::query()->count(), 'Aucun triage ne doit être enregistré.');
    }

    /**
     * LE MÊME REFUS, PAR LE SERVICE — pas seulement par la requête HTTP.
     *
     * Un vecteur qui ne passerait que par HTTP prouverait le VALIDATEUR et non la garde : un
     * import appelant le service directement ne serait soumis à rien. C'est la parade établie en
     * P6.6b, après quatre occurrences du piège.
     */
    public function test_le_service_refuse_lui_meme_une_valeur_hors_echelle(): void
    {
        $this->preparerTriage();

        $this->expectException(ValidationException::class);

        app(ServiceQuestionnaire::class)->normaliser([
            ['cle' => 'intensite', 'valeur' => 100],
        ]);
    }

    public function test_une_cle_absente_de_la_version_publiee_est_refusee(): void
    {
        $this->preparerTriage();

        $reponse = $this->postJson('/api/v1/triage/analyser', [
            'symptomes' => [$this->symptome('Toux')->id],
            'reponses' => [['cle' => 'question_inventee', 'valeur' => 3]],
        ]);

        $reponse->assertStatus(422);
        $this->assertStringContainsString('question_inventee', $this->premierMessage($reponse));
    }

    public function test_le_service_refuse_lui_meme_une_cle_inconnue(): void
    {
        $this->preparerTriage();

        $this->expectException(ValidationException::class);

        app(ServiceQuestionnaire::class)->normaliser([
            ['cle' => 'question_inventee', 'valeur' => 3],
        ]);
    }

    public function test_une_valeur_hors_des_reponses_possibles_est_refusee(): void
    {
        $this->preparerTriage();

        $reponse = $this->postJson('/api/v1/triage/analyser', [
            'symptomes' => [$this->symptome('Toux')->id],
            'reponses' => [['cle' => 'type_toux', 'valeur' => 'sifflante']],
        ]);

        $reponse->assertStatus(422);
        $this->assertStringContainsString('sifflante', $this->premierMessage($reponse));
    }

    public function test_le_service_refuse_lui_meme_une_option_hors_catalogue(): void
    {
        $this->preparerTriage();

        $this->expectException(ValidationException::class);

        app(ServiceQuestionnaire::class)->normaliser([
            ['cle' => 'type_toux', 'valeur' => 'sifflante'],
        ]);
    }

    public function test_un_texte_sur_une_question_numerique_est_refuse(): void
    {
        $this->preparerTriage();

        $reponse = $this->postJson('/api/v1/triage/analyser', [
            'symptomes' => [$this->symptome('Toux')->id],
            'reponses' => [['cle' => 'duree_jours', 'valeur' => 'longtemps']],
        ]);

        $reponse->assertStatus(422);
        $this->assertStringContainsString('nombre', $this->premierMessage($reponse));
    }

    /**
     * ═══ LE DRAPEAU ROUGE D'UNE RÉPONSE PRIME, PAR UN PLANCHER ET NON PAR UN BOOLÉEN CACHÉ ═══
     *
     * `drapeau_rouge_si_vrai` a disparu : une réponse critique relève désormais le score par
     * `DEFINIR_SCORE_MINIMUM`, l'action que P10b-1 a créée pour le drapeau rouge des symptômes.
     *
     * Le vecteur est CONSTRUIT pour que le plancher soit la seule explication possible : « Frissons »
     * pèse 12 et n'est pas un drapeau rouge, donc sans le plancher le niveau serait le plus faible.
     * Il vérifie aussi que le fait `drapeau_rouge` conserve le sens que le registre lui donne —
     * « au moins un symptôme **ou une réponse** critique » —, sans quoi son libellé mentirait.
     */
    public function test_une_reponse_critique_releve_le_score_et_leve_le_drapeau_rouge(): void
    {
        $this->preparerTriage();

        $frissons = $this->symptome('Frissons')->id;

        $sans = $this->postJson('/api/v1/triage/analyser', ['symptomes' => [$frissons]])
            ->assertStatus(201);

        // Le même cas, plus une fièvre déclarée au-dessus de 40 °C.
        $fievre = $this->symptome('Fièvre élevée')->id;

        $avec = $this->postJson('/api/v1/triage/analyser', [
            'symptomes' => [$frissons, $fievre],
            'reponses' => [['cle' => 'fievre_sup_40', 'valeur' => true]],
        ])->assertStatus(201);

        $this->assertFalse($sans->json('drapeau_rouge'));
        $this->assertTrue($avec->json('drapeau_rouge'));
        $this->assertGreaterThanOrEqual(90, $avec->json('score_severite'));
        $this->assertSame('urgence', $avec->json('niveau'));
    }

    public function test_une_valeur_dans_les_bornes_est_acceptee(): void
    {
        $this->preparerTriage();

        $this->postJson('/api/v1/triage/analyser', [
            'symptomes' => [$this->symptome('Douleur thoracique')->id],
            'reponses' => [['cle' => 'intensite', 'valeur' => 9]],
        ])->assertStatus(201);
    }

    // ═════════════════════════════════════════════════════════════════════════════════════
    // 4. La gouvernance — le contrôle qualité du §7.4
    // ═════════════════════════════════════════════════════════════════════════════════════

    public function test_le_questionnaire_seede_franchit_les_controles_du_7_4(): void
    {
        $this->publierReferentiel(SourceSymptomesTriage::CODE);
        $this->seed(ProtocoleSeeder::class);

        $erreurs = $this->controler($this->brouillonQuestionnaire());

        $this->assertSame([], $erreurs, "Le questionnaire de démonstration n'est pas publiable :\n"
            .implode("\n", $erreurs));
    }

    /**
     * ═══ LE CONTRÔLE QUI OUVRE `reponse.<cle>` ═══
     *
     * P10b-1 refusait ce fait faute de pouvoir vérifier son suffixe. Une condition portant
     * `reponse.duree` au lieu de `reponse.duree_jours` produirait une règle qui ne se déclenche
     * JAMAIS, et rien ne le signalerait.
     *
     * ═══ CE VECTEUR A ÉTÉ RÉÉCRIT : IL PROUVAIT AUTRE CHOSE ═══
     *
     * Il se contentait de `assertStringContainsString('duree', …)`. La **mutation** a montré qu'il
     * survivait à la neutralisation de la garde : sans elle, le contrôle tombe plus bas sur la
     * compatibilité fait/opérateur (le type d'une question inconnue vaut chaîne vide, donc aucun
     * opérateur ne l'accepte) et refuse quand même — **pour la mauvaise raison**, en parlant d'un
     * type au lieu d'une question absente.
     *
     * C'est la cinquième occurrence de cette famille dans le projet, après les
     * `expectExceptionCode` de P6.4c, le contrôle de révocation de P6.5b, le quatre-yeux de P6.8e
     * et celui de P10b-1 : **un refus doit être vérifié PAR SON MOTIF.**
     */
    public function test_une_condition_sur_une_question_inexistante_bloque_la_publication(): void
    {
        $this->publierReferentiel(SourceSymptomesTriage::CODE);
        $this->seed(ProtocoleSeeder::class);

        $version = $this->brouillonQuestionnaire();
        $version->regles()->first()->conditions()->create([
            'ordre' => 2, 'fait' => 'reponse.duree', 'operateur' => '>', 'valeur_json' => [3],
        ]);

        $message = implode(' ', $this->controler($version->refresh()));

        // Le MOTIF, pas seulement le refus : la question n'appartient pas à cette version.
        $this->assertStringContainsString("n'est pas une question de cette version", $message);
        // Et le message doit AIDER le rédacteur en nommant les questions disponibles — refuser
        // sans dire par quoi remplacer ramènerait la faute qu'on ferme (précédent P6.8a).
        $this->assertStringContainsString('duree_jours', $message);
    }

    public function test_poser_une_question_inexistante_bloque_la_publication(): void
    {
        $this->publierReferentiel(SourceSymptomesTriage::CODE);
        $this->seed(ProtocoleSeeder::class);

        $version = $this->brouillonQuestionnaire();
        $version->regles()->first()->actions()->create([
            'ordre' => 9, 'type' => RegistreActionsProtocole::POSER_QUESTION,
            'valeur_json' => ['question_fantome'],
        ]);

        $erreurs = $this->controler($version->refresh());

        $this->assertNotEmpty($erreurs);
        $this->assertStringContainsString('question_fantome', implode(' ', $erreurs));
    }

    public function test_un_operateur_incompatible_avec_le_type_de_question_bloque_la_publication(): void
    {
        $this->publierReferentiel(SourceSymptomesTriage::CODE);
        $this->seed(ProtocoleSeeder::class);

        $version = $this->brouillonQuestionnaire();
        $version->regles()->first()->conditions()->create([
            // `au_repos` est booléenne : une comparaison numérique n'y veut rien dire.
            'ordre' => 2, 'fait' => 'reponse.au_repos', 'operateur' => '>=', 'valeur_json' => [1],
        ]);

        $erreurs = $this->controler($version->refresh());

        $this->assertNotEmpty($erreurs);
        $this->assertStringContainsString('au_repos', implode(' ', $erreurs));
    }

    /**
     * UNE QUESTION À CHOIX SANS RÉPONSE POSSIBLE NE CASSE RIEN DE VISIBLE.
     *
     * L'écran afficherait l'énoncé et aucun bouton, le patient passerait à la suivante, et rien ne
     * le signalerait. C'est le cas que l'ancien modèle à deux listes parallèles produisait
     * silencieusement (constat X5).
     */
    public function test_une_question_a_choix_sans_reponse_bloque_la_publication(): void
    {
        $this->publierReferentiel(SourceSymptomesTriage::CODE);
        $this->seed(ProtocoleSeeder::class);

        $version = $this->brouillonQuestionnaire();
        $version->questions()->where('cle', 'type_toux')->firstOrFail()->reponses()->delete();

        $erreurs = $this->controler($version->refresh());

        $this->assertNotEmpty($erreurs);
        $this->assertStringContainsString('type_toux', implode(' ', $erreurs));
    }

    /**
     * UN QUESTIONNAIRE NE PEUT PAS CONDITIONNER SUR `score`.
     *
     * Le score n'est pas encore clos quand on interroge le patient : la règle répondrait
     * différemment selon le tour. Le refus doit NOMMER les faits utilisables — refuser sans dire
     * par quoi remplacer ramènerait la faute qu'on ferme (précédent P6.8a).
     */
    public function test_un_questionnaire_ne_peut_pas_conditionner_sur_le_score(): void
    {
        $this->publierReferentiel(SourceSymptomesTriage::CODE);
        $this->seed(ProtocoleSeeder::class);

        $version = $this->brouillonQuestionnaire();
        $version->regles()->first()->conditions()->create([
            'ordre' => 2, 'fait' => 'score', 'operateur' => '>', 'valeur_json' => [50],
        ]);

        $erreurs = $this->controler($version->refresh());

        $this->assertNotEmpty($erreurs);
        $this->assertStringContainsString('score_symptomes', implode(' ', $erreurs));
    }

    /**
     * LE MOTEUR REFUSE UNE ÉCHELLE AUX BORNES INVERSÉES — pas seulement le contrôle qualité.
     *
     * Une garantie qui ne tient qu'au chemin applicatif n'en est pas une (leçon du G2 de P6.6a).
     * `CHECK` impossible : `version_id` est `cascadeOnDelete` — erreur 3823, le mur de P6.3.
     */
    public function test_le_moteur_refuse_une_echelle_aux_bornes_inversees(): void
    {
        $this->publierReferentiel(SourceSymptomesTriage::CODE);
        $this->seed(ProtocoleSeeder::class);

        $version = $this->brouillonQuestionnaire();

        $this->expectException(QueryException::class);

        DB::table('protocole_questions')->insert([
            'version_id' => $version->id,
            'cle' => 'bornes_absurdes',
            'libelle' => 'Une échelle impossible',
            'type' => 'echelle',
            'valeur_min' => 10,
            'valeur_max' => 1,
            'ordre' => 99,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * ANTI-SUBSTITUTION — modifier une question après signature rend les validations caduques.
     *
     * Sans cela il suffirait de faire signer un questionnaire anodin puis d'en changer les bornes.
     * C'est le contrôle « destination révoquée depuis le figeage » de P5.5b-2, transposé de
     * l'argent à ce qu'on demande à un patient.
     */
    public function test_modifier_une_question_apres_signature_rend_les_validations_caduques(): void
    {
        $this->publierReferentiel(SourceSymptomesTriage::CODE);
        $this->seed(PortailRolesSeeder::class);
        $this->seed(ProtocoleSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $version = $this->brouillonQuestionnaire();
        $gouvernance = app(ServiceGouvernanceProtocole::class);
        $relecteur = $this->agentProtocole(...array_values(ServiceGouvernanceProtocole::PERMISSIONS_VALIDATION));

        foreach (array_keys(ServiceGouvernanceProtocole::PERMISSIONS_VALIDATION) as $type) {
            $gouvernance->valider($version, $relecteur, $type, 'favorable', 'Relecteur '.$type);
        }

        // La substitution : l'énoncé change APRÈS les quatre signatures.
        $version->questions()->where('cle', 'intensite')->firstOrFail()
            ->update(['libelle' => 'Énoncé remplacé après relecture']);

        $this->expectException(ProtocoleException::class);

        $gouvernance->publier(
            $version->refresh(),
            $this->agentProtocole(ServiceGouvernanceProtocole::PERMISSION_PUBLIER),
        );
    }

    // ═════════════════════════════════════════════════════════════════════════════════════
    // 5. Le déménagement — les questions quittent le référentiel des symptômes
    // ═════════════════════════════════════════════════════════════════════════════════════

    public function test_les_questions_ont_quitte_l_instantane_des_symptomes(): void
    {
        $this->publierReferentiel(SourceSymptomesTriage::CODE);

        $contenu = app(SourceSymptomesTriage::class)->extraire();

        foreach ($contenu as $ligne) {
            $this->assertArrayNotHasKey('questions_complementaires_json', $ligne);
        }
    }

    public function test_la_liste_des_symptomes_ne_sert_plus_les_questions(): void
    {
        $this->publierReferentiel(SourceSymptomesTriage::CODE);

        $reponse = $this->getJson('/api/v1/symptomes')->assertOk();

        foreach ($reponse->json('symptomes') as $symptome) {
            $this->assertArrayNotHasKey('questions_complementaires_json', $symptome);
        }
    }

    /**
     * UN `UPDATE` DIRECT SUR LA TABLE DES SYMPTÔMES N'A PLUS AUCUN EFFET SUR LE QUESTIONNAIRE.
     *
     * C'est la garantie que la bascule apporte : la règle ne se corrige plus qu'en republiant, ce
     * qui passe par les quatre validations et le quatre-yeux.
     */
    public function test_un_update_direct_sur_les_symptomes_ne_change_pas_le_questionnaire(): void
    {
        $this->preparerTriage();

        DB::table('symptomes')
            ->where('nom_fr', 'Toux')
            ->update(['questions_complementaires_json' => json_encode([
                ['cle' => 'question_injectee', 'libelle' => 'Question posée par UPDATE direct', 'type' => 'booleen'],
            ])]);

        $this->simulerNouvelleRequete();

        $tour = $this->postJson('/api/v1/triage/questions', [
            'symptomes' => [$this->symptome('Toux')->id],
        ])->assertOk();

        $cles = array_column($tour->json('questions'), 'cle');

        $this->assertNotContains('question_injectee', $cles);
        $this->assertContains('duree_jours', $cles);
    }

    /**
     * REFUS BRUYANT — jamais de repli sur la colonne héritée.
     *
     * Un repli laisserait un oubli de publication INVISIBLE : le triage poserait les anciennes
     * questions et personne ne saurait que la version gouvernée n'est pas en vigueur.
     */
    public function test_le_triage_refuse_bruyamment_tant_que_le_questionnaire_n_est_pas_publie(): void
    {
        $this->publierReferentiel(SourceSymptomesTriage::CODE);
        $this->seed(PortailRolesSeeder::class);
        $this->seed(ProtocoleSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        // Seul le protocole de NIVEAU est publié : le questionnaire reste en brouillon.
        $this->publierProtocole(ServiceNiveauTriage::CODE);
        $this->simulerNouvelleRequete();

        $charge = ['symptomes' => [$this->symptome('Toux')->id]];

        $this->postJson('/api/v1/triage/questions', $charge)->assertStatus(503);
        $this->postJson('/api/v1/triage/analyser', $charge)->assertStatus(503);
    }

    // ═════════════════════════════════════════════════════════════════════════════════════
    // 6. L'archive — CDC_04 §115
    // ═════════════════════════════════════════════════════════════════════════════════════

    public function test_les_reponses_sont_archivees_en_table_avec_leur_enonce(): void
    {
        $this->preparerTriage();

        $reponse = $this->postJson('/api/v1/triage/analyser', [
            'symptomes' => [$this->symptome('Toux')->id],
            'reponses' => [
                ['cle' => 'duree_jours', 'valeur' => 5],
                ['cle' => 'type_toux', 'valeur' => 'grasse'],
            ],
        ])->assertStatus(201);

        $triage = Triage::findOrFail($reponse->json('triage_id'));

        $this->assertCount(2, $triage->reponses);
        $this->assertSame([], $triage->reponses_json, 'La colonne héritée ne doit plus être écrite.');

        $duree = $triage->reponses->firstWhere('question_cle', 'duree_jours');
        $this->assertSame('Depuis combien de jours ?', $duree->question_libelle);
        $this->assertSame('5', $duree->valeur);
        $this->assertSame(ServiceQuestionnaire::CODE, $duree->protocole_code);
        $this->assertNotNull($duree->protocole_version);
    }

    /**
     * L'ÉNONCÉ EST FIGÉ : republier le questionnaire ne réécrit pas ce qu'un patient a lu.
     */
    public function test_l_enonce_archive_ne_suit_pas_un_renommage_de_la_question(): void
    {
        $this->preparerTriage();

        $this->postJson('/api/v1/triage/analyser', [
            'symptomes' => [$this->symptome('Toux')->id],
            'reponses' => [['cle' => 'duree_jours', 'valeur' => 5]],
        ])->assertStatus(201);

        ProtocoleQuestion::query()->where('cle', 'duree_jours')
            ->update(['libelle' => 'Énoncé réécrit après coup']);

        $this->assertSame(
            'Depuis combien de jours ?',
            TriageReponse::query()->where('question_cle', 'duree_jours')->value('question_libelle'),
        );
    }

    /**
     * UN TRIAGE ANTÉRIEUR À LA BASCULE RESTE LISIBLE, DANS SA FORME D'ORIGINE.
     *
     * Lui fabriquer des lignes dans `triage_reponses` serait un mensonge d'archive — précédent L2,
     * où les mesures antérieures restent sans version de référentiel.
     */
    public function test_un_triage_anterieur_reste_lisible_par_sa_colonne_heritee(): void
    {
        $this->preparerTriage();

        $ancien = Triage::create([
            'patient_nom' => 'Patient historique',
            'symptomes_json' => [['id' => 1, 'nom' => 'Toux', 'poids' => 10]],
            'reponses_json' => [['cle' => 'duree_jours', 'valeur' => 4, 'valeur_impact' => 8]],
            'score_severite' => 18,
            'niveau' => 'modere',
            'recommandation_texte' => 'Consultation recommandée.',
        ]);

        $fiche = $this->getJson('/api/v1/triage/'.$ancien->id.'/fiche?jeton='.$ancien->jeton_partage)
            ->assertOk();

        $this->assertCount(1, $fiche->json('fiche.reponses'));
        $this->assertSame('duree_jours', $fiche->json('fiche.reponses.0.cle'));
        $this->assertSame(0, $ancien->reponses()->count(), 'Aucune ligne ne doit lui être fabriquée.');
    }

    // ═════════════════════════════════════════════════════════════════════════════════════
    // 7. Le registre des faits — la forme seulement
    // ═════════════════════════════════════════════════════════════════════════════════════

    public function test_le_registre_reconnait_la_forme_d_un_fait_de_reponse(): void
    {
        $this->assertTrue(RegistreFaitsProtocole::existe('reponse.duree_jours'));
        $this->assertSame('duree_jours', RegistreFaitsProtocole::cleReponse('reponse.duree_jours'));

        // La FORME seulement : le registre ne connaît aucun protocole, donc aucun type.
        $this->assertNull(RegistreFaitsProtocole::type('reponse.duree_jours'));

        // Une clé mal formée n'est pas un fait — le suffixe n'est pas libre.
        $this->assertFalse(RegistreFaitsProtocole::existe('reponse.'));
        $this->assertFalse(RegistreFaitsProtocole::existe('reponse.Clé Invalide'));
    }

    // ═════════════════════════════════════════════════════════════════════════════════════
    // Utilitaires
    // ═════════════════════════════════════════════════════════════════════════════════════

    /** Le déploiement complet : symptômes publiés, protocole de niveau publié, questionnaire publié. */
    private function preparerTriage(): void
    {
        $this->publierReferentiel(SourceSymptomesTriage::CODE);
        $this->publierProtocoleDeTriage();
        $this->simulerNouvelleRequete();
    }

    private function brouillonQuestionnaire(): ProtocoleVersion
    {
        return Protocole::query()
            ->where('code', ServiceQuestionnaire::CODE)
            ->firstOrFail()
            ->versions()
            ->where('etat', ProtocoleVersion::BROUILLON)
            ->firstOrFail();
    }

    /** @return array<int, string> */
    private function controler(ProtocoleVersion $version): array
    {
        return app(ControleQualiteProtocole::class)
            ->controler(app(CompilateurProtocole::class)->extraire($version));
    }

    private function symptome(string $nom): Symptome
    {
        return Symptome::query()->where('nom_fr', $nom)->firstOrFail();
    }

    private function premierMessage(TestResponse $reponse): string
    {
        $erreurs = $reponse->json('errors') ?? [];

        return (string) (reset($erreurs)[0] ?? $reponse->json('message'));
    }

    /** @param  array<int, array<string, mixed>>  $actions */
    private function questionsPosees(array $actions): array
    {
        return array_values(array_map(
            static fn (array $a): string => (string) $a['valeur'],
            array_filter($actions, static fn (array $a): bool => $a['type'] === RegistreActionsProtocole::POSER_QUESTION),
        ));
    }

    /** @param  array<int, array<string, mixed>>  $actions */
    private function pointsDe(array $actions): int
    {
        $total = 0;

        foreach ($actions as $action) {
            if ($action['type'] === RegistreActionsProtocole::AJOUTER_SCORE) {
                $total += (int) $action['valeur'];
            }
        }

        return $total;
    }

    /** @return array<string, mixed> */
    private function regleDePose(string $categorie, string $cle): array
    {
        return [
            'ordre' => 1,
            'libelle' => 'Poser '.$cle,
            'conditions' => [['fait' => 'symptome_categorie', 'operateur' => 'contient', 'valeur' => $categorie]],
            'actions' => [[
                'type' => RegistreActionsProtocole::POSER_QUESTION, 'valeur' => $cle, 'justification' => null,
            ]],
        ];
    }

    /** @return array<string, mixed> */
    private function regleDImpact(int $ordre, string $fait, string $operateur, mixed $valeur, int $points): array
    {
        return [
            'ordre' => $ordre,
            'libelle' => 'Impact de '.$fait,
            'conditions' => [['fait' => $fait, 'operateur' => $operateur, 'valeur' => $valeur]],
            'actions' => [[
                'type' => RegistreActionsProtocole::AJOUTER_SCORE, 'valeur' => $points, 'justification' => null,
            ]],
        ];
    }
}
