<?php

namespace Tests\Feature;

use App\Models\MembreFamille;
use App\Models\MesureSante;
use App\Models\ReferentielMesure;
use App\Models\Symptome;
use App\Models\TriageConstante;
use App\Models\User;
use App\Services\Protocole\ControleQualiteProtocole;
use App\Services\Referentiel\SourceSeuilsMesure;
use App\Services\Referentiel\SourceSymptomesTriage;
use App\Services\Triage\FaitsTriage;
use App\Services\Triage\ServiceConstantesTriage;
use App\Support\RegistreFaitsProtocole;
use Database\Seeders\PortailRolesSeeder;
use Database\Seeders\ProtocoleSeeder;
use Database\Seeders\ReferentielMesureSeeder;
use Database\Seeders\SpecialiteMedicaleSeeder;
use Database\Seeders\SymptomeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Concerns\PublieLeProtocoleDeTriage;
use Tests\TestCase;

/**
 * P10c-1 — Les constantes cliniques du §5.2 dans le triage (ADR-043).
 *
 * Chaque refus est vérifié PAR SON MOTIF : un refus rendu pour la bonne conclusion mais la mauvaise
 * raison ne prouve rien (leçon P6.5b, P6.8e, P10b-1, P10b-3-i, P10b-3-ii).
 *
 * Les gardes qui vivent dans le SERVICE sont éprouvées deux fois — une par HTTP, une en appelant le
 * service directement, comme le ferait un import. Un vecteur qui ne passe que par HTTP prouve le
 * validateur, pas la garde : parade posée en P6.6b après quatre occurrences de ce piège.
 */
class ConstantesCliniquesTest extends TestCase
{
    use PublieLeProtocoleDeTriage;
    use RefreshDatabase;

    private User $user;

    private MembreFamille $membre;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(SpecialiteMedicaleSeeder::class);
        $this->seed(SymptomeSeeder::class);

