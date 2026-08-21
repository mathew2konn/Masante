<?php

namespace Tests\Feature;

use App\Models\Protocole;
use App\Models\ProtocoleJournal;
use App\Models\ProtocoleValidation;
use App\Models\ProtocoleVersion;
use App\Models\Symptome;
use App\Models\Triage;
use App\Models\User;
use App\Services\Protocole\CompilateurProtocole;
use App\Services\Protocole\DiffusionProtocole;
use App\Services\Protocole\JournalProtocole;
use App\Services\Protocole\ProtocoleException;
use App\Services\Protocole\ServiceGouvernanceProtocole;
use App\Services\Referentiel\SourceSymptomesTriage;
use App\Services\Triage\ServiceNiveauTriage;
use App\Services\Triage\ServicePlafondAntecedents;
use App\Services\Triage\ServiceQuestionnaire;
use App\Support\NiveauTriage;
use App\Support\RegistreActionsProtocole;
use Database\Seeders\PortailRolesSeeder;
use Database\Seeders\ProtocoleSeeder;
use Database\Seeders\SpecialiteMedicaleSeeder;
use Database\Seeders\SymptomeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\GouverneUnReferentiel;
use Tests\TestCase;

/**
 * P10b-1 — Le registre des protocoles médicaux et le niveau de triage qui en sort (CDC_08).
 *
 * ═══ ÉCRITE DANS LES DEUX SENS ═══
 *
 * Chacune des quatre gardes de publication a son vecteur qui passe ET son vecteur qui refuse :
 * l'habilitation, les quatre validations du §7, le quatre-yeux et l'anti-substitution ne se
 * rattrapent pas l'une l'autre. Une suite qui ne vérifierait que le chemin heureux prouverait
 * qu'on sait publier, pas qu'on sait refuser — et c'est le refus qui empêche une règle clinique
 * non relue d'atteindre un patient.
 *
 * ═══ CE QU'ELLE PROTÈGE EN PARTICULIER ═══
 *
 * Que le niveau de priorité ne redevienne jamais une ligne de PHP. Le vecteur central
 * ({@see test_le_seuil_vient_du_protocole_et_non_du_code}) modifie une bande EN BASE et vérifie
 * que la réponse change : si quelqu'un remettait un `match` de repli dans `TriageService`, ce
 * vecteur tomberait.
 */
