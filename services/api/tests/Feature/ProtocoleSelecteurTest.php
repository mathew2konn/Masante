<?php

namespace Tests\Feature;

use App\Models\Protocole;
use App\Models\ProtocoleApplication;
use App\Models\ProtocoleConflit;
use App\Models\ProtocoleVersion;
use App\Models\Symptome;
use App\Services\Protocole\JournalApplicationProtocole;
use App\Services\Referentiel\SourceSymptomesTriage;
use App\Services\Protocole\ProtocoleException;
use App\Services\Protocole\SelecteurProtocoles;
use App\Services\Protocole\ServiceGouvernanceProtocole;
use App\Support\NiveauTriage;
use App\Support\RegistreActionsProtocole;
use App\Support\RegistreContextesProtocole;
use App\Support\ReglesResolutionConflit;
use Database\Seeders\ProtocoleSeeder;
use Database\Seeders\SpecialiteMedicaleSeeder;
use Database\Seeders\SymptomeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\GouverneUnReferentiel;
use Tests\Concerns\PublieLeProtocoleDeTriage;
use Tests\TestCase;

/**
 * P10b-2 — Sélecteur, ordre §3, conflits §8, journal d'exécution §10.
 *
 * Écrit dans les deux sens : chaque garde a un vecteur qui la voit refuser ET un vecteur qui la
 * voit laisser passer. Une garde qu'on n'a vue que refuser peut refuser tout le temps.
 */
class ProtocoleSelecteurTest extends TestCase
{
    use GouverneUnReferentiel;
    use PublieLeProtocoleDeTriage;
    use RefreshDatabase;

    private function selecteur(): SelecteurProtocoles
    {
        return app(SelecteurProtocoles::class);
    }

    /**
     * Publie un second protocole de triage, par un acte de gouvernance réel (quatre validations
     * §7 + quatre-yeux §10). Aucun raccourci par la base : ce que le test met en vigueur, c'est ce
     * que la production met en vigueur.
     *
     * @param  array<int, array{0: string, 1: mixed, 2: string|null}>  $actions
     */
    private function publierSecondProtocole(
        string $code,
        array $actions,
        string $source = 'regional',
        string $preuve = 'D',
        array $contextes = [RegistreContextesProtocole::TRIAGE],
        array $conditions = [['score', 'entre', [0, 100]]],
        ?array $bandeSupplementaire = null,
    ): ProtocoleVersion {
        // Le contrôle qualité de b-1 vérifie qu'un `ORIENTER` désigne un terme VIVANT du
        // vocabulaire national (P6.8a) : sans ce seeder, un protocole d'orientation serait
        // refusé pour une raison sans rapport avec ce qu'on teste.
        $this->seed(SpecialiteMedicaleSeeder::class);

        $gouvernance = app(ServiceGouvernanceProtocole::class);
        $auteur = $this->agentProtocole(ServiceGouvernanceProtocole::PERMISSION_REDIGER);

        $protocole = $gouvernance->creer($code, config('referentiels.pays_defaut', 'CI'), $auteur, [
            'titre'          => 'Protocole de test '.$code,
            'domaine'        => Protocole::DOMAINE_TRIAGE,
            'niveau_source'  => $source,
            'contextes_json' => $contextes,
            'organisme'      => 'Test',
        ]);

        $version = $gouvernance->ouvrirBrouillon($protocole, $auteur, '1.0', 'Vecteur de test', [
            'niveau_preuve' => $preuve,
            'population'    => 'Test',
        ]);

        $regle = $version->regles()->create(['ordre' => 1, 'libelle' => 'Règle de test']);

        foreach ($conditions as $i => [$fait, $operateur, $valeur]) {
            $regle->conditions()->create([
                'ordre'       => $i + 1,
                'fait'        => $fait,
                'operateur'   => $operateur,
                'valeur_json' => is_array($valeur) ? $valeur : [$valeur],
            ]);
        }

        foreach ($actions as $i => [$type, $valeur, $justification]) {
            $regle->actions()->create([
                'ordre'         => $i + 1,
                'type'          => $type,
                'valeur_json'   => is_array($valeur) ? $valeur : [$valeur],
                'justification' => $justification,
            ]);
        }

        // Une seconde bande, quand le vecteur a besoin de deux règles pour créer un TROU entre
        // elles — le cas que la borne haute seule ne sait pas produire.
        if ($bandeSupplementaire !== null) {
            $seconde = $version->regles()->create(['ordre' => 2, 'libelle' => 'Seconde bande']);
            $seconde->conditions()->create([
                'ordre' => 1, 'fait' => 'score', 'operateur' => 'entre',
                'valeur_json' => $bandeSupplementaire,
            ]);
            $seconde->actions()->create([
                'ordre' => 1, 'type' => RegistreActionsProtocole::DEFINIR_NIVEAU,
                'valeur_json' => [NiveauTriage::URGENCE],
            ]);
            $seconde->actions()->create([
                'ordre' => 2, 'type' => RegistreActionsProtocole::MESSAGE,
                'valeur_json' => ['Consigne de test'],
            ]);
        }

        $version->references()->create([
            'type'     => 'document',
            'libelle'  => 'Vecteur de test',
            'citation' => 'Contenu de test, aucune source réelle.',
        ]);

        $version->refresh();
        $relecteur = $this->agentProtocole(...array_values(ServiceGouvernanceProtocole::PERMISSIONS_VALIDATION));

        foreach (array_keys(ServiceGouvernanceProtocole::PERMISSIONS_VALIDATION) as $type) {
            $gouvernance->valider($version, $relecteur, $type, 'favorable', 'Relecteur '.$type);
        }

        $publie = $gouvernance->publier(
            $version->refresh(),
            $this->agentProtocole(ServiceGouvernanceProtocole::PERMISSION_PUBLIER),
        );

        $this->app->forgetScopedInstances();

        return $publie;
    }