        $this->user = User::factory()->create();
        $this->membre = MembreFamille::factory()->for($this->user)->create();
    }

    /** Met tout en vigueur : symptômes, seuils, et les quatre protocoles. */
    private function publierTout(): void
    {
        $this->publierReferentiel(SourceSymptomesTriage::CODE);
        $this->publierProtocoleDeTriage();
    }

    /** Un symptôme anodin — surtout PAS un drapeau rouge, qui masquerait ce qu'on mesure. */
    private function symptomeAnodin(): int
    {
        return (int) Symptome::query()
            ->where('drapeau_rouge', false)
            ->orderBy('poids_severite')
            ->value('id');
    }

    /** @param array<int, array<string, mixed>> $constantes */
    private function analyser(array $constantes, array $extra = []): TestResponse
    {
        return $this->postJson('/api/v1/triage/analyser', array_merge([
            'symptomes' => [$this->symptomeAnodin()],
            'constantes' => $constantes,
        ], $extra));
    }

    private function service(): ServiceConstantesTriage
    {
        return app(ServiceConstantesTriage::class);
    }

    // ═════════════════════════════════════════════════════════════════════════════════════
    // 1. LE REGISTRE DES FAITS — LA FORME SEULEMENT
    // ═════════════════════════════════════════════════════════════════════════════════════

    public function test_la_famille_constante_est_reconnue_et_sa_forme_est_fermee(): void
    {
        $this->assertTrue(RegistreFaitsProtocole::existe('constante.temperature'));
        $this->assertTrue(RegistreFaitsProtocole::estConstante('constante.saturation_o2'));
        $this->assertSame('saturation_o2', RegistreFaitsProtocole::typeConstante('constante.saturation_o2'));

        // La forme est fermée délibérément : une clé accentuée ou majuscule traverserait quatre
        // couches qui la normaliseraient chacune à sa façon (défaut `gyn_ecologie` de P6.8a).
        $this->assertFalse(RegistreFaitsProtocole::existe('constante.Température'));
        $this->assertFalse(RegistreFaitsProtocole::existe('constante.SPO2'));
        $this->assertFalse(RegistreFaitsProtocole::existe('constante.'));
    }

    public function test_une_constante_est_toujours_un_nombre(): void
    {
        // À la différence d'un `reponse.<cle>` dont le type est déclaré par la question, une
        // constante est numérique par construction. C'est ce qui fait que le contrôle de
        // compatibilité fait/opérateur s'applique sans être modifié.
        $this->assertSame(
            RegistreFaitsProtocole::TYPE_NOMBRE,
            RegistreFaitsProtocole::type('constante.temperature'),
        );

        $this->assertNull(RegistreFaitsProtocole::type('reponse.au_repos'));
    }

    public function test_le_libelle_d_une_constante_est_une_phrase_pas_un_code(): void
    {
        // Le §7 fait signer des médecins : leur montrer `constante.saturation_o2` brut reviendrait
        // à leur faire signer du code.
        $this->assertStringContainsString(
            'Constante mesurée',
            RegistreFaitsProtocole::libelle('constante.saturation_o2'),
        );
    }

    // ═════════════════════════════════════════════════════════════════════════════════════
    // 2. LE CONTRÔLE QUALITÉ CONFRONTE LE SUFFIXE AU RÉFÉRENTIEL PUBLIÉ
    // ═════════════════════════════════════════════════════════════════════════════════════

    /** @param array<string, mixed> $condition */
    private function controler(array $condition): array
    {
        return app(ControleQualiteProtocole::class)->controler([
            'metadonnees' => [
                'niveau_preuve' => 'D',
                'population' => 'Tous publics',
                'organisme' => 'Test',
                'contextes' => ['triage'],
            ],
            'references' => [['libelle' => 'Référence de test']],
            'questions' => [],
            'regles' => [[
                'ordre' => 1,
                'libelle' => 'Règle éprouvée',
                'conditions' => [$condition],
                'actions' => [['type' => 'AJOUTER_SCORE', 'valeur' => 5]],
            ]],
        ]);
    }

    public function test_une_constante_absente_de_la_version_publiee_bloque_la_publication(): void
    {
        $this->seed(ReferentielMesureSeeder::class);
        $this->publierLesSeuils();

        // `spo2` est le nom du §5.2 ; le référentiel dit `saturation_o2`. Sans ce contrôle, la
        // règle ne se déclencherait JAMAIS et rien ne le signalerait.
        $erreurs = $this->controler(['fait' => 'constante.spo2', 'operateur' => '>=', 'valeur' => 90]);

        $this->assertNotEmpty($erreurs);
        $this->assertStringContainsString('spo2', $erreurs[0]);
        $this->assertStringContainsString('saturation_o2', $erreurs[0], 'le refus NOMME les constantes admises');
    }

    public function test_une_constante_de_la_version_publiee_est_acceptee(): void
    {
        $this->seed(ReferentielMesureSeeder::class);
        $this->publierLesSeuils();

        $this->assertSame([], $this->controler([
            'fait' => 'constante.temperature', 'operateur' => '>=', 'valeur' => 39.5,
        ]));
    }

    public function test_aucune_version_publiee_le_refus_le_dit_au_lieu_de_tomber_en_panne(): void
    {
        $this->seed(ReferentielMesureSeeder::class);
        // Volontairement PAS publié : le contrôle qualité ne doit pas répondre 503 — il juge au
        // moment où un humain publie un protocole, et « ce type n'est pas publié » n'est pas une
        // panne de serveur (motif `estEnVigueur()` de P6.8e).
        $erreurs = $this->controler([
            'fait' => 'constante.temperature', 'operateur' => '>=', 'valeur' => 39.5,
        ]);

        $this->assertNotEmpty($erreurs);
        $this->assertStringContainsString('aucune version publiée', $erreurs[0]);
    }

    public function test_le_statut_du_referentiel_n_est_pas_un_fait_utilisable(): void
    {
        // ═══ LE VECTEUR DE LA DÉCISION CENTRALE ═══
        //
        // `critique_haut = 39.5` est gouverné par les DEUX signatures du §10. Un protocole qui s'y
        // adosserait déciderait de l'urgence sans passer par les QUATRE validations du §7 —
        // l'asymétrie refermée par P10b-3-i. Le suffixe a beau être bien formé, il ne désigne
        // aucun type publié : la publication est refusée.
        $this->seed(ReferentielMesureSeeder::class);
        $this->publierLesSeuils();

        $erreurs = $this->controler([
            'fait' => 'constante.temperature_statut', 'operateur' => '=', 'valeur' => 'critique',
        ]);

        $this->assertNotEmpty($erreurs);
        $this->assertStringContainsString('temperature_statut', $erreurs[0]);
    }

    public function test_un_operateur_de_liste_sur_une_constante_est_refuse(): void
    {
        $this->seed(ReferentielMesureSeeder::class);
        $this->publierLesSeuils();

        $erreurs = $this->controler([
            'fait' => 'constante.temperature', 'operateur' => 'contient', 'valeur' => 39,
        ]);

        $this->assertNotEmpty($erreurs);
        $this->assertStringContainsString('ne s\'applique pas', $erreurs[0]);
    }

    // ═════════════════════════════════════════════════════════════════════════════════════
    // 3. LES BORNES SONT OPPOSABLES — ON REFUSE, ON N'ÉCRÊTE JAMAIS
    // ═════════════════════════════════════════════════════════════════════════════════════

    public function test_une_valeur_hors_bornes_est_refusee_en_nommant_la_borne(): void
    {
        $this->publierTout();

        $reponse = $this->analyser([['type_mesure' => 'temperature', 'valeur' => 60]]);

        $reponse->assertStatus(422);
        $message = json_encode($reponse->json('errors'), JSON_UNESCAPED_UNICODE);

        $this->assertStringContainsString('45', $message, 'la borne publiée est nommée');
        $this->assertStringContainsString('pas ramenée dans la plage', $message);

        // RIEN n'est enregistré : ni le triage, ni la constante.
        $this->assertSame(0, TriageConstante::count());
    }

    public function test_la_borne_est_gardee_par_le_service_et_pas_seulement_par_le_validateur(): void
    {
        // Vecteur DÉDOUBLÉ. Le précédent passe par HTTP ; celui-ci appelle le service directement,
        // comme le ferait un import. Sans lui, neutraliser la garde du service laisserait le
        // vecteur HTTP vert si le validateur refusait pour une autre raison.
        $this->publierTout();

        $this->expectException(ValidationException::class);

        $this->service()->normaliser([['type_mesure' => 'temperature', 'valeur' => 60]]);
    }

    public function test_une_valeur_dans_les_bornes_est_acceptee_telle_quelle(): void
    {
        $this->publierTout();

        $this->analyser([['type_mesure' => 'temperature', 'valeur' => 38.4]])->assertStatus(201);

        $this->assertSame(38.4, (float) TriageConstante::query()->value('valeur'));
    }

    public function test_une_precision_de_trop_est_refusee_plutot_qu_arrondie(): void
    {
        // Une valeur trop précise serait arrondie en silence : le patient croirait avoir saisi une
        // valeur et son dossier en porterait une autre. Même faute que l'écrêtage, en plus discret.
        //
        // CE VECTEUR A ÉTÉ RÉÉCRIT après le G2 : il nommait « décimales » au pluriel, c'est-à-dire
        // la capacité de la colonne, seule borne qui mordait alors. Il porte désormais sur la
        // PROMESSE — « on refuse plutôt que d'altérer » — sans préjuger de laquelle des deux bornes
        // a mordu ; ce sont les deux vecteurs suivants qui les distinguent. Il n'a pas été corrigé
        // pour passer, il a été réécrit pour dire la garantie qui tient (précédent P6.4d).
        $this->publierTout();

        $reponse = $this->analyser([['type_mesure' => 'temperature', 'valeur' => 39.555]]);

        $reponse->assertStatus(422);
        $this->assertStringContainsString(
            'serait arrondie sans que vous le sachiez',
            json_encode($reponse->json('errors'), JSON_UNESCAPED_UNICODE),
        );
    }

    public function test_la_precision_publiee_par_le_referentiel_est_opposable(): void
    {
        // ═══ TROUVÉ PAR LE G2 LIVE, PAS PAR CES VECTEURS ═══
        //
        // Le référentiel publie `decimales = 1` pour la température, et le serveur acceptait 39,55 :
        // la borne gouvernée était **décorative**, seule la capacité de la colonne mordait. C'est
        // exactement le défaut que cet incrément referme pour `valeur_min`/`valeur_max` (constat X4
        // de P10b-3-i), laissé ouvert un cran plus loin.
        $this->publierTout();

        $reponse = $this->analyser([['type_mesure' => 'temperature', 'valeur' => 39.55]]);

        $reponse->assertStatus(422);
        $this->assertStringContainsString(
            'au plus 1 décimale',
            json_encode($reponse->json('errors'), JSON_UNESCAPED_UNICODE),
        );

        $this->assertSame(0, TriageConstante::query()->count());
    }

    public function test_la_precision_exactement_publiee_est_acceptee(): void
    {
        // Le miroir du vecteur précédent : sans lui, refuser TOUTE décimale passerait pour un
        // succès. La garde doit mordre à 39,55 et laisser passer 39,5.
        $this->publierTout();

        $this->analyser([['type_mesure' => 'temperature', 'valeur' => 39.5]])->assertStatus(201);

        $this->assertSame(39.5, (float) TriageConstante::query()->value('valeur'));
    }

    public function test_la_capacite_de_la_colonne_l_emporte_sur_une_precision_intenable(): void
    {
        // Défense en profondeur : si un référentiel publiait plus de décimales que la colonne
        // `decimal(8,2)` n'en sait porter, c'est le stockage qui a le dernier mot — et le message
        // nomme la borne **réellement appliquée**, jamais la promesse qu'on ne tiendrait pas.
        $this->publierTout();

        $this->corrigerUnSeuilEtPublier(
            'glycemie',
            ['decimales' => 3],
            'Précision portée à trois décimales — que le stockage ne sait pas tenir.',
        );

        $reponse = $this->analyser([['type_mesure' => 'glycemie', 'valeur' => 1.234]]);

        $reponse->assertStatus(422);
        $this->assertStringContainsString(
            'au plus 2 décimales',
            json_encode($reponse->json('errors'), JSON_UNESCAPED_UNICODE),
        );
    }

    public function test_une_valeur_non_numerique_est_refusee(): void
    {
        $this->publierTout();

        $this->analyser([['type_mesure' => 'temperature', 'valeur' => 'chaude']])->assertStatus(422);
    }

    public function test_un_type_absent_de_la_version_publiee_est_refuse_en_nommant_les_types(): void
    {
        $this->publierTout();

        $reponse = $this->analyser([['type_mesure' => 'spo2', 'valeur' => 91]]);

        $reponse->assertStatus(422);
        $message = json_encode($reponse->json('errors'), JSON_UNESCAPED_UNICODE);

        $this->assertStringContainsString('saturation_o2', $message);
    }

    public function test_le_meme_type_fourni_deux_fois_est_refuse(): void
    {
        // L'unicité est déclarative en base (`uq_triage_constante`) ; on refuse AVANT d'y arriver,
        // sinon le triage échouerait sur une erreur de contrainte au lieu d'un message lisible.
        $this->publierTout();

        $reponse = $this->analyser([
            ['type_mesure' => 'temperature', 'valeur' => 38.0],
            ['type_mesure' => 'temperature', 'valeur' => 39.0],
        ]);

        $reponse->assertStatus(422);
        $this->assertStringContainsString(
            'deux fois',
            json_encode($reponse->json('errors'), JSON_UNESCAPED_UNICODE),
        );
    }

    public function test_une_constante_vide_n_est_pas_une_erreur(): void
    {
        // Tout est facultatif au triage depuis le Module 1 ; cet incrément ne change pas ce contrat.
        $this->publierTout();

        $this->analyser([['type_mesure' => 'temperature', 'valeur' => null]])->assertStatus(201);
        $this->assertSame(0, TriageConstante::count());
    }

    public function test_un_triage_sans_aucune_constante_reste_possible(): void
    {
        $this->publierTout();

        $this->postJson('/api/v1/triage/analyser', [
            'symptomes' => [$this->symptomeAnodin()],
        ])->assertStatus(201);
    }

    // ═════════════════════════════════════════════════════════════════════════════════════
    // 4. L'ORIGINE EST DÉCIDÉE PAR LE SERVEUR, JAMAIS DÉCLARÉE PAR LE CLIENT
    // ═════════════════════════════════════════════════════════════════════════════════════

    private function mesureAuCarnet(string $type, float $valeur, int $minutes): MesureSante
    {
        $mesure = new MesureSante([
            'type_mesure' => $type,
            'valeur' => $valeur,
            'date_mesure' => now()->subMinutes($minutes),
        ]);

        $mesure->unite = '°C';
        $mesure->statut_norme = 'normal';
        $mesure->referentiel_version = 1;

        $this->membre->mesuresSante()->save($mesure);

        return $mesure;
    }

    public function test_le_client_ne_peut_pas_declarer_que_sa_valeur_vient_du_carnet(): void
    {
        $this->publierTout();
        Sanctum::actingAs($this->user);

        $this->analyser([[
            'type_mesure' => 'temperature',
            'valeur' => 38.4,
            // Ce que le client tente de faire croire.
            'origine' => TriageConstante::ORIGINE_CARNET,
            'mesure_id' => 4242,
        ]], ['membre_id' => $this->membre->id])->assertStatus(201);

        $constante = TriageConstante::query()->firstOrFail();

        $this->assertSame(TriageConstante::ORIGINE_SAISIE, $constante->origine);
        $this->assertNull($constante->mesure_id);
    }

    public function test_l_origine_est_decidee_par_le_service_et_pas_seulement_ecartee_par_le_validateur(): void
    {
        // Vecteur DÉDOUBLÉ, et c'est ici qu'il compte le plus : `validate()` écarte déjà les clés
        // non déclarées, si bien que le vecteur HTTP resterait vert même si le service faisait
        // confiance au client. Celui-ci appelle le service avec les clés, comme un import.
        $this->publierTout();

        $normalisees = $this->service()->normaliser([[
            'type_mesure' => 'temperature',
            'valeur' => 38.4,
            'origine' => TriageConstante::ORIGINE_CARNET,
            'mesure_id' => 4242,
        ]], $this->membre);

        $this->assertSame(TriageConstante::ORIGINE_SAISIE, $normalisees['temperature']['origine']);
        $this->assertNull($normalisees['temperature']['mesure_id']);
    }

    public function test_une_valeur_egale_a_celle_proposee_par_le_carnet_est_comptee_comme_reprise(): void
    {
        $this->publierTout();
        $mesure = $this->mesureAuCarnet('temperature', 37.2, 30); // fenêtre = 120 min
        Sanctum::actingAs($this->user);

        $this->analyser(
            [['type_mesure' => 'temperature', 'valeur' => 37.2]],
            ['membre_id' => $this->membre->id],
        )->assertStatus(201);

        $constante = TriageConstante::query()->firstOrFail();

        $this->assertSame(TriageConstante::ORIGINE_CARNET, $constante->origine);
        $this->assertSame((int) $mesure->id, $constante->mesure_id);
    }

    public function test_une_valeur_corrigee_par_le_patient_est_une_saisie(): void
    {
        // Le miroir du précédent : sans lui, le premier prouverait seulement qu'on sait écrire
        // « reprise » quelque part.
        $this->publierTout();
        $this->mesureAuCarnet('temperature', 37.2, 30);
        Sanctum::actingAs($this->user);

        $this->analyser(
            [['type_mesure' => 'temperature', 'valeur' => 39.1]],
            ['membre_id' => $this->membre->id],
        )->assertStatus(201);

        $constante = TriageConstante::query()->firstOrFail();

        $this->assertSame(TriageConstante::ORIGINE_SAISIE, $constante->origine);
        $this->assertNull($constante->mesure_id);
    }

    // ═════════════════════════════════════════════════════════════════════════════════════
    // 5. LA FRAÎCHEUR — UNE MESURE ANCIENNE N'EST JAMAIS PRÉSENTÉE COMME LE PRÉSENT
    // ═════════════════════════════════════════════════════════════════════════════════════

    public function test_une_mesure_recente_est_proposee_avec_sa_date(): void
    {
        $this->publierTout();
        $this->mesureAuCarnet('temperature', 37.2, 30);

        $ligne = collect($this->service()->proposables($this->membre))
            ->firstWhere('type_mesure', 'temperature');

        $this->assertNotNull($ligne['proposition']);
        $this->assertSame(37.2, $ligne['proposition']['valeur']);
        $this->assertNotNull($ligne['proposition']['date_mesure'], 'la date accompagne toujours la valeur');
        $this->assertNull($ligne['contexte']);
    }

    public function test_une_mesure_ancienne_est_montree_en_contexte_jamais_preremplie(): void
    {
        $this->publierTout();
        $this->mesureAuCarnet('temperature', 37.2, 60 * 24 * 3); // 3 jours, fenêtre = 120 min

        $ligne = collect($this->service()->proposables($this->membre))
            ->firstWhere('type_mesure', 'temperature');

        $this->assertNull($ligne['proposition'], 'une température de 3 jours n\'est pas une température');
        $this->assertNotNull($ligne['contexte']);
        $this->assertSame(37.2, $ligne['contexte']['valeur']);
    }

    public function test_une_valeur_egale_a_un_contexte_perime_n_est_pas_comptee_comme_reprise(): void
    {
        // Le corollaire qui compte : hors fenêtre, la valeur n'a PAS été proposée. La saisir reste
        // une saisie — sinon le dossier dirait que le patient a validé une proposition qu'on ne lui
        // a jamais faite.
        $this->publierTout();
        $this->mesureAuCarnet('temperature', 37.2, 60 * 24 * 3);
        Sanctum::actingAs($this->user);

        $this->analyser(
            [['type_mesure' => 'temperature', 'valeur' => 37.2]],
            ['membre_id' => $this->membre->id],
        )->assertStatus(201);

        $this->assertSame(TriageConstante::ORIGINE_SAISIE, TriageConstante::query()->value('origine'));
    }

    public function test_une_fraicheur_absente_ne_propose_jamais(): void
    {
        // `null` = jamais pré-rempli. Le sens sûr : une donnée absente ne doit pas autoriser
        // silencieusement la réutilisation d'une mesure ancienne (motif P6.5a).
        $this->seed(ReferentielMesureSeeder::class);
        ReferentielMesure::where('type_mesure', 'temperature')->update(['fraicheur_max_minutes' => null]);
        $this->publierReferentiel(SourceSeuilsMesure::CODE);

        $this->mesureAuCarnet('temperature', 37.2, 1);

        $ligne = collect($this->service()->proposables($this->membre))
            ->firstWhere('type_mesure', 'temperature');

        $this->assertNull($ligne['proposition']);
        $this->assertNotNull($ligne['contexte']);
    }

    public function test_une_fraicheur_absente_ne_propose_pas_meme_une_mesure_datee_du_futur(): void
    {
        // ═══ CE VECTEUR EXISTE PARCE QUE LE PRÉCÉDENT NE PROUVAIT PAS CE QU'IL DISAIT ═══
        //
        // La campagne de mutation a montré que `test_une_fraicheur_absente_ne_propose_jamais`
        // survivait à la neutralisation de la garde : sans elle, `(int) null` vaut 0, la fenêtre
        // devient « zéro minute », et une mesure passée est écartée de toute façon. Il prouvait
        // l'arithmétique, pas l'intention.
        //
        // Une mesure datée du FUTUR les sépare — horloge d'appareil en avance, ou date saisie à la
        // main. L'arithmétique la dirait fraîche ; la garde dit **jamais**.
        $this->seed(ReferentielMesureSeeder::class);
        ReferentielMesure::where('type_mesure', 'temperature')->update(['fraicheur_max_minutes' => null]);
        $this->publierReferentiel(SourceSeuilsMesure::CODE);

        $this->mesureAuCarnet('temperature', 37.2, -10); // dans 10 minutes

        $ligne = collect($this->service()->proposables($this->membre))
            ->firstWhere('type_mesure', 'temperature');

        $this->assertNull($ligne['proposition'], 'aucune fenêtre publiée : on ne propose jamais');
        $this->assertNotNull($ligne['contexte']);
    }

    public function test_la_fenetre_change_avec_la_version_publiee_sans_toucher_une_ligne_de_code(): void
    {
        // ═══ LE VECTEUR CENTRAL DE LA DÉCISION E3 ═══
        //
        // La même mesure, le même patient, deux versions du référentiel : proposée, puis reléguée
        // au contexte. C'est ce que « la fraîcheur est une donnée » veut dire.
        $this->seed(ReferentielMesureSeeder::class);
        $this->publierLesSeuils();
        $this->mesureAuCarnet('temperature', 37.2, 90); // fenêtre initiale = 120 min

        $this->assertNotNull(
            collect($this->service()->proposables($this->membre))->firstWhere('type_mesure', 'temperature')['proposition'],
        );

        $this->corrigerUnSeuilEtPublier('temperature', ['fraicheur_max_minutes' => 30], 'Fenêtre resserrée.');

        $ligne = collect($this->service()->proposables($this->membre))
            ->firstWhere('type_mesure', 'temperature');

        $this->assertNull($ligne['proposition']);
        $this->assertNotNull($ligne['contexte']);
    }

    public function test_un_update_direct_reste_sans_effet_tant_qu_il_n_est_pas_publie(): void
    {
        $this->seed(ReferentielMesureSeeder::class);
        $this->publierLesSeuils();
        $this->mesureAuCarnet('temperature', 37.2, 90);

        // Correction en TABLE, sans publication : elle ne doit rien changer.
        ReferentielMesure::where('type_mesure', 'temperature')->update(['fraicheur_max_minutes' => 30]);
        $this->simulerNouvelleRequete();

        $this->assertNotNull(
            collect($this->service()->proposables($this->membre))->firstWhere('type_mesure', 'temperature')['proposition'],
            'le référentiel diffusé ne change qu\'à la publication',
        );
    }

    public function test_une_fenetre_nulle_ou_negative_bloque_la_publication(): void
    {
        $this->seed(ReferentielMesureSeeder::class);
        ReferentielMesure::where('type_mesure', 'temperature')->update(['fraicheur_max_minutes' => 0]);

        $erreurs = (new SourceSeuilsMesure)->controlerQualite((new SourceSeuilsMesure)->extraire());

        $this->assertNotEmpty($erreurs);
        $this->assertStringContainsString('fenêtre de fraîcheur', implode(' ', $erreurs));
    }

    // ═════════════════════════════════════════════════════════════════════════════════════
    // 6. LE §1.2 RETOURNÉ À L'ENDROIT — LE SEUIL EST UNE RÈGLE SIGNÉE
    // ═════════════════════════════════════════════════════════════════════════════════════

    public function test_la_fievre_du_jeune_enfant_releve_le_niveau_par_une_regle_publiee(): void
    {
        $this->publierTout();

        $reponse = $this->analyser(
            [['type_mesure' => 'temperature', 'valeur' => 39.6]],
            ['patient_age' => 4],
        );

        $reponse->assertStatus(201);
        $this->assertSame('urgence', $reponse->json('niveau'));
        $this->assertGreaterThanOrEqual(90, $reponse->json('score_severite'));

        // La règle est NOMMÉE dans la trace : sans elle, la recommandation serait une affirmation
        // sans origine (§9.1, §10).
        $this->assertStringContainsString(
            'Fièvre élevée',
            json_encode($reponse->json('regles_declenchees'), JSON_UNESCAPED_UNICODE),
        );
    }

    public function test_la_meme_fievre_chez_l_adulte_ne_declenche_pas_la_regle(): void
    {
        // Le miroir : sans lui, le vecteur précédent prouverait seulement qu'un score monte.
        $this->publierTout();

        $reponse = $this->analyser(
            [['type_mesure' => 'temperature', 'valeur' => 39.6]],
            ['patient_age' => 30],
        );

        $reponse->assertStatus(201);
        $this->assertNotSame('urgence', $reponse->json('niveau'));
    }

    public function test_une_fievre_sous_le_seuil_ne_declenche_pas_la_regle(): void
    {
        $this->publierTout();

        $reponse = $this->analyser(
            [['type_mesure' => 'temperature', 'valeur' => 38.2]],
            ['patient_age' => 4],
        );

        $reponse->assertStatus(201);
        $this->assertNotSame('urgence', $reponse->json('niveau'));
    }

    public function test_sans_constante_la_regle_ne_se_declenche_pas_et_le_triage_aboutit(): void
    {
        // Un fait CONNU mais non renseigné pour ce patient ne lève pas : c'est la distinction que
        // `MoteurProtocole` pose depuis P10b-1, et c'est ce qui garde le triage anonyme possible.
        $this->publierTout();

        $reponse = $this->postJson('/api/v1/triage/analyser', [
            'symptomes' => [$this->symptomeAnodin()],
            'patient_age' => 4,
        ]);

        $reponse->assertStatus(201);
        $this->assertNotSame('urgence', $reponse->json('niveau'));
    }

    // ═════════════════════════════════════════════════════════════════════════════════════
    // 7. LE TRIAGE N'ÉCRIT RIEN DANS LE CARNET
    // ═════════════════════════════════════════════════════════════════════════════════════

    public function test_un_triage_avec_constantes_laisse_le_carnet_intact(): void
    {
        // Écrire la température du triage dans `mesures_sante` ouvrirait un 4ᵉ chemin d'écriture
        // dans une table du carnet, avec sa question de rejeu et de suppression (motif W3 de P6.8b).
        $this->publierTout();
        Sanctum::actingAs($this->user);

        $avant = MesureSante::count();

        $this->analyser(
            [['type_mesure' => 'temperature', 'valeur' => 39.1]],
            ['membre_id' => $this->membre->id],
        )->assertStatus(201);

        $this->assertSame($avant, MesureSante::count());
        $this->assertSame(1, TriageConstante::count());
    }

    // ═════════════════════════════════════════════════════════════════════════════════════
    // 8. AUCUN STATUT NE SORT DANS LES FAITS
    // ═════════════════════════════════════════════════════════════════════════════════════

    public function test_les_faits_ne_portent_que_la_valeur_brute(): void
    {
        $this->publierTout();

        $faits = $this->service()->faits(
            $this->service()->normaliser([['type_mesure' => 'temperature', 'valeur' => 39.6]]),
        );

        $this->assertSame(['constante.temperature' => 39.6], $faits);

        foreach (array_keys($faits) as $cle) {
            $this->assertStringNotContainsString('_statut', $cle);
        }
    }

    public function test_la_base_des_faits_accueille_les_constantes(): void
    {
        $this->publierTout();

        $base = FaitsTriage::base(
            Symptome::query()->limit(1)->get(),
            30,
            'F',
            ['constante.temperature' => 39.6],
        );

        $this->assertSame(39.6, $base['constante.temperature']);
        $this->assertArrayHasKey('score_symptomes', $base, 'la base reste intacte');
    }

    // ═════════════════════════════════════════════════════════════════════════════════════
    // 9. LES DEUX ENDPOINTS REÇOIVENT LES CONSTANTES (constat Z1)
    // ═════════════════════════════════════════════════════════════════════════════════════

    public function test_le_tour_de_questionnaire_accepte_les_constantes(): void
    {
        $this->publierTout();

        $this->postJson('/api/v1/triage/questions', [
            'symptomes' => [$this->symptomeAnodin()],
            'constantes' => [['type_mesure' => 'temperature', 'valeur' => 39.1]],
        ])->assertStatus(200);
    }

    public function test_le_tour_de_questionnaire_refuse_aussi_une_valeur_hors_bornes(): void
    {
        // Sans ce contrôle ici, une valeur aberrante entrerait dans le moteur et débloquerait
        // peut-être une question qu'elle n'aurait pas dû déclencher.
        $this->publierTout();

        $this->postJson('/api/v1/triage/questions', [
            'symptomes' => [$this->symptomeAnodin()],
            'constantes' => [['type_mesure' => 'temperature', 'valeur' => 60]],
        ])->assertStatus(422);
    }

    // ═════════════════════════════════════════════════════════════════════════════════════
    // 10. L'ENDPOINT DE PRÉ-REMPLISSAGE ET SA GARDE ANTI-IDOR
    // ═════════════════════════════════════════════════════════════════════════════════════

    public function test_les_constantes_collectables_sont_servies_sans_compte(): void
    {
        $this->publierTout();

        $reponse = $this->getJson('/api/v1/triage/constantes');

        $reponse->assertStatus(200);
        $this->assertNotEmpty($reponse->json('constantes'));
        $this->assertNull($reponse->json('constantes.0.proposition'), 'aucun carnet, donc rien à proposer');
        $this->assertNotNull($reponse->json('referentiel_version'));
    }

    public function test_lire_le_carnet_d_autrui_est_refuse(): void
    {
        $this->publierTout();
        $autre = User::factory()->create();
        Sanctum::actingAs($autre);

        $this->getJson('/api/v1/triage/constantes?membre_id='.$this->membre->id)->assertStatus(403);
    }

    public function test_un_membre_exige_un_compte_authentifie(): void
    {
        $this->publierTout();

        $this->getJson('/api/v1/triage/constantes?membre_id='.$this->membre->id)->assertStatus(401);
    }

    public function test_la_route_litterale_n_est_pas_captee_par_la_fiche(): void
    {
        // `/triage/constantes` est littérale et `/triage/{triage}/fiche` porte un paramètre : une
        // route littérale déclarée APRÈS se ferait capter (piège P7-D0, P6.5b, P6.6b).
        $this->publierTout();

        $this->getJson('/api/v1/triage/constantes')->assertStatus(200);
    }

    // ═════════════════════════════════════════════════════════════════════════════════════
    // 11. REFUS BRUYANT, ESTAMPILLE ET FICHE
    // ═════════════════════════════════════════════════════════════════════════════════════

    public function test_sans_seuils_publies_une_constante_est_refusee_bruyamment(): void
    {
        $this->seed(PortailRolesSeeder::class);
        $this->seed(ProtocoleSeeder::class);
        $this->seed(ReferentielMesureSeeder::class);
        $this->publierReferentiel(SourceSymptomesTriage::CODE);

        // Les seuils NE SONT PAS publiés. Le service refuse plutôt que de replier sur la table :
        // un repli laisserait un oubli de publication invisible (décision de L1+L2).
        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('aucune version en vigueur');

        $this->service()->normaliser([['type_mesure' => 'temperature', 'valeur' => 38.0]]);
    }

    public function test_chaque_constante_porte_la_version_qui_l_a_acceptee(): void
    {
        $this->publierTout();

        $this->analyser([['type_mesure' => 'temperature', 'valeur' => 38.4]])->assertStatus(201);

        $constante = TriageConstante::query()->firstOrFail();

        $this->assertSame($this->service()->version(), $constante->referentiel_version);
        $this->assertSame('°C', $constante->unite, 'l\'unité est figée à l\'écriture');
    }

    public function test_la_fiche_montre_les_constantes_avec_leur_origine(): void
    {
        $this->publierTout();
        Sanctum::actingAs($this->user);

        $triageId = $this->analyser(
            [['type_mesure' => 'temperature', 'valeur' => 39.1]],
            ['membre_id' => $this->membre->id],
        )->json('triage_id');

        $fiche = $this->getJson("/api/v1/triage/{$triageId}/fiche")->json('fiche.constantes');

        $this->assertCount(1, $fiche);
        $this->assertSame('temperature', $fiche[0]['type_mesure']);
        $this->assertSame(TriageConstante::ORIGINE_SAISIE, $fiche[0]['origine']);
    }

    public function test_la_reponse_de_l_analyse_rend_l_origine_decidee_par_le_serveur(): void
    {
        $this->publierTout();

        $reponse = $this->analyser([['type_mesure' => 'temperature', 'valeur' => 38.4]]);

        $reponse->assertStatus(201);
        $this->assertSame('temperature', $reponse->json('constantes.0.type_mesure'));
        $this->assertSame(TriageConstante::ORIGINE_SAISIE, $reponse->json('constantes.0.origine'));
    }
}