class ProtocoleMedicalTest extends TestCase
{
    use GouverneUnReferentiel;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PortailRolesSeeder::class);
        $this->seed(SpecialiteMedicaleSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // Fixtures
    // ─────────────────────────────────────────────────────────────────────────────

    private function gouvernance(): ServiceGouvernanceProtocole
    {
        return app(ServiceGouvernanceProtocole::class);
    }

    private function agent(string ...$permissions): User
    {
        $user = User::factory()->create();

        foreach ($permissions as $permission) {
            $user->givePermissionTo($permission);
        }

        return $user->fresh();
    }

    /** Un agent portant les quatre habilitations de validation du §7. */
    private function relecteur(): User
    {
        return $this->agent(...array_values(ServiceGouvernanceProtocole::PERMISSIONS_VALIDATION));
    }

    /** Enregistre les quatre validations favorables du §7 sur une version. */
    private function validerQuatreFois(ProtocoleVersion $version, ?User $relecteur = null): void
    {
        $relecteur ??= $this->relecteur();

        foreach (array_keys(ServiceGouvernanceProtocole::PERMISSIONS_VALIDATION) as $type) {
            $this->gouvernance()->valider($version, $relecteur, $type, 'favorable', 'Relecteur '.$type);
        }
    }

    /**
     * Le chemin nominal complet : seeder → quatre validations → publication.
     *
     * DEUX COMPTES AU MINIMUM, et ce n'est pas une lourdeur de test : le quatre-yeux du §10 ne se
     * contourne pas. Un helper qui aurait triché avec un seul compte aurait prouvé le contraire de
     * ce que la gouvernance garantit (raisonnement de `GouverneUnReferentiel`, L1+L2).
     */
    private function publierProtocoleDeTriage(): ProtocoleVersion
    {
        $this->seed(ProtocoleSeeder::class);

        $version = $this->versionDeTriage();
        $this->validerQuatreFois($version);

        $publiee = $this->gouvernance()->publier($version, $this->agent(ServiceGouvernanceProtocole::PERMISSION_PUBLIER));

        $this->simulerNouvelleRequete();

        return $publiee;
    }

    private function versionDeTriage(): ProtocoleVersion
    {
        return Protocole::query()
            ->where('code', ServiceNiveauTriage::CODE)
            ->firstOrFail()
            ->versions()
            ->where('etat', ProtocoleVersion::BROUILLON)
            ->firstOrFail();
    }

    /**
     * Le triage complet exige TROIS mises en vigueur, et ce nombre a augmenté à chaque bascule.
     *
     * P10a a ajouté le référentiel des symptômes, P10b-1 le protocole de niveau, **P10b-3-i le
     * questionnaire**. Chaque fois, les vecteurs antérieurs se sont mis à répondre 503 d'un coup :
     * c'est la preuve que le refus bruyant fonctionne, pas une régression. Ils sont complétés ici,
     * jamais rendus tolérants au 503.
     */
    private function preparerTriageComplet(): void
    {
        $this->seed(SymptomeSeeder::class);
        $this->publierReferentiel(SourceSymptomesTriage::CODE);
        $this->publierProtocoleDeTriage();
        $this->publierQuestionnaire();
        $this->publierProtocoleAuxiliaire(ServicePlafondAntecedents::CODE);
    }

    /**
     * P10b-3-i — Met en vigueur le questionnaire, par le même chemin nominal que le niveau.
     *
     * Aucun raccourci par la base : quatre validations puis publication, à deux comptes distincts.
     */
    private function publierQuestionnaire(): void
    {
        $this->publierProtocoleAuxiliaire(ServiceQuestionnaire::CODE);
    }

    /**
     * P10b-3-ii — Met en vigueur un protocole seedé, par le chemin nominal.
     *
     * Le corps de `publierQuestionnaire()` est devenu générique quand une QUATRIÈME étape de
     * déploiement est apparue (la borne des antécédents) : le recopier une troisième fois aurait
     * été la duplication que le constat Z1 vient de fermer ailleurs.
     */
    private function publierProtocoleAuxiliaire(string $code): void
    {
        $version = Protocole::query()
            ->where('code', $code)
            ->firstOrFail()
            ->versions()
            ->where('etat', ProtocoleVersion::BROUILLON)
            ->firstOrFail();

        $this->validerQuatreFois($version);
        $this->gouvernance()->publier($version, $this->agent(ServiceGouvernanceProtocole::PERMISSION_PUBLIER));

        $this->simulerNouvelleRequete();
    }

    private function analyser(array $charge): TestResponse
    {
        return $this->postJson('/api/v1/triage/analyser', $charge);
    }

    private function symptome(string $nom): Symptome
    {
        return Symptome::query()->where('nom_fr', $nom)->firstOrFail();
    }

    // ═════════════════════════════════════════════════════════════════════════════
    // 1. Le vecteur central — le seuil vient de la base, pas du code
    // ═════════════════════════════════════════════════════════════════════════════

    /**
     * MODIFIER UNE BANDE EN BASE CHANGE LE NIVEAU RENDU.
     *
     * C'est le vecteur qui tombe si quelqu'un remet un seuil de repli dans `TriageService`. Le
     * même score (12) est rendu `faible` sous la version 1 puis `rapide` sous la version 2, sans
     * qu'une ligne de code ait changé.
     */
    public function test_le_seuil_vient_du_protocole_et_non_du_code(): void
    {
        $this->preparerTriageComplet();

        $charge = ['symptomes' => [$this->symptome('Courbatures (douleurs musculaires)')->id]];

        $premier = $this->analyser($charge);
        $premier->assertCreated()->assertJsonPath('niveau', NiveauTriage::FAIBLE);

        // On rédige une v2 où la bande basse devient « consultation rapide ». Rien d'autre ne
        // change : ni le score, ni le symptôme, ni une ligne de PHP.
        $auteur = $this->agent(ServiceGouvernanceProtocole::PERMISSION_REDIGER);
        $protocole = Protocole::query()->where('code', ServiceNiveauTriage::CODE)->firstOrFail();

        $v2 = $this->gouvernance()->ouvrirBrouillon($protocole, $auteur, '2026.2', 'Reclassement de la bande basse.', [
            'niveau_preuve' => 'D',
            'population' => 'Tous publics',
        ]);

        $this->recopierRegles($protocole->versionActive(), $v2, function (array $regle): array {
            if ($regle['libelle'] === 'Score de 0 à 25 : Faible priorité') {
                $regle['actions'][0]['valeur'] = NiveauTriage::RAPIDE;
            }

            return $regle;
        });

        $this->validerQuatreFois($v2->refresh());
        $this->gouvernance()->publier($v2->refresh(), $this->agent(ServiceGouvernanceProtocole::PERMISSION_PUBLIER));
        $this->simulerNouvelleRequete();

        $second = $this->analyser($charge);
        $second->assertCreated()->assertJsonPath('niveau', NiveauTriage::RAPIDE);

        // L'estampille suit : les deux triages citent des versions différentes, ce qui est
        // précisément ce que le §6.1 exige pour rester explicable.
        $this->assertSame(1, $premier->json('protocole.numero'));
        $this->assertSame(2, $second->json('protocole.numero'));
    }

    /**
     * LE DRAPEAU ROUGE PRIME — ET SA PRIORITÉ EST UNE DONNÉE.
     *
     * `if ($drapeauRouge) { $score = max($score, 90); }` a quitté le code : c'est l'ordre 1 d'une
     * règle qui relève le score, et les bandes suivantes voient la valeur relevée.
     */
    public function test_le_drapeau_rouge_impose_l_urgence_par_une_regle_ordonnee(): void
    {
        $this->preparerTriageComplet();

        // « Douleur dentaire » pèse 8 — bande basse. Avec un symptôme à drapeau rouge, le score
        // est relevé à 90 et bascule dans la bande haute.
        $reponse = $this->analyser([
            'symptomes' => [
                $this->symptome('Douleur dentaire')->id,
                $this->symptome('Convulsions')->id,
            ],
        ]);

        $reponse->assertCreated()
            ->assertJsonPath('niveau', NiveauTriage::URGENCE)
            ->assertJsonPath('drapeau_rouge', true);

        $this->assertGreaterThanOrEqual(90, $reponse->json('score_severite'));
    }

    /** Les quatre niveaux patient de CDC_05 §5.3 sortent réellement du moteur. */
    public function test_les_quatre_niveaux_patient_sont_rendus(): void
    {
        $this->preparerTriageComplet();

        $protocole = app(DiffusionProtocole::class)->lire(ServiceNiveauTriage::CODE);

        $niveaux = collect($protocole['contenu']['regles'])
            ->flatMap(fn (array $r): array => $r['actions'])
            ->where('type', RegistreActionsProtocole::DEFINIR_NIVEAU)
            ->pluck('valeur')
            ->sort()
            ->values()
            ->all();

        $attendus = NiveauTriage::PATIENT;
        sort($attendus);

        $this->assertSame($attendus, $niveaux);
    }

    /**
     * Les trois valeurs héritées du Module 1 ne sont plus produites — mais restent lisibles.
     *
     * Convertir l'historique changerait ce qu'un patient a réellement lu sur son écran : un
     * mensonge d'archive (précédent `mesures_sante.referentiel_version` laissée NULL en L1+L2).
     */
    public function test_les_niveaux_herites_restent_acceptes_par_la_colonne_mais_ne_sont_plus_produits(): void
    {
        $this->preparerTriageComplet();

        // La colonne accepte encore l'ancien vocabulaire : un triage d'avant P10b n'est pas
        // réécrit et reste relisible.
        $ancien = Triage::create([
            'symptomes_json' => [], 'reponses_json' => [],
            'score_severite' => 40, 'niveau' => 'modere',
            'recommandation_texte' => 'Triage antérieur à P10b.',
        ]);

        $this->assertSame('modere', $ancien->fresh()->niveau);
        $this->assertNull($ancien->fresh()->protocole_version, 'Un triage antérieur ne reçoit aucune estampille');

        // Mais plus rien ne les produit.
        $niveau = $this->analyser(['symptomes' => [$this->symptome('Frissons')->id]])->json('niveau');

        $this->assertTrue(NiveauTriage::estValide($niveau));
        $this->assertFalse(NiveauTriage::estHerite($niveau));
    }

    // ═════════════════════════════════════════════════════════════════════════════
    // 2. Refus bruyant et estampille
    // ═════════════════════════════════════════════════════════════════════════════

    /**
     * SANS PROTOCOLE EN VIGUEUR, LE TRIAGE REFUSE — il ne replie pas sur des seuils par défaut.
     *
     * Un repli laisserait un oubli de publication passer inaperçu : le triage rendrait des niveaux
     * que personne n'a validés, en croyant appliquer un protocole (décision de L1+L2).
     */
    public function test_sans_protocole_en_vigueur_l_analyse_refuse_bruyamment(): void
    {
        $this->seed(SymptomeSeeder::class);
        $this->publierReferentiel(SourceSymptomesTriage::CODE);
        $this->seed(ProtocoleSeeder::class); // brouillon seulement : rien n'est publié

        $this->analyser(['symptomes' => [$this->symptome('Frissons')->id]])
            ->assertStatus(503);
    }

    public function test_le_triage_conserve_la_version_exacte_du_protocole(): void
    {
        $this->preparerTriageComplet();

        $reponse = $this->analyser(['symptomes' => [$this->symptome('Frissons')->id]]);

        $triage = Triage::findOrFail($reponse->json('triage_id'));

        $this->assertSame(ServiceNiveauTriage::CODE, $triage->protocole_code);
        $this->assertSame(1, $triage->protocole_version);
        $this->assertSame('2026.1', $reponse->json('protocole.version'));
    }

    /** Le client ne peut pas choisir l'estampille : le serveur la reprend du résultat calculé. */
    public function test_le_client_ne_peut_pas_declarer_la_version_du_protocole(): void
    {
        $this->preparerTriageComplet();

        $reponse = $this->analyser([
            'symptomes' => [$this->symptome('Frissons')->id],
            'protocole_version' => 999,
            'protocole_code' => 'PROT-INVENTE',
            'niveau' => NiveauTriage::URGENCE,
        ]);

        $triage = Triage::findOrFail($reponse->json('triage_id'));

        $this->assertSame(1, $triage->protocole_version);
        $this->assertSame(ServiceNiveauTriage::CODE, $triage->protocole_code);
        $this->assertNotSame(NiveauTriage::URGENCE, $triage->niveau);
    }

    /** §6.1 — « un protocole archivé reste consultable indéfiniment ». */
    public function test_une_version_archivee_reste_consultable(): void
    {
        $this->preparerTriageComplet();

        $protocole = Protocole::query()->where('code', ServiceNiveauTriage::CODE)->firstOrFail();
        $auteur = $this->agent(ServiceGouvernanceProtocole::PERMISSION_REDIGER);

        $v2 = $this->gouvernance()->ouvrirBrouillon($protocole, $auteur, '2026.2', 'Nouvelle version complète.', [
            'niveau_preuve' => 'D', 'population' => 'Tous publics',
        ]);
        $this->recopierRegles($protocole->versionActive(), $v2);
        $this->validerQuatreFois($v2->refresh());
        $this->gouvernance()->publier($v2->refresh(), $this->agent(ServiceGouvernanceProtocole::PERMISSION_PUBLIER));

        $this->getJson('/api/v1/protocoles/'.ServiceNiveauTriage::CODE.'/versions/1')
            ->assertOk()
            ->assertJsonPath('etat', ProtocoleVersion::ARCHIVE)
            ->assertJsonPath('version', '2026.1');
    }

    // ═════════════════════════════════════════════════════════════════════════════
    // 3. Les quatre gardes de publication — chacune dans les deux sens
    // ═════════════════════════════════════════════════════════════════════════════

    public function test_l_habilitation_est_exigee_pour_rediger_et_pour_publier(): void
    {
        $this->seed(ProtocoleSeeder::class);
        $version = $this->versionDeTriage();
        $this->validerQuatreFois($version);

        $this->expectException(ProtocoleException::class);
        $this->expectExceptionMessageMatches('/protocole\.publier/');

        $this->gouvernance()->publier($version, $this->agent());
    }

    /** GARDE 1 — les quatre validations du §7, et le refus NOMME celle qui manque. */
    public function test_publier_sans_les_quatre_validations_refuse_en_nommant_la_manquante(): void
    {
        $this->seed(ProtocoleSeeder::class);
        $version = $this->versionDeTriage();

        $relecteur = $this->relecteur();

        foreach (['clinique', 'reglementaire', 'scientifique'] as $type) {
            $this->gouvernance()->valider($version, $relecteur, $type, 'favorable', 'Relecteur');
        }

        try {
            $this->gouvernance()->publier($version->refresh(), $this->agent(ServiceGouvernanceProtocole::PERMISSION_PUBLIER));
            $this->fail('La publication aurait dû être refusée : la validation technique manque.');
        } catch (ProtocoleException $e) {
            // ═══ ON VÉRIFIE LE MOTIF, PAS SEULEMENT LE REFUS ═══
            //
            // Un refus pour la mauvaise raison ne prouve rien — c'est le défaut trouvé au G2 de
            // P6.5b (le contrôle de révocation était inatteignable et le test passait quand même)
            // et le piège du quatre-yeux relevé en P6.8e.
            $this->assertStringContainsString('technique', $e->getMessage());
            $this->assertSame(409, $e->statut);
        }

        $this->assertSame(ProtocoleVersion::BROUILLON, $version->refresh()->etat);
    }

    public function test_un_avis_defavorable_bloque_la_publication(): void
    {
        $this->seed(ProtocoleSeeder::class);
        $version = $this->versionDeTriage();
        $relecteur = $this->relecteur();

        foreach (['clinique', 'reglementaire', 'scientifique'] as $type) {
            $this->gouvernance()->valider($version, $relecteur, $type, 'favorable', 'Relecteur');
        }
        $this->gouvernance()->valider($version, $relecteur, 'technique', 'defavorable', 'Ingénieur');

        try {
            $this->gouvernance()->publier($version->refresh(), $this->agent(ServiceGouvernanceProtocole::PERMISSION_PUBLIER));
            $this->fail('Un avis défavorable aurait dû bloquer la publication.');
        } catch (ProtocoleException $e) {
            $this->assertStringContainsString('non favorable', $e->getMessage());
        }
    }

    /** GARDE 2 — quatre-yeux (§10), refusé PAR SON MOTIF. */
    public function test_le_redacteur_ne_peut_pas_publier_sa_propre_version(): void
    {
        $this->seed(ProtocoleSeeder::class);

        $auteur = $this->agent(
            ServiceGouvernanceProtocole::PERMISSION_REDIGER,
            ServiceGouvernanceProtocole::PERMISSION_PUBLIER,
        );

        $protocole = Protocole::query()->where('code', 'PROT-CI-HTA-SUIVI')->firstOrFail();
        $version = $protocole->versions()->firstOrFail();

        // Le brouillon du seeder n'a pas de rédacteur ; on en ouvre un vrai pour éprouver la garde.
        $version->delete();

        $neuve = $this->gouvernance()->ouvrirBrouillon($protocole, $auteur, '2026.2', 'Version rédigée par un agent.', [
            'niveau_preuve' => 'C', 'population' => 'Adultes',
        ]);
        $neuve->references()->create(['type' => 'publication', 'libelle' => 'Référence de test']);
        $this->validerQuatreFois($neuve);

        try {
            $this->gouvernance()->publier($neuve->refresh(), $auteur);
            $this->fail('Le rédacteur ne doit pas pouvoir publier sa propre version.');
        } catch (ProtocoleException $e) {
            $this->assertStringContainsString('double validation', $e->getMessage());
            $this->assertSame(409, $e->statut);
        }
    }

    /**
     * GARDE 3 — ANTI-SUBSTITUTION. Le vecteur le plus important de la gouvernance.
     *
     * Sans lui, il suffirait de faire signer un texte anodin puis d'en changer les seuils avant
     * publication : on mettrait en vigueur des règles cliniques que **personne n'a relues**.
     */
    public function test_modifier_le_contenu_apres_relecture_rend_les_validations_caduques(): void
    {
        $this->seed(ProtocoleSeeder::class);
        $version = $this->versionDeTriage();

        $this->validerQuatreFois($version);

        // ═══ LA MODIFICATION EST VOLONTAIREMENT *VALIDE* ═══
        //
        // La bande haute cesse d'envoyer aux urgences et renvoie « faible priorité ». Le contenu
        // reste parfaitement publiable — couverture complète, aucun recouvrement, message présent :
        // les contrôles techniques du §7.4 n'y verraient rien. **Seule l'anti-substitution peut
        // l'arrêter**, et c'est exactement le scénario qu'elle existe pour couvrir : faire signer
        // un texte anodin, puis en changer la conclusion clinique avant publication.
        //
        // Premier jet : la bande était élargie à [40, 100], ce qui créait un recouvrement — le
        // contrôle qualité refusait AVANT l'anti-substitution, et le vecteur passait pour la
        // mauvaise raison. Vérifier un refus par son MOTIF l'a révélé (leçon P6.5b et P6.8e).
        $version->regles()->where('libelle', 'Score de 76 à 100 : Urgence')
            ->firstOrFail()
            ->actions()
            ->where('type', RegistreActionsProtocole::DEFINIR_NIVEAU)
            ->firstOrFail()
            ->update(['valeur_json' => [NiveauTriage::FAIBLE]]);

        try {
            $this->gouvernance()->publier($version->refresh(), $this->agent(ServiceGouvernanceProtocole::PERMISSION_PUBLIER));
            $this->fail('Un contenu modifié depuis la relecture ne doit pas être publiable.');
        } catch (ProtocoleException $e) {
            $this->assertStringContainsString('modifié depuis sa relecture', $e->getMessage());
            $this->assertStringContainsString('personne n\'a relu', $e->getMessage());
            $this->assertSame(409, $e->statut);
        }

        $this->assertSame(ProtocoleVersion::BROUILLON, $version->refresh()->etat);
    }

    public function test_re_signer_apres_modification_permet_de_publier(): void
    {
        $this->seed(ProtocoleSeeder::class);
        $version = $this->versionDeTriage();

        $this->validerQuatreFois($version);

        // Une modification RÉELLE du contenu — sinon l'empreinte ne bougerait pas et le vecteur
        // prouverait qu'on sait publier un texte inchangé, pas qu'on sait re-signer.
        $version->regles()->where('libelle', 'Score de 51 à 75 : Consultation rapide')
            ->firstOrFail()
            ->actions()
            ->where('type', RegistreActionsProtocole::MESSAGE)
            ->firstOrFail()
            ->update(['valeur_json' => ['Consultez dans les 12 heures.']]);

        // Le contenu a bougé puis a été re-signé : la publication redevient possible. La garde
        // n'est pas un mur, c'est une exigence de fraîcheur.
        $this->validerQuatreFois($version->refresh());

        $publiee = $this->gouvernance()->publier(
            $version->refresh(),
            $this->agent(ServiceGouvernanceProtocole::PERMISSION_PUBLIER),
        );

        $this->assertSame(ProtocoleVersion::ACTIF, $publiee->etat);
    }

    /** GARDE 4 — les contrôles techniques du §7.4. */
    public function test_un_fait_inconnu_est_refuse_a_la_publication(): void
    {
        $this->seed(ProtocoleSeeder::class);
        $version = $this->versionDeTriage();

        $version->regles()->where('ordre', 1)->firstOrFail()
            ->conditions()->firstOrFail()->update(['fait' => 'temperature', 'operateur' => '>']);

        $this->validerQuatreFois($version->refresh());

        try {
            $this->gouvernance()->publier($version->refresh(), $this->agent(ServiceGouvernanceProtocole::PERMISSION_PUBLIER));
            $this->fail('Un fait inconnu doit être refusé à la publication, pas découvert au premier patient.');
        } catch (ProtocoleException $e) {
            $this->assertSame(422, $e->statut);
            $this->assertStringContainsString('temperature', implode(' ', $e->details));
        }
    }

    /**
     * LE CONTRÔLE DE COUVERTURE — le seul défaut de cette famille qui ne fait aucun bruit.
     *
     * Un trou entre deux bandes se publierait sans erreur, et n'apparaîtrait qu'au premier patient
     * dont le score tombe dedans.
     */
    public function test_un_trou_dans_les_bandes_de_score_est_refuse(): void
    {
        $this->seed(ProtocoleSeeder::class);
        $version = $this->versionDeTriage();

        // La bande basse s'arrête à 20 au lieu de 25 : rien ne couvre 21 à 25.
        $version->regles()->where('libelle', 'Score de 0 à 25 : Faible priorité')
            ->firstOrFail()->conditions()->firstOrFail()->update(['valeur_json' => [0, 20]]);

        $this->validerQuatreFois($version->refresh());

        try {
            $this->gouvernance()->publier($version->refresh(), $this->agent(ServiceGouvernanceProtocole::PERMISSION_PUBLIER));
            $this->fail('Un trou dans les bandes de score doit être refusé.');
        } catch (ProtocoleException $e) {
            $this->assertSame(422, $e->statut);
            $this->assertStringContainsString('Trou dans les bandes', implode(' ', $e->details));
        }
    }

    public function test_un_recouvrement_de_bandes_est_refuse(): void
    {
        $this->seed(ProtocoleSeeder::class);
        $version = $this->versionDeTriage();

        $version->regles()->where('libelle', 'Score de 26 à 50 : Consultation recommandée')
            ->firstOrFail()->conditions()->firstOrFail()->update(['valeur_json' => [20, 50]]);

        $this->validerQuatreFois($version->refresh());

        try {
            $this->gouvernance()->publier($version->refresh(), $this->agent(ServiceGouvernanceProtocole::PERMISSION_PUBLIER));
            $this->fail('Deux règles ne peuvent pas décider du même score.');
        } catch (ProtocoleException $e) {
            $this->assertStringContainsString('Recouvrement', implode(' ', $e->details));
        }
    }

    /** Un niveau sans consigne laisse le citoyen devant une couleur et un mot (CDC_05 §5.3). */
    public function test_une_regle_de_niveau_sans_message_est_refusee(): void
    {
        $this->seed(ProtocoleSeeder::class);
        $version = $this->versionDeTriage();

        $version->regles()->where('libelle', 'Score de 0 à 25 : Faible priorité')
            ->firstOrFail()->actions()->where('type', RegistreActionsProtocole::MESSAGE)->delete();

        $this->validerQuatreFois($version->refresh());

        try {
            $this->gouvernance()->publier($version->refresh(), $this->agent(ServiceGouvernanceProtocole::PERMISSION_PUBLIER));
            $this->fail('Un niveau sans consigne doit être refusé.');
        } catch (ProtocoleException $e) {
            $this->assertStringContainsString('sans dire au patient quoi faire', implode(' ', $e->details));
        }
    }

    public function test_une_version_sans_reference_bibliographique_est_refusee(): void
    {
        $this->seed(ProtocoleSeeder::class);
        $version = $this->versionDeTriage();
        $version->references()->delete();

        $this->validerQuatreFois($version->refresh());

        try {
            $this->gouvernance()->publier($version->refresh(), $this->agent(ServiceGouvernanceProtocole::PERMISSION_PUBLIER));
            $this->fail('Une recommandation clinique sans source ne doit pas être publiable.');
        } catch (ProtocoleException $e) {
            $this->assertStringContainsString('sans source', implode(' ', $e->details));
        }
    }

    // ═════════════════════════════════════════════════════════════════════════════
    // 4. La décision N3 — aucun protocole thérapeutique n'est applicable
    // ═════════════════════════════════════════════════════════════════════════════

    /**
     * LE VECTEUR QUI TIENT LA DÉCISION N3 PAR UN TEST, PAS PAR UNE INTENTION.
     *
     * Les protocoles thérapeutiques sont seedés en brouillon sans validation. §1.6 — « aucun
     * protocole utilisable sans validation » — devient ainsi un comportement prouvable.
     */
    public function test_aucun_protocole_therapeutique_n_est_publie_ni_applicable(): void
    {
        $this->seed(ProtocoleSeeder::class);

        $therapeutiques = Protocole::query()
            ->where('domaine', '!=', Protocole::DOMAINE_TRIAGE)
            ->get();

        $this->assertGreaterThan(0, $therapeutiques->count(), 'Le jeu de démonstration doit en contenir');

        foreach ($therapeutiques as $protocole) {
            $this->assertNull(
                $protocole->versionActive(),
                "« {$protocole->code} » ne doit avoir aucune version en vigueur (décision G1 N3)",
            );

            $this->assertSame(
                0,
                ProtocoleValidation::query()
                    ->whereIn('version_id', $protocole->versions()->pluck('id'))
                    ->count(),
                "Aucune validation ne doit être fabriquée pour « {$protocole->code} » : le §7 la "
                .'veut opposable, et une signature clinique inventée serait une pièce fausse',
            );

            // ═══ LE REFUS EST VÉRIFIÉ PAR SON MOTIF, PAS PAR SON CODE ═══
            //
            // Premier jet : on n'assertait que le 404. La mutation l'a démasqué — en neutralisant
            // le refus délibéré, le 404 continuait d'arriver, produit cette fois par un
            // `firstOrFail` sur une version introuvable. Le vecteur survivait donc en prouvant
            // **autre chose que la garde visée**.
            //
            // Troisième instance de la même leçon, après le contrôle de révocation de P6.5b et le
            // quatre-yeux de P6.8e : *un refus pour la mauvaise raison ne prouve rien.*
            $this->getJson('/api/v1/protocoles/'.$protocole->code)
                ->assertStatus(404)
                ->assertJsonFragment(['code' => 'PROTOCOLE_REFUS'])
                ->assertSee('n\'a aucune version en vigueur', escape: false);
        }
    }

    /** Aucune attribution n'est forgée : aucun protocole ne se réclame d'une autorité non consultée. */
    public function test_aucun_protocole_ne_se_reclame_d_une_autorite_non_consultee(): void
    {
        $this->seed(ProtocoleSeeder::class);

        foreach (Protocole::all() as $protocole) {
            $this->assertStringContainsString(
                'Source non fournie',
                (string) $protocole->organisme,
                "« {$protocole->code} » ne doit citer aucune autorité tant qu'aucun document n'a été vu",
            );

            $this->assertNull($protocole->auteur);
        }
    }

    // ═════════════════════════════════════════════════════════════════════════════
    // 5. Audit et traçabilité (§10)
    // ═════════════════════════════════════════════════════════════════════════════

    public function test_le_journal_enchaine_et_detecte_une_alteration(): void
    {
        $this->publierProtocoleDeTriage();

        $journal = app(JournalProtocole::class);

        $this->assertTrue($journal->verifierChaine()['intacte']);

        // On réécrit le nom d'un acteur en base — c'est ce qu'un humain lit dans un audit, et le
        // test d'altération de P6.3 avait montré qu'il fallait le faire entrer dans l'empreinte.
        $entree = ProtocoleJournal::query()->where('action', ProtocoleJournal::PUBLICATION)->firstOrFail();
        DB::table('protocole_journal')->where('id', $entree->id)->update(['acteur_nom' => 'Système']);

        $verification = $journal->verifierChaine();

        $this->assertFalse($verification['intacte']);
        $this->assertSame('CONTENU', $verification['rupture']['type']);
    }

    public function test_le_journal_ne_contient_aucun_contenu_clinique(): void
    {
        $this->publierProtocoleDeTriage();

        $journal = ProtocoleJournal::all()->toJson();

        // Le journal de gouvernance dit QUI a fait QUOI ; l'instantané de la version porte ce qui
        // a changé. Deux copies du contenu seraient deux vérités (règle établie en P6.3).
        foreach (['artémisinine', 'Consultez', 'urgences', 'SAMU'] as $fragment) {
            $this->assertStringNotContainsString($fragment, $journal);
        }
    }

    public function test_une_version_publiee_est_scellee(): void
    {
        $version = $this->publierProtocoleDeTriage();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/scellée/');

        $version->update(['contenu_json' => ['regles' => []]]);
    }

    public function test_une_validation_ne_se_modifie_ni_ne_se_supprime(): void
    {
        $this->seed(ProtocoleSeeder::class);
        $version = $this->versionDeTriage();

        $validation = $this->gouvernance()->valider(
            $version, $this->relecteur(), 'clinique', 'favorable', 'Médecin spécialiste',
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/opposable/');

        $validation->update(['avis' => 'defavorable']);
    }

    /** La dernière validation d'un type fait foi ; les précédentes racontent l'histoire (§7). */
    public function test_la_derniere_validation_d_un_type_fait_foi_sans_effacer_les_precedentes(): void
    {
        $this->seed(ProtocoleSeeder::class);
        $version = $this->versionDeTriage();
        $relecteur = $this->relecteur();

        $this->gouvernance()->valider($version, $relecteur, 'clinique', 'defavorable', 'Médecin');
        $this->gouvernance()->valider($version, $relecteur, 'clinique', 'favorable', 'Médecin');

        $this->assertSame(2, $version->validations()->where('type', 'clinique')->count());
        $this->assertSame('favorable', $version->refresh()->validationsCourantes()['clinique']->avis);
    }

    // ═════════════════════════════════════════════════════════════════════════════
    // 6. Le message vient du protocole, le numéro du référentiel
    // ═════════════════════════════════════════════════════════════════════════════

    public function test_le_marqueur_d_urgence_est_resolu_au_referentiel_national(): void
    {
        $this->preparerTriageComplet();

        $texte = $this->analyser([
            'symptomes' => [$this->symptome('Convulsions')->id],
        ])->json('recommandation_texte');

        // La consigne vient du protocole, le numéro du référentiel (P6.8e) : ni l'un ni l'autre
        // n'est en dur, et le marqueur ne doit jamais atteindre l'écran d'un patient.
        $this->assertStringContainsString('185', $texte);
        $this->assertStringNotContainsString('{urgence', $texte);
        $this->assertStringContainsString('urgences', $texte);
    }

    // ═════════════════════════════════════════════════════════════════════════════
    // 7. Le code ne porte plus de seuil de niveau
    // ═════════════════════════════════════════════════════════════════════════════

    /**
     * AUCUNE COMPARAISON DE SCORE NE DÉCIDE PLUS D'UN NIVEAU DANS `TriageService`.
     *
     * Vecteur de non-régression littéral : il lit le fichier. Il ne remplace pas les vecteurs de
     * comportement au-dessus — il attrape le cas où quelqu'un réintroduirait un repli « au cas
     * où », qui ne se verrait dans aucun test fonctionnel tant que le protocole est publié.
     */
    public function test_le_service_de_triage_ne_contient_plus_aucun_seuil_de_niveau(): void
    {
        // ═══ LE TEST PORTE SUR LE CODE, PAS SUR LA PROSE ═══
        //
        // Premier jet : il lisait le fichier entier et tombait sur le commentaire qui EXPLIQUE la
        // suppression de `niveauDepuisScore()`. Il aurait donc interdit de documenter ce qu'on
        // venait de retirer — c'est-à-dire encouragé à effacer la trace du défaut plutôt qu'à la
        // conserver. Les commentaires sont écartés avant l'analyse.
        $source = $this->codeSeul(app_path('Services/TriageService.php'));

        foreach (NiveauTriage::PATIENT as $niveau) {
            $this->assertStringNotContainsString(
                "'{$niveau}'",
                $source,
                "TriageService ne doit citer aucun niveau en dur — « {$niveau} » y est réapparu",
            );
        }

        foreach (NiveauTriage::HERITES as $niveau) {
            $this->assertStringNotContainsString("=> '{$niveau}'", $source);
        }

        $this->assertStringNotContainsString('niveauDepuisScore', $source);
        $this->assertStringNotContainsString('max($score, 90)', $source);
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // Utilitaire — recopie les règles d'une version dans une autre
    // ─────────────────────────────────────────────────────────────────────────────

    /** Le contenu d'un fichier PHP, commentaires et docblocks retirés. */
    private function codeSeul(string $chemin): string
    {
        $garde = [];

        foreach (token_get_all(file_get_contents($chemin)) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $garde[] = is_array($token) ? $token[1] : $token;
        }

        return implode('', $garde);
    }

    /**
     * Recopie l'instantané d'une version publiée dans un brouillon, en laissant l'appelant
     * transformer chaque règle au passage.
     *
     * C'est ce que fera l'écran d'authoring de P10b-3 : on ne repart jamais d'une page blanche
     * pour corriger un seuil.
     */
    private function recopierRegles(ProtocoleVersion $source, ProtocoleVersion $cible, ?callable $transformer = null): void
    {
        $contenu = $source->contenu_json
            ?? app(CompilateurProtocole::class)->extraire($source);

        foreach ($contenu['regles'] as $regle) {
            $regle = $transformer !== null ? $transformer($regle) : $regle;

            $nouvelle = $cible->regles()->create([
                'ordre' => $regle['ordre'],
                'libelle' => $regle['libelle'],
            ]);

            foreach ($regle['conditions'] as $i => $condition) {
                $nouvelle->conditions()->create([
                    'ordre' => $i + 1,
                    'fait' => $condition['fait'],
                    'operateur' => $condition['operateur'],
                    'valeur_json' => is_array($condition['valeur']) ? $condition['valeur'] : [$condition['valeur']],
                ]);
            }

            foreach ($regle['actions'] as $i => $action) {
                $nouvelle->actions()->create([
                    'ordre' => $i + 1,
                    'type' => $action['type'],
                    'valeur_json' => is_array($action['valeur']) ? $action['valeur'] : [$action['valeur']],
                    'justification' => $action['justification'] ?? null,
                ]);
            }
        }

        foreach ($contenu['references'] as $reference) {
            $cible->references()->create([
                'type' => $reference['type'],
                'libelle' => $reference['libelle'],
                'url' => $reference['url'] ?? null,
                'citation' => $reference['citation'] ?? null,
            ]);
        }
    }
}