    /**
     * Met en vigueur les DEUX gouvernances dont dépend `POST /triage/analyser` :
     * le référentiel des symptômes (P10a) et le protocole de niveau (b-1).
     *
     * Deux étapes de déploiement, deux traits : c'est exactement ce que le guide de test demande
     * à l'exploitant, et le harnais ne prend pas de raccourci que la production n'aurait pas.
     */
    private function mettreLeTriageEnService(): int
    {
        $numero = $this->publierProtocoleDeTriage();
        $this->seed(SymptomeSeeder::class);
        $this->publierReferentiel(SourceSymptomesTriage::CODE);
        $this->app->forgetScopedInstances();

        return $numero;
    }

    /** @return array<string, mixed> */
    private function faits(int $score = 40, ?int $age = null): array
    {
        return array_filter([
            'score'         => $score,
            'age'           => $age,
            'drapeau_rouge' => false,
            'nb_symptomes'  => 1,
        ], fn ($v) => $v !== null);
    }

    // ═════════════════════════════════════════════════════════════════════════════
    // 1. LA SÉLECTION
    // ═════════════════════════════════════════════════════════════════════════════

    public function test_un_protocole_sans_contexte_ne_peut_meme_pas_etre_publie(): void
    {
        $this->publierProtocoleDeTriage();

        // Le message de `ProtocoleException::qualite()` est générique par construction ; ce
        // sont les DÉTAILS qui nomment l'anomalie, et c'est eux que l'écran affiche.
        try {
            $this->publierSecondProtocole(
                'SANS-CONTEXTE',
                [[RegistreActionsProtocole::MESSAGE, 'Bonjour', null]],
                contextes: [],
            );
            $this->fail('Un protocole sans contexte déclaré ne doit pas pouvoir être publié.');
        } catch (ProtocoleException $e) {
            $this->assertMatchesRegularExpression('/contexte/i', implode(' | ', $e->details));
        }
    }

    public function test_un_UPDATE_direct_sur_les_contextes_reste_sans_effet(): void
    {
        $this->publierProtocoleDeTriage();

        // ═══ LA BASCULE DE L1+L2, APPLIQUÉE AU CHAMP D'APPLICATION ═══
        //
        // Élargir ou restreindre le champ d'application d'un protocole en vigueur par un simple
        // `UPDATE` reviendrait à changer qui reçoit quelle orientation, sans quatre-yeux et sans
        // relecture. Le sélecteur lit l'INSTANTANÉ publié : la colonne ne prend effet qu'à la
        // publication suivante.
        DB::table('protocoles')->where('code', 'TRIAGE-NIVEAU')
            ->update(['contextes_json' => json_encode([RegistreContextesProtocole::CONSULTATION])]);

        $this->app->forgetScopedInstances();

        $resultat = $this->selecteur()->evaluer(RegistreContextesProtocole::TRIAGE, $this->faits());

        // Toujours sélectionné : c'est la version publiée qui fait foi.
        $this->assertCount(1, $resultat['protocoles']);
        $this->assertSame('TRIAGE-NIVEAU', $resultat['protocoles'][0]['code']);
    }

