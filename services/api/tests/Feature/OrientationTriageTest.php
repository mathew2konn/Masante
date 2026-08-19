<?php

namespace Tests\Feature;

use App\Models\MembreFamille;
use App\Models\ServiceEtablissement;
use App\Models\SpecialiteMedicale;
use App\Models\StructureSanitaire;
use App\Models\Symptome;
use App\Models\SymptomeSpecialite;
use App\Models\Triage;
use App\Models\User;
use App\Services\Referentiel\ServiceGouvernanceReferentiel;
use App\Services\Referentiel\SourceSymptomesTriage;
use App\Services\Triage\ServiceSymptomesTriage;
use App\Support\NiveauTriage;
use App\Support\ReglesOrientation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\GouverneUnReferentiel;
use Tests\Concerns\PublieLeProtocoleDeTriage;
use Tests\TestCase;

/**
 * P10a — Orientation après triage + gouvernance du triage (CDC_05 §5, CDC_09 §10).
 *
 * ÉCRITE DANS LES DEUX SENS. Chaque garde a son vecteur qui passe ET son vecteur qui refuse ; et
 * chaque garde doit MOURIR seule sous mutation. Les gardes visées :
 *
 *   G1  la bascule sur la version publiée (refus bruyant avant la v1)
 *   G2  la validation lit la version publiée, pas la table (constat C1 de L1+L2)
 *   G3  le rang porte la priorité, plus un `str_contains`
 *   G4  `sexe_requis` porte la restriction, et un sexe INCONNU n'écarte rien
 *   G5  le repli pédiatrique ne s'applique QUE si rien n'a été retenu
 *   G6  le contrôle qualité refuse une orientation vers un terme désactivé
 *   G7  la fiche n'est lisible que par son propriétaire ou par le porteur du jeton
 *   G8  l'historique ne renvoie que les triages du compte authentifié
 */
class OrientationTriageTest extends TestCase
{
    use GouverneUnReferentiel;
    use PublieLeProtocoleDeTriage;
    use RefreshDatabase;

    // ═════════════════════════════════════════════════════════════════════════════════════════
    // La classe de règles — pure, sans base, sans horloge
    // ═════════════════════════════════════════════════════════════════════════════════════════

    public function test_les_orientations_sont_ordonnees_par_rang_puis_par_code(): void
    {
        $codes = ReglesOrientation::agreger([
            ['code' => 'urgences', 'rang' => 2, 'sexe_requis' => null],
            ['code' => 'cardiologie', 'rang' => 1, 'sexe_requis' => null],
        ]);

        $this->assertSame(['cardiologie', 'urgences'], $codes);
    }

    public function test_un_meme_code_venu_de_deux_symptomes_garde_son_meilleur_rang(): void
    {
        // Deux symptômes orientent vers les urgences, l'un au rang 1, l'autre au rang 2. Les
        // reléguer au pire rang ferait passer une urgence derrière une spécialité de confort.
        $codes = ReglesOrientation::agreger([
            ['code' => 'urgences', 'rang' => 2, 'sexe_requis' => null],
            ['code' => 'dentisterie', 'rang' => 1, 'sexe_requis' => null],
            ['code' => 'urgences', 'rang' => 1, 'sexe_requis' => null],
        ]);

        $this->assertSame(['dentisterie', 'urgences'], $codes);
    }

    public function test_deux_orientations_de_meme_rang_sortent_dans_un_ordre_total(): void
    {
        // Sans second critère, l'ordre dépendrait du moteur et la même entrée répondrait
        // différemment d'une base à l'autre (leçon `NumeroUrgence::scopeOrdonne`, P6.8e).
        $premier = ReglesOrientation::agreger([
            ['code' => 'orl', 'rang' => 1, 'sexe_requis' => null],
            ['code' => 'cardiologie', 'rang' => 1, 'sexe_requis' => null],
        ]);

        $second = ReglesOrientation::agreger([
            ['code' => 'cardiologie', 'rang' => 1, 'sexe_requis' => null],
            ['code' => 'orl', 'rang' => 1, 'sexe_requis' => null],
        ]);

        $this->assertSame(['cardiologie', 'orl'], $premier);
        $this->assertSame($premier, $second);
    }

