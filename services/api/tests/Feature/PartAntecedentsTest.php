<?php

namespace Tests\Feature;

use App\Models\MembreFamille;
use App\Models\Protocole;
use App\Models\Symptome;
use App\Models\User;
use App\Services\Protocole\ControleQualiteProtocole;
use App\Services\Protocole\ServiceGouvernanceProtocole;
use App\Services\Referentiel\SourceSymptomesTriage;
use App\Services\Triage\FaitsTriage;
use App\Services\Triage\ServiceNiveauTriage;
use App\Services\Triage\ServicePlafondAntecedents;
use App\Services\Triage\ServiceQuestionnaire;
use App\Support\RegistreActionsProtocole;
use App\Support\RegistreContextesProtocole;
use Database\Seeders\PortailRolesSeeder;
use Database\Seeders\ProtocoleSeeder;
use Database\Seeders\ReferentielMesureSeeder;
use Database\Seeders\SpecialiteMedicaleSeeder;
use Database\Seeders\SymptomeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\GouverneUnReferentiel;
use Tests\Concerns\PublieLeProtocoleDeTriage;
use Tests\TestCase;

/**
 * P10b-3-ii — La part des antécédents, bornée par un protocole publié (CDC_08 §1.2).
 *
 * Les vecteurs sont écrits dans les deux sens, et chaque refus est vérifié PAR SON MOTIF : un refus
 * rendu pour la bonne conclusion mais la mauvaise raison ne prouve rien (leçon P6.5b, P6.8e,
 * P10b-1, P10b-3-i).
 */