    public function test_un_protocole_d_un_autre_contexte_n_est_pas_evalue(): void
    {
        $this->publierProtocoleDeTriage();

        $this->publierSecondProtocole(
            'AUTRE-CONTEXTE',
            [[RegistreActionsProtocole::MESSAGE, 'Consigne de consultation', null]],
            contextes: [RegistreContextesProtocole::CONSULTATION],
        );

        $resultat = $this->selecteur()->evaluer(RegistreContextesProtocole::TRIAGE, $this->faits());

        $codes = array_column($resultat['protocoles'], 'code');
        $this->assertNotContains('AUTRE-CONTEXTE', $codes);
        $this->assertContains('AUTRE-CONTEXTE', array_column($resultat['ecartes'], 'code'));
    }

    public function test_un_contexte_inconnu_est_refuse_sans_rien_evaluer(): void
    {
        $this->publierProtocoleDeTriage();

        $this->expectException(ProtocoleException::class);
        $this->expectExceptionMessageMatches('/Contexte d\'évaluation inconnu/');

        $this->selecteur()->evaluer('urgence_vitale_absolue', $this->faits());
    }

    public function test_un_protocole_desactive_apres_publication_cesse_d_etre_evalue(): void
    {
        $this->publierProtocoleDeTriage();

        $avant = $this->selecteur()->evaluer(RegistreContextesProtocole::TRIAGE, $this->faits());
        $this->assertCount(1, $avant['protocoles']);

        Protocole::query()->where('code', 'TRIAGE-NIVEAU')->update(['actif' => false]);

        $apres = $this->selecteur()->evaluer(RegistreContextesProtocole::TRIAGE, $this->faits());
        $this->assertSame([], $apres['protocoles']);
    }

    public function test_un_protocole_selectionne_qui_ne_declenche_rien_ne_contribue_pas(): void
    {
        $this->publierProtocoleDeTriage();

        // Une règle qui ne se déclenche jamais sur ces faits (score hors bande).
        $this->publierSecondProtocole(
            'MUET',
            [[RegistreActionsProtocole::MESSAGE, 'Jamais affiché', null]],
            conditions: [['score', 'entre', [95, 100]]],
        );

        $resultat = $this->selecteur()->evaluer(RegistreContextesProtocole::TRIAGE, $this->faits(40));

        $muet = collect($resultat['protocoles'])->firstWhere('code', 'MUET');

        // Il a bien été évalué — ce n'est pas une erreur qu'il n'ait rien produit.
        $this->assertNotNull($muet);
        $this->assertFalse($muet['a_contribue']);
        $this->assertSame([], $resultat['conflits']);
    }

    // ═════════════════════════════════════════════════════════════════════════════
    // 2. LA CASCADE §3 / §8 — un vecteur par critère atteignable
    // ═════════════════════════════════════════════════════════════════════════════

    public function test_le_national_l_emporte_et_la_divergence_est_consignee(): void
    {
        $this->publierProtocoleDeTriage();

        $this->publierSecondProtocole(
            'REGIONAL',
            [[RegistreActionsProtocole::DEFINIR_NIVEAU, NiveauTriage::URGENCE, null],
            [RegistreActionsProtocole::MESSAGE, 'Consigne de test', null]],
            source: 'regional',
        );

        $resultat = $this->selecteur()->evaluer(RegistreContextesProtocole::TRIAGE, $this->faits(40));

        // Le national impose son niveau (bande 26-50 → recommandee).
        $this->assertSame('TRIAGE-NIVEAU', $resultat['protocole_retenu']['code']);

        $this->assertCount(1, $resultat['conflits']);
        $conflit = $resultat['conflits'][0];
        $this->assertSame(RegistreActionsProtocole::DEFINIR_NIVEAU, $conflit['action_type']);
        $this->assertSame(ReglesResolutionConflit::CRITERE_RANG, $conflit['critere']);
        $this->assertSame('TRIAGE-NIVEAU', $conflit['protocole_retenu_code']);
        $this->assertSame('REGIONAL', $conflit['protocole_ecarte_code']);
        // Les DEUX valeurs sont conservées : le §8 exige de pouvoir présenter les deux
        // recommandations et leurs sources.
        $this->assertSame(NiveauTriage::RECOMMANDEE, $conflit['valeur_retenue']);
        $this->assertSame(NiveauTriage::URGENCE, $conflit['valeur_ecartee']);
    }