    public function test_une_orientation_restreinte_est_ecartee_quand_on_sait_qu_elle_ne_s_applique_pas(): void
    {
        $codes = ReglesOrientation::agreger(
            [['code' => 'gynecologie', 'rang' => 1, 'sexe_requis' => 'F']],
            sexe: 'M',
        );

        $this->assertSame([], $codes);
    }

    public function test_un_sexe_inconnu_n_ecarte_aucune_orientation(): void
    {
        // GARDE G4, dans l'autre sens. Un triage anonyme ne renseigne pas toujours le sexe :
        // retirer une orientation faute d'information reviendrait à décider à la place du patient.
        // On n'agit que sur ce qu'on SAIT — motif des trois silences de P7-D2.
        $codes = ReglesOrientation::agreger(
            [['code' => 'gynecologie', 'rang' => 1, 'sexe_requis' => 'F']],
            sexe: null,
        );

        $this->assertSame(['gynecologie'], $codes);
    }

    public function test_le_repli_pediatrique_s_applique_quand_rien_n_est_retenu(): void
    {
        $codes = ReglesOrientation::agreger([], sexe: null, age: 6, codePediatrie: 'pediatrie');

        $this->assertSame(['pediatrie'], $codes);
    }

    public function test_un_enfant_avec_une_orientation_ne_bascule_PAS_en_pediatrie(): void
    {
        // LA QUESTION EXACTE DU PROPRIÉTAIRE : « si les symptômes prouvent que le patient a mal à
        // la dent, il doit être orienté au dentiste ». Un enfant qui a mal aux dents va chez le
        // dentiste, pas en pédiatrie parce qu'il est enfant.
        $codes = ReglesOrientation::agreger(
            [['code' => 'dentisterie', 'rang' => 1, 'sexe_requis' => null]],
            sexe: null,
            age: 6,
            codePediatrie: 'pediatrie',
        );

        $this->assertSame(['dentisterie'], $codes);
    }

    public function test_sans_code_pediatrique_fourni_rien_n_est_invente(): void
    {
        // Le code est FOURNI, jamais deviné : s'il a disparu du vocabulaire national, on ne renvoie
        // rien plutôt que d'orienter vers une spécialité éteinte (précédent P6.4a).
        $codes = ReglesOrientation::agreger([], sexe: null, age: 6, codePediatrie: null);

        $this->assertSame([], $codes);
    }

    public function test_les_regles_ne_rendent_aucun_verdict_medical(): void
    {
        // Elles ORDONNENT DES CODES. Aucune maladie, aucun diagnostic, aucun niveau de gravité —
        // CDC_05 §1 : « le triage n'est jamais un diagnostic ». Vecteur miroir de celui de P6.7a
        // qui échoue si une clé de verdict apparaît.
        $codes = ReglesOrientation::agreger(
            [['code' => 'cardiologie', 'rang' => 1, 'sexe_requis' => null]],
            sexe: 'F',
            age: 40,
        );

        foreach ($codes as $valeur) {
            $this->assertIsString($valeur);
        }

        $this->assertSame(['cardiologie'], $codes);
    }

    // ═════════════════════════════════════════════════════════════════════════════════════════
    // La bascule sur la version publiée — G1 et G2
    // ═════════════════════════════════════════════════════════════════════════════════════════

    public function test_sans_version_publiee_la_liste_des_symptomes_refuse_bruyamment(): void
    {
        $this->symptome('Douleur dentaire');

        $this->getJson('/api/v1/symptomes')
            ->assertStatus(503)
            ->assertJsonFragment(['message' => $this->messageAbsenceVersion()]);
    }