class PartAntecedentsTest extends TestCase
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
    // 1. L'ASSEMBLAGE DES FAITS A UNE SEULE SOURCE (constat Z1)
    // ═════════════════════════════════════════════════════════════════════════════════════

    public function test_la_base_des_faits_ne_porte_pas_les_antecedents(): void
    {
        // La séparation n'est pas cosmétique : `POST /triage/questions` ne connaît pas le membre.
        // Si les faits d'antécédents étaient dans la base, ils y vaudraient 0 et vaudraient autre
        // chose dans `analyser()` — une règle en dépendant répondrait selon l'endpoint.
        $this->seed(ProtocoleSeeder::class);
        $base = FaitsTriage::base(Symptome::query()->limit(2)->get(), 30, 'F');

        $this->assertArrayNotHasKey('score_antecedents_brut', $base);
        $this->assertArrayNotHasKey('nb_antecedents', $base);
        $this->assertArrayNotHasKey('score', $base, 'le score n\'est pas encore assemblé');
        $this->assertArrayNotHasKey('score_antecedents', $base);
    }

    public function test_les_faits_des_antecedents_s_ajoutent_et_sont_exacts(): void
    {
        $base = FaitsTriage::base(Symptome::query()->limit(1)->get(), 30, 'F');

        $avec = FaitsTriage::avecAntecedents($base, [
            ['libelle' => 'Asthme', 'impact_triage' => 8],
            ['libelle' => 'Diabète', 'impact_triage' => 15],
        ]);

        $this->assertSame(23, $avec['score_antecedents_brut']);
        $this->assertSame(2, $avec['nb_antecedents']);
        $this->assertSame($base['score_symptomes'], $avec['score_symptomes'], 'la base est préservée');
    }

    // ═════════════════════════════════════════════════════════════════════════════════════
    // 2. LA BORNE EST UNE DÉCISION DU PROTOCOLE
    // ═════════════════════════════════════════════════════════════════════════════════════

    public function test_sous_la_borne_la_somme_declaree_est_retenue_telle_quelle(): void
    {
        $this->publierTout();

        $part = app(ServicePlafondAntecedents::class)->part(
            FaitsTriage::avecAntecedents(FaitsTriage::base(Symptome::query()->limit(1)->get()), [
                ['libelle' => 'Asthme', 'impact_triage' => 7],
            ])
        );

        $this->assertSame(7, $part['valeur']);
        $this->assertSame(20, $part['borne']);
        $this->assertSame(7, $part['brut']);
    }

    public function test_au_dessus_la_part_est_bornee_a_la_valeur_du_protocole(): void
    {
        $this->publierTout();

        $part = app(ServicePlafondAntecedents::class)->part(
            FaitsTriage::avecAntecedents(FaitsTriage::base(Symptome::query()->limit(1)->get()), [
                ['libelle' => 'A', 'impact_triage' => 18],
                ['libelle' => 'B', 'impact_triage' => 19],
            ])
        );

        $this->assertSame(20, $part['valeur']);
        $this->assertSame(37, $part['brut'], 'la somme brute reste rendue telle quelle');
    }

    public function test_la_borne_change_avec_la_version_publiee_sans_toucher_une_ligne_de_code(): void
    {
        // LE VECTEUR CENTRAL. Le même patient, les mêmes antécédents, deux versions du protocole :
        // deux parts. C'est ce que « le seuil quitte le code » veut dire.
        $this->publierTout();

        $antecedents = [['libelle' => 'A', 'impact_triage' => 30]];
        $faits = FaitsTriage::avecAntecedents(FaitsTriage::base(Symptome::query()->limit(1)->get()), $antecedents);

        $this->assertSame(20, app(ServicePlafondAntecedents::class)->part($faits)['valeur']);

        $this->republierBorne(5);

        $this->assertSame(5, app(ServicePlafondAntecedents::class)->part($faits)['valeur']);
    }

    public function test_le_triage_refuse_bruyamment_tant_que_la_borne_n_est_pas_publiee(): void
    {
        // Publication de tout SAUF la borne : le refus doit être explicite, jamais un repli sur 20.
        $this->seed(PortailRolesSeeder::class);
        $this->seed(ProtocoleSeeder::class);
        $this->publierReferentiel(SourceSymptomesTriage::CODE);
        // P10c-1 — `TRIAGE-NIVEAU` porte une regle sur `constante.temperature` : les seuils
        // doivent etre en vigueur AVANT lui. Ordre de deploiement reel, pas commodite de test.
        $this->seed(ReferentielMesureSeeder::class);
        $this->publierLesSeuils();
        $this->publierProtocole(ServiceNiveauTriage::CODE);
        $this->publierProtocole(ServiceQuestionnaire::CODE);
        $this->app->forgetScopedInstances();

        $this->assertFalse(app(ServicePlafondAntecedents::class)->estEnVigueur());

        $reponse = $this->postJson('/api/v1/triage/analyser', [
            'symptomes' => [Symptome::query()->value('id')],
            'patient_age' => 30,
        ]);

        $reponse->assertStatus(503);

        // === LE MOTIF, PAS SEULEMENT LE REFUS ===
        //
        // La premiere ecriture cherchait le mot borne dans le message. Une MUTATION l'a prise
        // en defaut : garde neutralisee, l'execution tombait sur l'AUTRE refus (aucune regle
        // ne s'applique), qui rend aussi un 503 parlant de borne. Le vecteur restait vert en
        // ayant perdu ce qu'il gardait. Sixieme instance de cette famille.
        $this->assertStringContainsString('mise en vigueur', $reponse->json('message') ?? '');
    }

    public function test_le_refus_vaut_meme_pour_un_patient_sans_aucun_antecedent(): void
    {
        // Sinon un oubli de publication passerait inaperçu sur la majorité des triages — ceux des
        // patients sans carnet — et ne se signalerait que sur les autres.
        $this->seed(PortailRolesSeeder::class);
        $this->seed(ProtocoleSeeder::class);
        $this->publierReferentiel(SourceSymptomesTriage::CODE);
        // P10c-1 — `TRIAGE-NIVEAU` porte une regle sur `constante.temperature` : les seuils
        // doivent etre en vigueur AVANT lui. Ordre de deploiement reel, pas commodite de test.
        $this->seed(ReferentielMesureSeeder::class);
        $this->publierLesSeuils();
        $this->publierProtocole(ServiceNiveauTriage::CODE);
        $this->publierProtocole(ServiceQuestionnaire::CODE);
        $this->app->forgetScopedInstances();

        $this->postJson('/api/v1/triage/analyser', [
            'symptomes' => [Symptome::query()->value('id')],
        ])->assertStatus(503);
    }

    // ═════════════════════════════════════════════════════════════════════════════════════
    // 3. LES FRONTIÈRES DE PHASE SONT VÉRIFIÉES, PAS CONVENUES
    // ═════════════════════════════════════════════════════════════════════════════════════

    public function test_un_protocole_publie_dont_aucune_regle_ne_s_applique_refuse_aussi(): void
    {
        // Le SECOND refus, distinct du premier : le protocole est bien en vigueur, mais aucune de
        // ses regles ne decide de borne. Se rabattre sur la somme brute laisserait un protocole
        // inoperant se comporter comme s'il n'y avait pas de borne, et sans bruit.
        // La table de travail ne sert à RIEN ici : c'est l'instantané publié qui est lu (garantie
        // de L1+L2). Il faut donc republier une version dont la règle ne se déclenche jamais.
        $this->publierTout();
        $this->republierBorne(20, ['nb_antecedents', '>', 999]);

        $reponse = $this->postJson('/api/v1/triage/analyser', [
            'symptomes' => [Symptome::query()->value('id')],
        ]);

        $reponse->assertStatus(503);
        $this->assertStringContainsString('aucune de ses règles', $reponse->json('message') ?? '');
    }

    public function test_deux_bornes_divergentes_sont_refusees_et_nommees(): void
    {
        // Vecteur MANQUANT, revele par la mutation : la garde existait, rien ne la tenait.
        // L'action est exclusive — deux bornes se contredisent, et en choisir une serait inventer
        // une semantique que personne n'a signee.
        $this->publierTout();
        $this->publierSecondeBorne(9);

        $reponse = $this->postJson('/api/v1/triage/analyser', [
            'symptomes' => [Symptome::query()->value('id')],
        ]);

        $reponse->assertStatus(503);
        $message = $reponse->json('message') ?? '';
        $this->assertStringContainsString('20', $message);
        $this->assertStringContainsString('9', $message);
        $this->assertStringContainsString('§8', $message, 'le refus renvoie au départage humain');
    }

    public function test_le_poids_des_symptomes_entre_reellement_dans_la_base(): void
    {
        // Ajoute apres qu'une mutation eut fausse `score_symptomes` a 0 SANS QUE RIEN NE TOMBE :
        // les vecteurs existants comparaient deux scores qui se decalaient ensemble. Une source
        // unique n'est prouvee que si l'on verifie CE QU'ELLE PRODUIT, pas seulement que deux
        // appelants en dependent.
        $symptomes = Symptome::query()->limit(3)->get();

        $attendu = (int) $symptomes->sum('poids_severite');
        $this->assertGreaterThan(0, $attendu, 'le jeu de démonstration doit peser quelque chose');

        $this->assertSame($attendu, FaitsTriage::base($symptomes)['score_symptomes']);
    }

    public function test_un_questionnaire_ne_peut_pas_conditionner_sur_les_antecedents(): void
    {
        $erreurs = app(ControleQualiteProtocole::class)->controler([
            'metadonnees' => [
                'niveau_preuve' => 'D',
                'population' => 'Tous',
                'domaine' => Protocole::DOMAINE_TRIAGE,
                'contextes' => [RegistreContextesProtocole::TRIAGE_QUESTIONNAIRE],
            ],
            'references' => [['type' => 'document', 'libelle' => 'x', 'citation' => 'y']],
            'questions' => [],
            'regles' => [[
                'ordre' => 1,
                'libelle' => 'Règle fautive',
                'conditions' => [['fait' => 'score_antecedents_brut', 'operateur' => '>', 'valeur' => 5]],
                'actions' => [['type' => RegistreActionsProtocole::AJOUTER_SCORE, 'valeur' => 3]],
            ]],
        ]);

        $motif = implode(' | ', $erreurs);

        $this->assertStringContainsString('antécédents', $motif);
        $this->assertStringContainsString('triage_antecedents', $motif,
            'le refus doit NOMMER le contexte à utiliser à la place');
    }

    public function test_un_protocole_d_antecedents_ne_peut_pas_conditionner_sur_le_score(): void
    {
        $erreurs = app(ControleQualiteProtocole::class)->controler([
            'metadonnees' => [
                'niveau_preuve' => 'D',
                'population' => 'Tous',
                'domaine' => Protocole::DOMAINE_TRIAGE,
                'contextes' => [RegistreContextesProtocole::TRIAGE_ANTECEDENTS],
            ],
            'references' => [['type' => 'document', 'libelle' => 'x', 'citation' => 'y']],
            'questions' => [],
            'regles' => [[
                'ordre' => 1,
                'libelle' => 'Règle fautive',
                'conditions' => [['fait' => 'score', 'operateur' => '>', 'valeur' => 50]],
                'actions' => [['type' => RegistreActionsProtocole::BORNER_SCORE_ANTECEDENTS, 'valeur' => 20]],
            ]],
        ]);

        $motif = implode(' | ', $erreurs);

        $this->assertStringContainsString('AVANT que le score ne soit assemblé', $motif);
        $this->assertStringContainsString('score_antecedents_brut', $motif);
    }

    public function test_un_protocole_d_antecedents_ne_peut_pas_conditionner_sur_ce_qu_il_decide(): void
    {
        $erreurs = app(ControleQualiteProtocole::class)->controler([
            'metadonnees' => [
                'niveau_preuve' => 'D',
                'population' => 'Tous',
                'domaine' => Protocole::DOMAINE_TRIAGE,
                'contextes' => [RegistreContextesProtocole::TRIAGE_ANTECEDENTS],
            ],
            'references' => [['type' => 'document', 'libelle' => 'x', 'citation' => 'y']],
            'questions' => [],
            'regles' => [[
                'ordre' => 1,
                'libelle' => 'Règle circulaire',
                'conditions' => [['fait' => 'score_antecedents', 'operateur' => '>', 'valeur' => 5]],
                'actions' => [['type' => RegistreActionsProtocole::BORNER_SCORE_ANTECEDENTS, 'valeur' => 20]],
            ]],
        ]);

        $this->assertStringContainsString("c'est la valeur qu'il DÉCIDE", implode(' | ', $erreurs));
    }

    public function test_l_action_de_borne_exige_une_valeur(): void
    {
        $erreurs = app(ControleQualiteProtocole::class)->controler([
            'metadonnees' => [
                'niveau_preuve' => 'D',
                'population' => 'Tous',
                'domaine' => Protocole::DOMAINE_TRIAGE,
                'contextes' => [RegistreContextesProtocole::TRIAGE_ANTECEDENTS],
            ],
            'references' => [['type' => 'document', 'libelle' => 'x', 'citation' => 'y']],
            'questions' => [],
            'regles' => [[
                'ordre' => 1,
                'libelle' => 'Borne sans valeur',
                'conditions' => [],
                'actions' => [['type' => RegistreActionsProtocole::BORNER_SCORE_ANTECEDENTS, 'valeur' => null]],
            ]],
        ]);

        $this->assertNotEmpty($erreurs, 'une borne sans valeur ne dit rien');
    }

    // ═════════════════════════════════════════════════════════════════════════════════════
    // 4. LE SCORE ASSEMBLÉ
    // ═════════════════════════════════════════════════════════════════════════════════════

    public function test_le_score_du_triage_tient_compte_de_la_part_bornee(): void
    {
        $this->publierTout();

        $membre = $this->membreAvecAntecedents([30]);

        $sans = $this->postJson('/api/v1/triage/analyser', [
            'symptomes' => [$this->symptomeSansDrapeau()->id],
            'patient_age' => 30,
        ])->assertStatus(201)->json('score_severite');

        $avec = $this->actingAs($membre->user)->postJson('/api/v1/triage/analyser', [
            'symptomes' => [$this->symptomeSansDrapeau()->id],
            'patient_age' => 30,
            'membre_id' => $membre->id,
        ])->assertStatus(201)->json('score_severite');

        $this->assertSame($sans + 20, $avec, 'la part est bornée à 20, pas à 30');
    }

    public function test_le_drapeau_rouge_leve_par_une_reponse_arrive_bien_au_protocole_de_niveau(): void
    {
        // Piège attrapé à l'implémentation : l'union de tableaux `+` garde la valeur de GAUCHE.
        // `drapeau_rouge` est dans la base (symptômes seuls) et vient d'être relevé par le plancher
        // d'une réponse — avec `+`, la mise à jour aurait été ignorée en silence, et le drapeau
        // rouge d'une réponse aurait disparu pour la SECONDE fois.
        $this->publierTout();

        $reponse = $this->postJson('/api/v1/triage/analyser', [
            'symptomes' => [$this->symptomeSansDrapeau()->id],
            'patient_age' => 30,
            'reponses' => [['cle' => 'fievre_sup_40', 'valeur' => true]],
        ])->assertStatus(201);

        $this->assertTrue((bool) $reponse->json('drapeau_rouge'));
        $this->assertSame('urgence', $reponse->json('niveau'));
    }

    // ═════════════════════════════════════════════════════════════════════════════════════
    // Fixtures
    // ═════════════════════════════════════════════════════════════════════════════════════

    private function publierTout(): void
    {
        $this->seed(PortailRolesSeeder::class);
        $this->seed(ProtocoleSeeder::class);
        $this->publierReferentiel(SourceSymptomesTriage::CODE);
        $this->publierProtocoleDeTriage();
    }

    /**
     * Republie la borne avec une autre valeur, par le chemin nominal de gouvernance.
     *
     * Aucun raccourci par la base : ouverture d'un brouillon, règles, quatre validations §7,
     * publication à un second compte. C'est le chemin qu'un exploitant emprunterait.
     */
    private function republierBorne(int $valeur, ?array $condition = null): void
    {
        $gouvernance = app(ServiceGouvernanceProtocole::class);
        $protocole = Protocole::query()->where('code', ServicePlafondAntecedents::CODE)->firstOrFail();

        $auteur = $this->agentProtocole(ServiceGouvernanceProtocole::PERMISSION_REDIGER);

        $version = $gouvernance->ouvrirBrouillon($protocole, $auteur, '2026.2', 'Vecteur : autre borne', [
            'niveau_preuve' => 'D',
            'population' => 'Tous publics',
            'conditions_utilisation' => 'Vecteur de test.',
        ]);

        $regle = $version->regles()->create(['ordre' => 1, 'libelle' => 'Borne abaissée']);

        if ($condition !== null) {
            $regle->conditions()->create([
                'ordre' => 1,
                'fait' => $condition[0],
                'operateur' => $condition[1],
                'valeur_json' => [$condition[2]],
            ]);
        }

        $regle->actions()->create([
            'ordre' => 1,
            'type' => RegistreActionsProtocole::BORNER_SCORE_ANTECEDENTS,
            'valeur_json' => [$valeur],
            'justification' => 'Vecteur.',
        ]);

        $version->references()->create([
            'type' => 'document',
            'libelle' => 'Vecteur de test',
            'citation' => 'Aucune autorité — vecteur automatisé.',
        ]);

        $relecteur = $this->agentProtocole(...array_values(ServiceGouvernanceProtocole::PERMISSIONS_VALIDATION));

        foreach (array_keys(ServiceGouvernanceProtocole::PERMISSIONS_VALIDATION) as $type) {
            $gouvernance->valider($version, $relecteur, $type, 'favorable', 'Relecteur '.$type);
        }

        $gouvernance->publier(
            $version->refresh(),
            $this->agentProtocole(ServiceGouvernanceProtocole::PERMISSION_PUBLIER),
        );

        $this->app->forgetScopedInstances();
    }

    /** Publie un SECOND protocole d'antecedents, avec une autre borne. */
    private function publierSecondeBorne(int $valeur): void
    {
        $gouvernance = app(ServiceGouvernanceProtocole::class);

        $protocole = Protocole::forceCreate([
            'code' => 'TRIAGE-ANTECEDENTS-BIS',
            'pays_code' => 'CI',
            'titre' => 'Seconde borne (vecteur)',
            'organisme' => 'Vecteur automatise — aucune autorite reelle',
            'domaine' => Protocole::DOMAINE_TRIAGE,
            // Un rang DIFFERENT : a rang egal, P10b-2 refuse la publication d'une seconde
            // version que seule la date departagerait — la garde d'amont fait deja son travail.
            'niveau_source' => 'regional',
            'contextes_json' => [RegistreContextesProtocole::TRIAGE_ANTECEDENTS],
            'langue' => 'fr',
            'actif' => true,
        ]);

        $version = $gouvernance->ouvrirBrouillon(
            $protocole,
            $this->agentProtocole(ServiceGouvernanceProtocole::PERMISSION_REDIGER),
            '1.0',
            'Vecteur : borne concurrente',
            ['niveau_preuve' => 'D', 'population' => 'Tous', 'conditions_utilisation' => 'Vecteur.'],
        );

        $regle = $version->regles()->create(['ordre' => 1, 'libelle' => 'Borne concurrente']);
        $regle->actions()->create([
            'ordre' => 1,
            'type' => RegistreActionsProtocole::BORNER_SCORE_ANTECEDENTS,
            'valeur_json' => [$valeur],
            'justification' => 'Vecteur.',
        ]);

        $version->references()->create([
            'type' => 'document',
            'libelle' => 'Vecteur de test',
            'citation' => 'Aucune autorite — vecteur automatise.',
        ]);

        $relecteur = $this->agentProtocole(...array_values(ServiceGouvernanceProtocole::PERMISSIONS_VALIDATION));

        foreach (array_keys(ServiceGouvernanceProtocole::PERMISSIONS_VALIDATION) as $type) {
            $gouvernance->valider($version, $relecteur, $type, 'favorable', 'Relecteur '.$type);
        }

        $gouvernance->publier($version->refresh(), $this->agentProtocole(ServiceGouvernanceProtocole::PERMISSION_PUBLIER));
        $this->app->forgetScopedInstances();
    }

    private function symptomeSansDrapeau(): Symptome
    {
        return Symptome::query()->where('drapeau_rouge', false)->orderBy('poids_severite')->firstOrFail();
    }

    /** @param  array<int, int>  $impacts */
    private function membreAvecAntecedents(array $impacts): MembreFamille
    {
        $compte = User::factory()->create();

        $membre = MembreFamille::factory()->create([
            'user_id' => $compte->id,
        ]);

        foreach ($impacts as $i => $impact) {
            $membre->antecedents()->create([
                'type' => 'Antécédent '.($i + 1),
                'description' => 'Vecteur',
                'impact_triage' => $impact,
            ]);
        }

        return $membre->fresh();
    }
}