    public function test_un_protocole_ecarte_sur_une_action_garde_ses_autres_actions(): void
    {
        $this->publierProtocoleDeTriage();

        $this->publierSecondProtocole('REGIONAL', [
            [RegistreActionsProtocole::DEFINIR_NIVEAU, NiveauTriage::URGENCE, null],
            [RegistreActionsProtocole::MESSAGE, 'Consigne de test', null],
            [RegistreActionsProtocole::ORIENTER, 'pediatrie', null],
        ], source: 'regional');

        $resultat = $this->selecteur()->evaluer(RegistreContextesProtocole::TRIAGE, $this->faits(40));

        // Perdu sur l'exclusive…
        $this->assertSame('TRIAGE-NIVEAU', $resultat['protocole_retenu']['code']);

        // …conservé sur la cumulative. Le §3 est un ordre de DÉPARTAGE, pas d'exclusion : un
        // protocole écarté sur un point ne l'est pas sur les autres.
        $orientations = array_values(array_filter(
            $resultat['actions'],
            fn (array $a): bool => $a['type'] === RegistreActionsProtocole::ORIENTER,
        ));

        $this->assertNotEmpty($orientations);
        $this->assertSame('pediatrie', $orientations[0]['valeur']);
        $this->assertSame('REGIONAL', $orientations[0]['protocole']);
    }

    public function test_deux_protocoles_d_accord_ne_produisent_aucun_conflit(): void
    {
        $this->publierProtocoleDeTriage();

        // Le même niveau que la bande 26-50 du national : ce n'est pas une divergence.
        $this->publierSecondProtocole(
            'ACCORD',
            [[RegistreActionsProtocole::DEFINIR_NIVEAU, NiveauTriage::RECOMMANDEE, null],
            [RegistreActionsProtocole::MESSAGE, 'Consigne de test', null]],
            source: 'regional',
        );

        $resultat = $this->selecteur()->evaluer(RegistreContextesProtocole::TRIAGE, $this->faits(40));

        $this->assertSame([], $resultat['conflits']);
        $this->assertNotNull($resultat['protocole_retenu']);
    }

    public function test_deux_orientations_s_additionnent_sans_conflit(): void
    {
        $this->publierProtocoleDeTriage();

        $this->publierSecondProtocole('ORIENTE-A', [
            [RegistreActionsProtocole::ORIENTER, 'pediatrie', null],
        ], source: 'regional');

        $this->publierSecondProtocole('ORIENTE-B', [
            [RegistreActionsProtocole::ORIENTER, 'cardiologie', null],
        ], source: 'oms');

        $resultat = $this->selecteur()->evaluer(RegistreContextesProtocole::TRIAGE, $this->faits(40));

        $valeurs = array_column(array_values(array_filter(
            $resultat['actions'],
            fn (array $a): bool => $a['type'] === RegistreActionsProtocole::ORIENTER,
        )), 'valeur');

        $this->assertContains('pediatrie', $valeurs);
        $this->assertContains('cardiologie', $valeurs);
        $this->assertSame([], $resultat['conflits']);
    }

    // ═════════════════════════════════════════════════════════════════════════════
    // 3. L'INTERDICTION DE PUBLICATION (§3.6 du plan)
    // ═════════════════════════════════════════════════════════════════════════════

    public function test_une_version_que_seule_la_date_departagerait_est_refusee(): void
    {
        $this->publierProtocoleDeTriage();

        try {
            $this->publierSecondProtocole(
                'CONCURRENT-NATIONAL',
                [[RegistreActionsProtocole::DEFINIR_NIVEAU, NiveauTriage::URGENCE, null],
                    [RegistreActionsProtocole::MESSAGE, 'Consigne de test', null]],
                source: 'national',
                preuve: 'D',
            );
            $this->fail('Une version que seule la date départagerait ne doit pas être publiable.');
        } catch (ProtocoleException $e) {
            $details = implode(' | ', $e->details);

            // Le refus NOMME le concurrent : sans cela, l'agent devrait deviner lequel des
            // protocoles en vigueur pose problème, et le message serait inexploitable.
            $this->assertStringContainsString('TRIAGE-NIVEAU', $details);
            $this->assertStringContainsString('date de publication', $details);
        }
    }

    public function test_un_rang_different_rend_la_publication_possible(): void
    {
        $this->publierProtocoleDeTriage();

        $version = $this->publierSecondProtocole(
            'REGIONAL-OK',
            [[RegistreActionsProtocole::DEFINIR_NIVEAU, NiveauTriage::URGENCE, null],
            [RegistreActionsProtocole::MESSAGE, 'Consigne de test', null]],
            source: 'regional',
            preuve: 'D',
        );

        $this->assertSame(ProtocoleVersion::ACTIF, $version->etat);
    }