    public function test_sans_version_publiee_l_analyse_refuse_bruyamment(): void
    {
        // Jamais un repli sur la table : un repli laisserait un oubli de publication passer
        // inaperçu, et la garantie serait inactive sans que personne ne le sache (décision L1+L2).
        $symptome = $this->symptome('Douleur dentaire');

        $this->postJson('/api/v1/triage/analyser', ['symptomes' => [$symptome->id]])
            ->assertStatus(503);
    }

    public function test_un_update_direct_reste_sans_effet_sur_la_version_diffusee(): void
    {
        $symptome = $this->symptome('Douleur dentaire', poids: 20);
        $this->publier();

        // Le geste que la gouvernance doit neutraliser : corriger un poids par un `UPDATE` direct.
        DB::table('symptomes')->where('id', $symptome->id)->update(['poids_severite' => 95]);
        $this->simulerNouvelleRequete();

        $reponse = $this->postJson('/api/v1/triage/analyser', ['symptomes' => [$symptome->id]])
            ->assertCreated();

        // 20 et non 95 : le triage lit la version publiée. C'est LE vecteur central de la bascule.
        $this->assertSame(20, $reponse->json('score_severite'));

        // ═══ P10b-1 — CETTE ASSERTION DISAIT `'leger'`, ET ELLE A ÉTÉ RÉÉCRITE ═══
        //
        // Pas corrigée pour passer : réécrite pour énoncer la garantie NEUVE. Le vocabulaire de
        // niveau est passé aux quatre valeurs de CDC_05 §5.3, et la bande est décidée par un
        // protocole publié — un score de 20 tombe dans « faible ». Écrire la nouvelle valeur en
        // dur ici referait, dans le test, le défaut que l'incrément vient de retirer du code :
        // on demande donc au vocabulaire ce qu'il porte.
        //
        // Précédent explicite : le vecteur hérité réécrit en P6.4d, et celui de P6.8b.
        $this->assertSame(NiveauTriage::FAIBLE, $reponse->json('niveau'));
        $this->assertFalse(NiveauTriage::estHerite($reponse->json('niveau')));
    }

    public function test_une_republication_met_la_correction_en_vigueur(): void
    {
        // L'autre sens : la gouvernance ne bloque pas, elle exige un acte relu par deux agents.
        $symptome = $this->symptome('Douleur dentaire', poids: 20);
        $this->publier();

        DB::table('symptomes')->where('id', $symptome->id)->update(['poids_severite' => 95]);
        $this->republierReferentiel(SourceSymptomesTriage::CODE, 'Correction clinique du poids.');

        $reponse = $this->postJson('/api/v1/triage/analyser', ['symptomes' => [$symptome->id]])
            ->assertCreated();

        $this->assertSame(95, $reponse->json('score_severite'));
    }

    public function test_un_symptome_absent_de_la_version_publiee_est_refuse_et_non_ignore(): void
    {
        // GARDE G2 — le constat C1 de L1+L2. Accepter puis ignorer en silence serait le pire des
        // deux : le citoyen coche un symptôme, son score n'en tient pas compte, rien ne le dit.
        $connu = $this->symptome('Douleur dentaire');
        $this->publier();

        $apres = $this->symptome('Symptôme ajouté après la publication');
        $this->simulerNouvelleRequete();

        $this->postJson('/api/v1/triage/analyser', ['symptomes' => [$connu->id, $apres->id]])
            // L'erreur porte sur le SECOND élément : le premier est bien de la version publiée.
            ->assertStatus(422)
            ->assertJsonValidationErrors('symptomes.1');
    }

    public function test_le_triage_est_estampille_de_la_version_qui_l_a_gouverne(): void
    {
        $symptome = $this->symptome('Douleur dentaire');
        $this->publier();

        $reponse = $this->postJson('/api/v1/triage/analyser', ['symptomes' => [$symptome->id]])
            ->assertCreated()
            ->assertJsonPath('referentiel_version', 1);

        $this->assertSame(1, Triage::find($reponse->json('triage_id'))->referentiel_version);
    }