    public function test_un_meilleur_niveau_de_preuve_ne_sauve_PAS_la_publication(): void
    {
        $this->publierProtocoleDeTriage();

        // ═══ CE VECTEUR DISAIT L'INVERSE, ET IL AVAIT TORT ═══
        //
        // Il affirmait qu'un niveau de preuve supérieur rendait la publication possible. C'est
        // faux : le §8 place la récence AVANT le niveau de preuve, et la version qu'on publie est
        // par construction la plus récente — elle gagnerait par la date avant qu'on ne regarde ses
        // preuves.
        //
        // Il PASSAIT pourtant, en run filtré : les deux publications tombaient dans la même
        // seconde, la cascade voyait une égalité de dates et descendait jusqu'au niveau de preuve.
        // La suite complète, plus lente, a révélé le défaut. Il est réécrit pour dire la garantie
        // JUSTE, pas corrigé pour passer (précédent P6.4d).
        try {
            $this->publierSecondProtocole(
                'MIEUX-PROUVE',
                [[RegistreActionsProtocole::DEFINIR_NIVEAU, NiveauTriage::URGENCE, null],
                    [RegistreActionsProtocole::MESSAGE, 'Consigne de test', null]],
                source: 'national',
                preuve: 'A',
            );
            $this->fail('Un même rang ne doit pas être publiable, quel que soit le niveau de preuve.');
        } catch (ProtocoleException $e) {
            $details = implode(' | ', $e->details);

            $this->assertStringContainsString('TRIAGE-NIVEAU', $details);
            // Le refus explique POURQUOI la preuve ne suffit pas : sans cela, l'agent
            // relèverait son niveau de preuve et se heurterait au même mur sans comprendre.
            $this->assertStringContainsString('récence', $details);
        }
    }

    public function test_une_action_cumulative_seule_ne_declenche_aucun_refus(): void
    {
        $this->publierProtocoleDeTriage();

        // Même rang, même niveau de preuve, même contexte — mais aucune action exclusive : les
        // deux protocoles ne se disputent rien.
        $version = $this->publierSecondProtocole(
            'CUMULATIF',
            [[RegistreActionsProtocole::ORIENTER, 'pediatrie', null]],
            source: 'national',
            preuve: 'D',
        );

        $this->assertSame(ProtocoleVersion::ACTIF, $version->etat);
    }

    public function test_un_contexte_disjoint_rend_la_publication_possible(): void
    {
        $this->publierProtocoleDeTriage();

        $version = $this->publierSecondProtocole(
            'CONSULT-SEUL',
            [[RegistreActionsProtocole::DEFINIR_NIVEAU, NiveauTriage::URGENCE, null],
            [RegistreActionsProtocole::MESSAGE, 'Consigne de test', null]],
            source: 'national',
            preuve: 'D',
            contextes: [RegistreContextesProtocole::CONSULTATION],
        );

        $this->assertSame(ProtocoleVersion::ACTIF, $version->etat);
    }

    public function test_un_trou_de_couverture_est_refuse_a_la_publication(): void
    {
        $this->publierProtocoleDeTriage();

        // Un protocole COMPLET dont les bandes laissent 26-50 à découvert. Le national couvre
        // pourtant cet intervalle : le contrôle porte sur l'ENSEMBLE, il ne devrait donc pas
        // refuser… sauf que ce protocole-ci recouvre le national partout ailleurs, ce qui n'est
        // pas une erreur. On isole donc le vrai cas : le seul protocole en vigueur laisse un trou.
        Protocole::query()->where('code', 'TRIAGE-NIVEAU')->update(['actif' => false]);

        try {
            $this->publierSecondProtocole(
                'TROUE',
                [[RegistreActionsProtocole::DEFINIR_NIVEAU, NiveauTriage::FAIBLE, null],
                    [RegistreActionsProtocole::MESSAGE, 'Consigne de test', null]],
                source: 'national',
                conditions: [['score', 'entre', [0, 25]]],
            );
            $this->fail('Un trou de couverture doit être refusé : personne ne le verrait autrement.');
        } catch (ProtocoleException $e) {
            $details = implode(' | ', $e->details);

            // Le refus NOMME l'intervalle : « couverture incomplète » obligerait à chercher où.
            $this->assertStringContainsString('26', $details);
        }
    }