    public function test_le_client_ne_choisit_pas_la_version_estampillee(): void
    {
        $symptome = $this->symptome('Douleur dentaire');
        $this->publier();

        $reponse = $this->postJson('/api/v1/triage/analyser', [
            'symptomes'           => [$symptome->id],
            'referentiel_version' => 999,
            'specialites_json'    => [['code' => 'cardiologie', 'libelle' => 'Inventé']],
        ])->assertCreated();

        $triage = Triage::find($reponse->json('triage_id'));

        $this->assertSame(1, $triage->referentiel_version);
        $this->assertNotSame('Inventé', $triage->specialites_json[0]['libelle'] ?? null);
    }

    public function test_la_liste_des_symptomes_ne_sert_plus_l_indice_de_specialite(): void
    {
        // `specialite_hint` ne gouverne plus l'orientation depuis P10a : la servir encore
        // publierait une orientation périmée à côté de la vraie.
        $this->symptome('Douleur dentaire');
        $this->publier();

        $reponse = $this->getJson('/api/v1/symptomes')->assertOk();

        $this->assertArrayNotHasKey('specialite_hint', $reponse->json('symptomes.0'));
        $this->assertSame(1, $reponse->json('referentiel_version'));
    }

    public function test_l_instantane_ne_publie_plus_les_maladies_probables(): void
    {
        // Décision D5. Ces libellés n'ont AUCUN lecteur, et leur seule sortie du serveur était
        // l'instantané — c'est-à-dire l'endroit qui leur donnait le plus d'autorité.
        $symptome = $this->symptome('Fièvre');
        $symptome->update(['maladies_probables_json' => ['Paludisme', 'Grossesse']]);

        $contenu = app(SourceSymptomesTriage::class)->extraire();

        $this->assertArrayNotHasKey('maladies_probables_json', $contenu[0]);
        $this->assertArrayNotHasKey('specialite_hint', $contenu[0]);
        $this->assertArrayHasKey('orientations', $contenu[0]);
    }

    // ═════════════════════════════════════════════════════════════════════════════════════════
    // L'orientation de bout en bout — G3, G4, G5
    // ═════════════════════════════════════════════════════════════════════════════════════════

    public function test_le_rang_decide_de_la_priorite_et_non_l_orthographe_du_libelle(): void
    {
        // GARDE G3. Avant P10a, la priorité venait d'un `str_contains($h, 'urgenc')` : écrire
        // « Urgence » au singulier l'aurait supprimée sans que rien ne le signale.
        $symptome = $this->symptome('Douleur thoracique');
        $this->orienter($symptome, 'cardiologie', rang: 2);
        $this->orienter($symptome, 'urgences', rang: 1);
        $this->publier();

        $reponse = $this->postJson('/api/v1/triage/analyser', ['symptomes' => [$symptome->id]])
            ->assertCreated();

        $this->assertSame(
            ['urgences', 'cardiologie'],
            array_column($reponse->json('specialites'), 'code'),
        );

        // Le libellé affiché est celui de la PREMIÈRE orientation (contrat hérité).
        $this->assertSame("Médecine d'urgence", $reponse->json('specialite_requise'));
    }

    public function test_une_orientation_restreinte_disparait_pour_un_patient_qui_ne_la_concerne_pas(): void
    {
        $symptome = $this->symptome('Douleurs pelviennes');
        $this->orienter($symptome, 'gynecologie', rang: 1, sexe: 'F');
        $this->publier();

        $reponse = $this->postJson('/api/v1/triage/analyser', [
            'symptomes'    => [$symptome->id],
            'patient_sexe' => 'M',
        ])->assertCreated();

        $this->assertSame([], $reponse->json('specialites'));
        $this->assertNull($reponse->json('specialite_requise'));
    }