    public function test_une_surcouche_qui_ne_couvre_qu_un_cas_reste_publiable(): void
    {
        $this->publierProtocoleDeTriage();

        // ═══ LE DÉFAUT DE b-1 QUE b-2 CORRIGE ═══
        //
        // b-1 vérifiait la couverture protocole par protocole : cette surcouche, qui ne traite
        // qu'un cas particulier, aurait été refusée parce qu'elle « ne couvre pas 0-100 » — alors
        // que c'est le national qui couvre, et que les faire cohabiter est l'objet du §3.
        $version = $this->publierSecondProtocole(
            'SURCOUCHE',
            [[RegistreActionsProtocole::DEFINIR_NIVEAU, NiveauTriage::URGENCE, null],
                [RegistreActionsProtocole::MESSAGE, 'Consigne de test', null]],
            source: 'regional',
            conditions: [['age', '<', 5], ['score', 'entre', [26, 50]]],
        );

        $this->assertSame(ProtocoleVersion::ACTIF, $version->etat);
    }

    // ═════════════════════════════════════════════════════════════════════════════
    // 4. LE JOURNAL D'EXÉCUTION (§10)
    // ═════════════════════════════════════════════════════════════════════════════

    public function test_un_triage_ecrit_exactement_une_entree_au_journal(): void
    {
        $this->mettreLeTriageEnService();
        $symptome = Symptome::query()->where('actif', true)->firstOrFail();

        $this->postJson('/api/v1/triage/analyser', [
            'symptomes'   => [$symptome->id],
            'patient_age' => 30,
        ])->assertStatus(201);

        $this->assertSame(1, ProtocoleApplication::query()->count());

        $entree = ProtocoleApplication::query()->firstOrFail();
        $this->assertSame(RegistreContextesProtocole::TRIAGE, $entree->contexte);
        $this->assertSame('TRIAGE-NIVEAU', $entree->protocole_retenu_code);
        $this->assertNotNull($entree->triage_id);
        $this->assertNotEmpty($entree->recommandations_json);

        // La version EXACTE de chaque protocole évalué (§6.1, §10).
        $this->assertNotEmpty($entree->protocoles_json);
        $this->assertArrayHasKey('numero', $entree->protocoles_json[0]);
    }

    public function test_la_decision_finale_reste_nulle_sur_un_triage_citoyen(): void
    {
        $this->mettreLeTriageEnService();
        $symptome = Symptome::query()->where('actif', true)->firstOrFail();

        $this->postJson('/api/v1/triage/analyser', ['symptomes' => [$symptome->id]])->assertStatus(201);

        $entree = ProtocoleApplication::query()->firstOrFail();

        // §10 les nomme ; le triage citoyen n'a personne pour décider. L'absence est structurelle
        // et doit rester visible plutôt que d'être remplie par une valeur inventée.
        $this->assertNull($entree->decision_finale);
        $this->assertNull($entree->ecart_justification);
        $this->assertNull($entree->professionnel_id);
    }

    public function test_le_journal_est_append_only_au_niveau_du_modele(): void
    {
        $this->mettreLeTriageEnService();
        $symptome = Symptome::query()->where('actif', true)->firstOrFail();
        $this->postJson('/api/v1/triage/analyser', ['symptomes' => [$symptome->id]])->assertStatus(201);

        $entree = ProtocoleApplication::query()->firstOrFail();

        // ═══ CE VECTEUR SE LAISSAIT SATISFAIRE PAR LA MAUVAISE GARDE ═══
        //
        // `QueryException` hérite de `RuntimeException` : attendre `RuntimeException` laissait le
        // DÉCLENCHEUR de base satisfaire un vecteur censé prouver la garde ELOQUENT. La mutation
        // l'a montré — neutraliser la garde du modèle ne le faisait pas échouer.
        try {
            $entree->update(['decision_finale' => 'ajoutée après coup']);
            $this->fail('Le journal d\'exécution ne doit pas être modifiable par Eloquent.');
        } catch (\RuntimeException $e) {
            $this->assertNotInstanceOf(\Illuminate\Database\QueryException::class, $e);
            $this->assertStringContainsString('append-only', $e->getMessage());
        }

    }

    public function test_le_journal_est_append_only_au_niveau_du_moteur(): void
    {
        $this->mettreLeTriageEnService();
        $symptome = Symptome::query()->where('actif', true)->firstOrFail();
        $this->postJson('/api/v1/triage/analyser', ['symptomes' => [$symptome->id]])->assertStatus(201);

        // En SQL DIRECT, sans passer par Eloquent : la garde applicative serait muette ici, le
        // déclencheur ne l'est pas.
        $this->expectException(\Illuminate\Database\QueryException::class);
        DB::table('protocole_applications')->where('id', 1)->update(['decision_finale' => 'forcée']);
    }