    public function test_un_enfant_sans_orientation_bascule_en_pediatrie(): void
    {
        $symptome = $this->symptome('Fièvre');
        $this->publier();

        $reponse = $this->postJson('/api/v1/triage/analyser', [
            'symptomes'   => [$symptome->id],
            'patient_age' => 6,
        ])->assertCreated();

        $this->assertSame(['pediatrie'], array_column($reponse->json('specialites'), 'code'));
        $this->assertSame('Pédiatrie', $reponse->json('specialite_requise'));
    }

    public function test_le_libelle_est_fige_par_la_version_et_ne_suit_pas_un_renommage(): void
    {
        // Le libellé vit dans l'instantané, pas dans une résolution à la lecture : renommer un terme
        // au vocabulaire ne change pas ce qu'un patient lit tant que rien n'a été republié.
        $symptome = $this->symptome('Douleur dentaire');
        $this->orienter($symptome, 'dentisterie', rang: 1);
        $this->publier();

        SpecialiteMedicale::where('code', 'dentisterie')->update(['libelle' => 'Odontologie']);
        $this->simulerNouvelleRequete();

        $reponse = $this->postJson('/api/v1/triage/analyser', ['symptomes' => [$symptome->id]])
            ->assertCreated();

        $this->assertSame('Dentisterie', $reponse->json('specialites.0.libelle'));
    }

    // ═════════════════════════════════════════════════════════════════════════════════════════
    // Les gardes du moteur et les contrôles qualité — G6
    // ═════════════════════════════════════════════════════════════════════════════════════════

    public function test_le_moteur_refuse_un_rang_nul(): void
    {
        $symptome = $this->symptome('Douleur dentaire');
        $specialite = SpecialiteMedicale::where('code', 'dentisterie')->first();

        $this->expectExceptionMessageMatches('/ck_orientation_rang/');

        DB::table('symptome_specialites')->insert([
            'symptome_id'   => $symptome->id,
            'specialite_id' => $specialite->id,
            'rang'          => 0,
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);
    }

    public function test_le_moteur_refuse_une_orientation_vers_un_terme_desactive(): void
    {
        $symptome = $this->symptome('Douleur dentaire');
        $specialite = SpecialiteMedicale::where('code', 'dentisterie')->first();
        $specialite->update(['actif' => false]);

        $this->expectExceptionMessageMatches('/ck_orientation_specialite_inactive/');

        DB::table('symptome_specialites')->insert([
            'symptome_id'   => $symptome->id,
            'specialite_id' => $specialite->id,
            'rang'          => 1,
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);
    }

    public function test_la_publication_refuse_une_orientation_devenue_inactive(): void
    {
        // GARDE G6 — LE SEUL DÉFAUT DE CETTE FAMILLE QUI NE FAIT AUCUN BRUIT. Le déclencheur ne se
        // déclenche pas quand la désactivation vient APRÈS, du côté du vocabulaire : la clé
        // étrangère reste satisfaite, la ligne reste en base, et le triage propose une spécialité
        // que l'annuaire ne peut plus rendre. L'écran est vide et rien ne le signale.
        $symptome = $this->symptome('Douleur dentaire');
        $this->orienter($symptome, 'dentisterie', rang: 1);

        SpecialiteMedicale::where('code', 'dentisterie')->update(['actif' => false]);

        $erreurs = app(SourceSymptomesTriage::class)
            ->controlerQualite(app(SourceSymptomesTriage::class)->extraire());

        $this->assertNotEmpty($erreurs);
        $this->assertStringContainsString('DÉSACTIVÉ', implode(' ', $erreurs));
    }

    public function test_la_publication_accepte_un_referentiel_sain(): void
    {
        // L'autre sens : le contrôle ne doit pas être un mur. Un référentiel correct passe.
        $symptome = $this->symptome('Douleur dentaire');
        $this->orienter($symptome, 'dentisterie', rang: 1);

        $source = app(SourceSymptomesTriage::class);

        $this->assertSame([], $source->controlerQualite($source->extraire()));
    }

    public function test_un_symptome_sans_orientation_est_legitime(): void
    {
        // Neuf des vingt symptômes du jeu réel n'orientent vers rien. Le contrôle qualité ne doit
        // pas l'interdire — ce serait un arbitrage clinique que le §10 ne donne pas au socle.
        $this->symptome('Fièvre');

        $source = app(SourceSymptomesTriage::class);
        $contenu = $source->extraire();

        $this->assertSame([], $contenu[0]['orientations']);
        $this->assertSame([], $source->controlerQualite($contenu));
    }

    // ═════════════════════════════════════════════════════════════════════════════════════════
    // La fiche du §5.4 — G7
    // ═════════════════════════════════════════════════════════════════════════════════════════

    public function test_la_fiche_n_est_pas_lisible_par_son_seul_identifiant(): void
    {
        // GARDE G7. Le défaut date du Module 1 : l'identifiant est SÉQUENTIEL, donc lire la fiche
        // du voisin demandait de changer un chiffre. 404 et non 403 — un 403 confirmerait qu'un
        // triage existe à cet identifiant, et l'énumération redeviendrait possible.
        $triage = $this->triageEnregistre();

        $this->getJson('/api/v1/triage/'.$triage->id.'/fiche')->assertStatus(404);
        $this->getJson('/api/v1/triage/'.$triage->id.'/fiche?jeton=faux')->assertStatus(404);
    }

    public function test_le_jeton_ouvre_la_fiche(): void
    {
        $triage = $this->triageEnregistre();

        $this->getJson('/api/v1/triage/'.$triage->id.'/fiche?jeton='.$triage->jeton_partage)
            ->assertOk()
            ->assertJsonPath('fiche.triage_id', $triage->id);
    }

    public function test_le_proprietaire_authentifie_lit_sa_fiche_sans_jeton(): void
    {
        $user = User::factory()->create();
        $triage = $this->triageEnregistre($user);

        Sanctum::actingAs($user);

        $this->getJson('/api/v1/triage/'.$triage->id.'/fiche')->assertOk();
    }

    public function test_un_autre_compte_ne_lit_pas_la_fiche(): void
    {
        $triage = $this->triageEnregistre(User::factory()->create());

        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/v1/triage/'.$triage->id.'/fiche')->assertStatus(404);
    }

    public function test_la_fiche_porte_les_quatre_elements_du_5_4_qui_manquaient(): void
    {
        $triage = $this->triageEnregistre();

        $reponse = $this->getJson('/api/v1/triage/'.$triage->id.'/fiche?jeton='.$triage->jeton_partage)
            ->assertOk();

        // 1. La mention obligatoire, au mot près (§5.4 la cite, elle ne la décrit pas).
        $this->assertSame(Triage::MENTION_OBLIGATOIRE, $reponse->json('fiche.mention_obligatoire'));
        $this->assertStringContainsString(
            'ne remplace pas un diagnostic médical',
            $reponse->json('texte_partage'),
        );

        // 2. Les réponses au questionnaire — stockées depuis le Module 1, jamais affichées.
        $this->assertIsArray($reponse->json('fiche.reponses'));

        // 3. Les hôpitaux proches proposant ce service, groupés par spécialité.
        $this->assertSame('dentisterie', $reponse->json('fiche.etablissements.0.specialite.code'));
        $this->assertSame(
            'Clinique du Sourire',
            $reponse->json('fiche.etablissements.0.etablissements.0.nom'),
        );

        // 4. Le QR « permettant au médecin d'accéder au triage » — il porte le jeton, et rien d'autre.
        $this->assertStringContainsString($triage->jeton_partage, $reponse->json('qr_payload'));
        $this->assertStringContainsString('/triage/'.$triage->id.'/fiche', $reponse->json('qr_payload'));
    }

    public function test_la_fiche_relit_l_orientation_enregistree_sans_la_recalculer(): void
    {
        // Rejouer l'agrégation aujourd'hui pourrait donner un autre résultat (le référentiel a pu
        // être republié), et la fiche cesserait de décrire la décision réellement rendue.
        $triage = $this->triageEnregistre();

        SymptomeSpecialite::query()->delete();
        SpecialiteMedicale::where('code', 'dentisterie')->update(['libelle' => 'Odontologie']);
        $this->simulerNouvelleRequete();

        $this->getJson('/api/v1/triage/'.$triage->id.'/fiche?jeton='.$triage->jeton_partage)
            ->assertOk()
            ->assertJsonPath('fiche.specialites.0.libelle', 'Dentisterie');
    }

    public function test_le_jeton_n_est_pas_assignable_en_masse(): void
    {
        // QUATRIÈME INSTANCE DU PIÈGE « le vecteur prouve le validateur, pas la garde » : un vecteur
        // HTTP ne prouverait rien, `validate()` écartant déjà les clés non déclarées. Celui-ci
        // appelle le MODÈLE directement, comme le ferait un import.
        $triage = Triage::create([
            'symptomes_json' => [],
            'reponses_json'  => [],
            'score_severite' => 10,
            'niveau'         => 'leger',
            'recommandation_texte' => 'Repos.',
            'jeton_partage'  => 'jeton-choisi-par-le-client',
        ]);

        $this->assertNotSame('jeton-choisi-par-le-client', $triage->jeton_partage);
        $this->assertSame(48, strlen($triage->jeton_partage));
    }

    public function test_le_jeton_n_est_jamais_serialise_par_defaut(): void
    {
        $triage = $this->triageEnregistre();

        $this->assertArrayNotHasKey('jeton_partage', $triage->fresh()->toArray());
    }

    // ═════════════════════════════════════════════════════════════════════════════════════════
    // L'historique — G8
    // ═════════════════════════════════════════════════════════════════════════════════════════

    public function test_l_historique_exige_un_compte(): void
    {
        // GARDE G8. Il renvoyait les cinquante derniers triages de TOUT LE MONDE — nom du patient,
        // âge, sexe, symptômes, score — sans authentification. Accès à des données de santé sans
        // lien de prise en charge : CDC_00 §4 le range parmi les interdits absolus.
        $this->triageEnregistre();

        $this->getJson('/api/v1/triage/historique')->assertStatus(401);
    }

    public function test_l_historique_ne_renvoie_que_les_triages_du_compte(): void
    {
        $moi = User::factory()->create();
        $autre = User::factory()->create();

        $lemien = $this->triageEnregistre($moi);
        $lesien = $this->triageEnregistre($autre);

        Sanctum::actingAs($moi);

        $reponse = $this->getJson('/api/v1/triage/historique')->assertOk();

        $ids = array_column($reponse->json('triages'), 'id');

        $this->assertContains($lemien->id, $ids);
        $this->assertNotContains($lesien->id, $ids);
    }

    public function test_le_filtre_par_membre_ne_rouvre_pas_la_porte(): void
    {
        $autre = User::factory()->create();
        $membreDeLAutre = MembreFamille::factory()->for($autre)->create();

        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/v1/triage/historique?membre_id='.$membreDeLAutre->id)
            ->assertStatus(403);
    }

    // ═════════════════════════════════════════════════════════════════════════════════════════
    // Fixtures
    // ═════════════════════════════════════════════════════════════════════════════════════════

    private function messageAbsenceVersion(): string
    {
        return 'Le référentiel national des symptômes de triage n\'a aucune version en vigueur : '
            .'aucun triage ne peut être rendu tant qu\'une version n\'a pas été publiée (CDC_09 §10).';
    }

    private function symptome(string $nom, int $poids = 20, bool $drapeau = false): Symptome
    {
        $this->vocabulaire();

        return Symptome::create([
            'nom_fr'         => $nom,
            'categorie'      => 'general',
            'poids_severite' => $poids,
            'drapeau_rouge'  => $drapeau,
            'actif'          => true,
        ]);
    }

    private function orienter(Symptome $symptome, string $code, int $rang, ?string $sexe = null): void
    {
        SymptomeSpecialite::create([
            'symptome_id'   => $symptome->id,
            'specialite_id' => SpecialiteMedicale::where('code', $code)->value('id'),
            'rang'          => $rang,
            'sexe_requis'   => $sexe,
        ]);
    }

    private function vocabulaire(): void
    {
        if (SpecialiteMedicale::query()->exists()) {
            return;
        }

        $termes = [
            'urgences'    => "Médecine d'urgence",
            'cardiologie' => 'Cardiologie',
            'dentisterie' => 'Dentisterie',
            'gynecologie' => 'Gynécologie-obstétrique',
            'orl'         => 'Oto-rhino-laryngologie (ORL)',
            'pediatrie'   => 'Pédiatrie',
        ];

        $ordre = 10;

        foreach ($termes as $code => $libelle) {
            $specialite = new SpecialiteMedicale;
            $specialite->forceFill([
                'code'      => $code,
                'pays_code' => 'CI',
                'libelle'   => $libelle,
                'nature'    => 'specialite_medicale',
                'ordre'     => $ordre += 10,
                'actif'     => true,
            ])->save();
        }
    }

    /**
     * P10b-1 — LE PROTOCOLE DE NIVEAU EST PUBLIÉ EN MÊME TEMPS QUE LES SYMPTÔMES.
     *
     * Depuis P10b-1, l'analyse répond 503 tant qu'aucun protocole n'est en vigueur : le niveau ne
     * vient plus du code. Les dix-sept vecteurs de ce fichier se sont donc mis à échouer d'un coup
     * — **c'est la preuve que le refus bruyant fonctionne**, et ils sont complétés, pas affaiblis :
     * aucune assertion n'a été retirée, aucun n'a été rendu tolérant au 503.
     *
     * Publier ici est exactement ce qu'un déploiement fera : deux agents habilités mettent la v1
     * en vigueur, après les quatre validations du §7. Même geste qu'en P10a pour les symptômes.
     */
    private function publier(): int
    {
        $this->publierProtocoleDeTriage();

        return $this->publierReferentiel(SourceSymptomesTriage::CODE);
    }

    /**
     * Un triage réellement passé par la chaîne HTTP — donc estampillé, orienté et pourvu d'un
     * jeton, comme en production. Le fabriquer à la main masquerait ce que le contrôleur pose.
     */
    private function triageEnregistre(?User $proprietaire = null): Triage
    {
        // Appelable DEUX FOIS dans le même vecteur (l'historique en a besoin) : on ne recrée ni le
        // symptôme — un doublon de libellé ferait échouer le contrôle qualité, à juste titre — ni
        // la publication, qui n'a lieu qu'une fois.
        if (($symptome = Symptome::firstWhere('nom_fr', 'Douleur dentaire')) === null) {
            $symptome = $this->symptome('Douleur dentaire');
            $this->orienter($symptome, 'dentisterie', rang: 1);
            $this->publier();
        }

        $structure = StructureSanitaire::firstOrCreate(['nom' => 'Clinique du Sourire'], [
            'type'      => 'clinique_privee',
            'adresse'   => 'Rue des Jardins, Cocody',
            'commune'   => 'Cocody',
            'latitude'  => 5.35,
            'longitude' => -3.99,
            'actif'     => true,
        ]);

        ServiceEtablissement::firstOrCreate(
            ['structure_id' => $structure->id, 'specialite' => 'dentisterie'],
            ['nom_service' => 'Cabinet dentaire', 'actif' => true],
        );

        if ($proprietaire !== null) {
            Sanctum::actingAs($proprietaire);
        }

        $reponse = $this->postJson('/api/v1/triage/analyser', [
            'symptomes'   => [$symptome->id],
            'patient_nom' => 'Aya K.',
        ])->assertCreated();

        // La session d'acteur ne doit pas fuir sur le vecteur suivant.
        app('auth')->forgetGuards();

        return Triage::find($reponse->json('triage_id'));
    }

    private function gouvernance(): ServiceGouvernanceReferentiel
    {
        return app(ServiceGouvernanceReferentiel::class);
    }
}