    public function test_la_chaine_du_journal_detecte_une_alteration(): void
    {
        $this->mettreLeTriageEnService();
        $symptome = Symptome::query()->where('actif', true)->firstOrFail();

        foreach ([1, 2] as $ignore) {
            $this->postJson('/api/v1/triage/analyser', ['symptomes' => [$symptome->id]])->assertStatus(201);
        }

        $journal = app(JournalApplicationProtocole::class);

        $intacte = $journal->verifierChaine();
        $this->assertTrue($intacte['intacte']);
        $this->assertSame(2, $intacte['entrees']);

        // Altération par le seul chemin qui reste : désactiver le déclencheur n'est pas nécessaire
        // en SQLite pour un UPDATE fait avant sa création — on modifie donc la colonne d'empreinte
        // elle-même par une écriture brute, ce que seul un accès direct à la base permettrait.
        DB::statement('DROP TRIGGER IF EXISTS ck_applications_append_only_update');
        DB::table('protocole_applications')->where('id', 1)->update(['contexte' => 'consultation']);

        $rompue = $journal->verifierChaine();
        $this->assertFalse($rompue['intacte']);
        $this->assertSame('CONTENU', $rompue['rupture']['type']);
    }

    public function test_le_journal_ne_porte_ni_nom_ni_symptome_en_clair(): void
    {
        $this->mettreLeTriageEnService();
        $symptome = Symptome::query()->where('actif', true)->firstOrFail();

        $this->postJson('/api/v1/triage/analyser', [
            'symptomes'   => [$symptome->id],
            'patient_nom' => 'Konan Yao Rarissime',
            'patient_age' => 30,
        ])->assertStatus(201);

        $brut = json_encode(DB::table('protocole_applications')->get(), JSON_UNESCAPED_UNICODE);

        // Le §10 exige les recommandations, pas l'identité en clair : le patient est désigné par
        // ses identifiants, jamais par son nom.
        $this->assertStringNotContainsString('Konan Yao Rarissime', $brut);
        $this->assertStringNotContainsString($symptome->nom_fr, $brut);
    }

    public function test_les_divergences_sont_rattachees_a_leur_evaluation(): void
    {
        $this->mettreLeTriageEnService();
        $this->publierSecondProtocole(
            'REGIONAL',
            [[RegistreActionsProtocole::DEFINIR_NIVEAU, NiveauTriage::URGENCE, null],
            [RegistreActionsProtocole::MESSAGE, 'Consigne de test', null]],
            source: 'regional',
        );

        $symptome = Symptome::query()->where('actif', true)->firstOrFail();
        $this->postJson('/api/v1/triage/analyser', ['symptomes' => [$symptome->id]])->assertStatus(201);

        $entree = ProtocoleApplication::query()->firstOrFail();
        $conflits = ProtocoleConflit::query()->where('application_id', $entree->id)->get();

        $this->assertCount(1, $conflits);
        $this->assertSame('REGIONAL', $conflits[0]->protocole_ecarte_code);
        $this->assertSame(ReglesResolutionConflit::CRITERE_RANG, $conflits[0]->critere);
    }

    // ═════════════════════════════════════════════════════════════════════════════
    // 5. NON-RÉGRESSION SUR b-1 ET P10a
    // ═════════════════════════════════════════════════════════════════════════════

    public function test_avec_un_seul_protocole_le_selecteur_ne_change_rien(): void
    {
        $numero = $this->mettreLeTriageEnService();
        $symptome = Symptome::query()->where('actif', true)->firstOrFail();

        $reponse = $this->postJson('/api/v1/triage/analyser', [
            'symptomes'   => [$symptome->id],
            'patient_age' => 30,
        ])->assertStatus(201);

        // L'estampille désigne le protocole qui a emporté le niveau, comme en b-1.
        $reponse->assertJsonPath('protocole.code', 'TRIAGE-NIVEAU');
        $reponse->assertJsonPath('protocole.numero', $numero);
        $this->assertContains($reponse->json('niveau'), NiveauTriage::PATIENT);
    }

    public function test_sans_protocole_en_vigueur_le_triage_refuse_toujours_bruyamment(): void
    {
        // Le référentiel des symptômes EST en vigueur : le 503 attendu ne peut donc venir
        // que du protocole, dont seul un brouillon existe. Sans cette précaution, ce
        // vecteur prouverait le refus bruyant de P10a et non celui de b-1.
        $this->seed(SymptomeSeeder::class);
        $this->publierReferentiel(SourceSymptomesTriage::CODE);
        $this->seed(ProtocoleSeeder::class);
        $symptome = Symptome::query()->where('actif', true)->firstOrFail();

        // Le protocole existe en brouillon : c'est sa MISE EN VIGUEUR qui manque.
        $this->postJson('/api/v1/triage/analyser', ['symptomes' => [$symptome->id]])
            ->assertStatus(503);

        // Et rien n'a été journalisé : aucune recommandation n'a été rendue.
        $this->assertSame(0, ProtocoleApplication::query()->count());
    }

    public function test_l_estampille_designe_le_protocole_qui_a_emporte_le_niveau(): void
    {
        $this->mettreLeTriageEnService();
        $this->publierSecondProtocole(
            'REGIONAL',
            [[RegistreActionsProtocole::DEFINIR_NIVEAU, NiveauTriage::URGENCE, null],
            [RegistreActionsProtocole::MESSAGE, 'Consigne de test', null]],
            source: 'regional',
        );

        $symptome = Symptome::query()->where('actif', true)->firstOrFail();
        $this->postJson('/api/v1/triage/analyser', ['symptomes' => [$symptome->id]])->assertStatus(201);

        $triage = \App\Models\Triage::query()->firstOrFail();

        // Le régional a été évalué et a perdu : c'est le national qui estampille.
        $this->assertSame('TRIAGE-NIVEAU', $triage->protocole_code);
    }

    // ═════════════════════════════════════════════════════════════════════════════
    // 6. LA PORTE `POST /protocoles/evaluer` (§9.1)
    // ═════════════════════════════════════════════════════════════════════════════

    public function test_l_evaluation_exige_l_habilitation(): void
    {
        $this->publierProtocoleDeTriage();

        $this->actingAs($this->agentProtocole(ServiceGouvernanceProtocole::PERMISSION_REDIGER))
            ->postJson('/api/v1/protocoles/evaluer', [
                'contexte' => RegistreContextesProtocole::TRIAGE,
                'faits'    => ['score' => 40],
            ])
            ->assertStatus(403);
    }

    public function test_l_evaluation_rend_le_contrat_du_paragraphe_9_1(): void
    {
        $this->publierProtocoleDeTriage();

        $reponse = $this->actingAs($this->agentProtocole(ServiceGouvernanceProtocole::PERMISSION_EVALUER))
            ->postJson('/api/v1/protocoles/evaluer', [
                'contexte' => RegistreContextesProtocole::TRIAGE,
                'faits'    => ['score' => 40, 'drapeau_rouge' => false],
            ])
            ->assertStatus(201);

        $reponse->assertJsonStructure([
            'recommandations', 'conflits', 'trace_id', 'questions_suivantes',
            'protocole_retenu', 'protocoles_evalues', 'protocoles_ecartes',
        ]);

        $this->assertSame(1, ProtocoleApplication::query()->count());
        $this->assertSame(
            $reponse->json('trace_id'),
            ProtocoleApplication::query()->value('trace_id'),
        );
    }

    public function test_un_professionnel_peut_consigner_sa_decision_dans_le_meme_geste(): void
    {
        $this->publierProtocoleDeTriage();

        $agent = $this->agentProtocole(ServiceGouvernanceProtocole::PERMISSION_EVALUER);

        $this->actingAs($agent)->postJson('/api/v1/protocoles/evaluer', [
            'contexte'            => RegistreContextesProtocole::TRIAGE,
            'faits'               => ['score' => 40],
            'decision_finale'     => 'Consultation programmée à J+2',
            'ecart_justification' => 'Le patient a un rendez-vous déjà fixé.',
        ])->assertStatus(201);

        $entree = ProtocoleApplication::query()->firstOrFail();

        $this->assertSame('Consultation programmée à J+2', $entree->decision_finale);
        $this->assertSame($agent->id, $entree->professionnel_id);
    }

    public function test_le_journal_d_execution_a_sa_propre_verification_d_integrite(): void
    {
        $this->publierProtocoleDeTriage();

        $this->actingAs($this->agentProtocole(ServiceGouvernanceProtocole::PERMISSION_EVALUER))
            ->getJson('/api/v1/protocoles/applications/integrite')
            ->assertStatus(200)
            ->assertJsonPath('intacte', true);
    }

    public function test_la_route_litterale_integrite_n_est_pas_prise_pour_un_identifiant_de_trace(): void
    {
        $this->publierProtocoleDeTriage();

        // Le piège d'ordre de déclaration : sans lui, « integrite » serait lu comme un `trace_id`
        // et la route répondrait 404 — un défaut qui ne casse rien et ne se voit pas.
        $this->actingAs($this->agentProtocole(ServiceGouvernanceProtocole::PERMISSION_EVALUER))
            ->getJson('/api/v1/protocoles/applications/integrite')
            ->assertStatus(200)
            ->assertJsonMissingPath('trace_id');
    }
}
