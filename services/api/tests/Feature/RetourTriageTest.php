<?php

namespace Tests\Feature;

use App\Models\AccesDossier;
use App\Models\Medecin;
use App\Models\MembreFamille;
use App\Models\ProtocoleApplication;
use App\Models\ServiceEtablissement;
use App\Models\StructureSanitaire;
use App\Models\Triage;
use App\Models\User;
use App\Services\Protocole\JournalApplicationProtocole;
use App\Services\SessionDossierService;
use App\Services\Triage\ServiceRetourTriage;
use App\Support\NiveauTriage;
use App\Support\RegistreRetourTriage;
use Database\Seeders\PortailRolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * P10c-2-i (partie A) — le retour du soignant sur l'orientation (CDC_05 §5.5.4, §9.1 ; CDC_08 §10).
 *
 * CE QUE CETTE SUITE PROTÈGE. Le G0 a montré (constat Y6) que le chaînon d'apprentissage du §5.5.4
 * n'existait qu'à moitié : quatre tables du carnet portent `triage_id`, mais rien ne le posait sur
 * le chemin du soignant. Cette suite garde les deux propriétés qui font la valeur du lien :
 *
 *   1. il est DÉCLARÉ, jamais deviné — un lien inventé produirait une base d'apprentissage fausse,
 *      c'est-à-dire le pire résultat possible ;
 *   2. le label est fermé et journalisé — sans quoi le jeu d'apprentissage se peuplerait
 *      d'étiquettes libres incomparables entre elles.
 *
 * Écrite dans les DEUX SENS : ce que le retour permet, et tout ce qu'il refuse. Chaque garde a son
 * vecteur, car aucune ne rattrape les autres.
 */
class RetourTriageTest extends TestCase
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

    /** @return array{0: User, 1: MembreFamille} */
    private function famille(): array
    {
        $parent = User::factory()->create();

        return [$parent, MembreFamille::factory()->for($parent)->create()];
    }

    /**
     * Le niveau vient de {@see NiveauTriage} et non d'une chaîne écrite ici : la colonne porte une
     * contrainte, et un littéral inventé ferait échouer la création du triage AVANT la garde que
     * le vecteur prétend éprouver — le piège « le vecteur prouve autre chose », en plus sournois
     * puisqu'il se manifeste par une erreur et non par un faux vert.
     */
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

    private function service(): ServiceRetourTriage
    {
        return app(ServiceRetourTriage::class);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Ce que la boucle ouvre
    // ─────────────────────────────────────────────────────────────────────────

    public function test_un_soignant_habilite_enregistre_un_retour(): void
    {
        [, $membre] = $this->famille();
        $triage = $this->triagePour($membre);

        $entree = $this->service()->enregistrer(
            $this->soignant(), $membre, $triage, RegistreRetourTriage::ADAPTEE
        );

        $this->assertSame(RegistreRetourTriage::ADAPTEE, $entree->decision_finale);
        $this->assertSame($triage->id, $entree->triage_id);
        $this->assertSame($membre->id, $entree->membre_id);
    }

    public function test_un_ecart_est_enregistre_avec_son_motif(): void
    {
        [, $membre] = $this->famille();
        $triage = $this->triagePour($membre);

        $entree = $this->service()->enregistrer(
            $this->soignant(), $membre, $triage,
            RegistreRetourTriage::SOUS_TRIAGE,
            'Détresse respiratoire non signalée au questionnaire.',
        );

        $this->assertSame(RegistreRetourTriage::SOUS_TRIAGE, $entree->decision_finale);
        $this->assertSame(
            'Détresse respiratoire non signalée au questionnaire.',
            $entree->ecart_justification
        );
    }

    /**
     * `professionnel_id` et `user_id` sont DEUX identifiants distincts, comme la migration du §10
     * le dit. La fiche désigne le praticien au référentiel (P6.5a), le compte désigne qui a agi.
     */
    public function test_le_retour_porte_la_fiche_professionnelle_et_le_compte(): void
    {
        [, $membre] = $this->famille();
        $triage = $this->triagePour($membre);
        $soignant = $this->soignant();

        $service = ServiceEtablissement::create([
            'structure_id' => $soignant->structure_id, 'nom_service' => 'Cardiologie',
            'specialite' => 'cardiologie', 'actif' => true,
        ]);

        $fiche = Medecin::create([
            'structure_id' => $soignant->structure_id, 'service_id' => $service->id,
            'user_id' => $soignant->id,
            'titre' => 'Dr', 'prenom' => 'Aya', 'nom' => 'Koffi', 'specialite' => 'Cardiologie',
            'profession' => 'medecin_specialiste', 'actif' => true,
        ]);

        $entree = $this->service()->enregistrer(
            $soignant, $membre, $triage, RegistreRetourTriage::ADAPTEE
        );

        $this->assertSame($fiche->id, $entree->professionnel_id);
        $this->assertSame($soignant->id, $entree->user_id);
    }

    /** Un praticien habilité mais sans fiche reste identifié par son compte. */
    public function test_un_soignant_sans_fiche_professionnelle_reste_identifie(): void
    {
        [, $membre] = $this->famille();
        $soignant = $this->soignant();

        $entree = $this->service()->enregistrer(
            $soignant, $membre, $this->triagePour($membre), RegistreRetourTriage::ADAPTEE
        );

        $this->assertNull($entree->professionnel_id);
        $this->assertSame($soignant->id, $entree->user_id);
    }

    /**
     * Le journal est append-only : un praticien qui se ravise AJOUTE, il ne corrige pas. Refuser le
     * second retour figerait un avis rendu à la hâte — P10b-1 a payé ce prix dans l'autre sens.
     */
    public function test_un_second_retour_ajoute_une_entree_sans_effacer_la_premiere(): void
    {
        [, $membre] = $this->famille();
        $triage = $this->triagePour($membre);
        $soignant = $this->soignant();

        $premier = $this->service()->enregistrer(
            $soignant, $membre, $triage, RegistreRetourTriage::ADAPTEE
        );
        $this->service()->enregistrer(
            $soignant, $membre, $triage, RegistreRetourTriage::SOUS_TRIAGE, 'Aggravation constatée.'
        );

        $retours = $this->service()->retoursDe($triage);

        $this->assertCount(2, $retours);
        $this->assertSame(RegistreRetourTriage::ADAPTEE, $retours->first()->decision_finale);
        $this->assertSame(RegistreRetourTriage::SOUS_TRIAGE, $retours->last()->decision_finale);
        // Le premier avis n'a pas été réécrit : un avis retiré reste une information.
        $this->assertSame(
            RegistreRetourTriage::ADAPTEE,
            $premier->fresh()->decision_finale
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Ce que la boucle refuse — une garde, un vecteur
    // ─────────────────────────────────────────────────────────────────────────

    public function test_un_compte_non_habilite_est_refuse(): void
    {
        [, $membre] = $this->famille();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/habilité/');

        $this->service()->enregistrer(
            $this->soignant(habilite: false), $membre, $this->triagePour($membre),
            RegistreRetourTriage::ADAPTEE
        );
    }

    /**
     * ANTI-IDOR. Le membre vient de la session, mais le triage vient du formulaire : sans cette
     * garde, un soignant annoterait depuis le dossier qu'on lui a ouvert le triage de n'importe qui.
     */
    public function test_un_triage_d_un_autre_patient_est_refuse(): void
    {
        [, $membre] = $this->famille();
        [, $autre] = $this->famille();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/dossier ouvert/');

        $this->service()->enregistrer(
            $this->soignant(), $membre, $this->triagePour($autre), RegistreRetourTriage::ADAPTEE
        );
    }

    /** Un triage anonyme n'appartient à aucun dossier : il ne peut être annoté depuis aucun. */
    public function test_un_triage_anonyme_est_refuse(): void
    {
        [, $membre] = $this->famille();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/dossier ouvert/');

        $this->service()->enregistrer(
            $this->soignant(), $membre, $this->triagePour(null), RegistreRetourTriage::ADAPTEE
        );
    }

    /**
     * `decision_finale` est un `string(200)` QUI ENTRE DANS L'EMPREINTE de la chaîne. Sans liste
     * blanche, du texte libre s'inscrirait dans un journal immuable.
     */
    public function test_une_valeur_hors_liste_blanche_est_refusee(): void
    {
        [, $membre] = $this->famille();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Retour inconnu/');

        $this->service()->enregistrer(
            $this->soignant(), $membre, $this->triagePour($membre), 'plutot_bien'
        );
    }

    public function test_un_ecart_sans_motif_est_refuse(): void
    {
        [, $membre] = $this->famille();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches("/n'a pas vu/");

        $this->service()->enregistrer(
            $this->soignant(), $membre, $this->triagePour($membre), RegistreRetourTriage::SUR_TRIAGE
        );
    }

    /** Un motif fait d'espaces n'est pas un motif. */
    public function test_un_ecart_dont_le_motif_est_vide_est_refuse(): void
    {
        [, $membre] = $this->famille();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches("/n'a pas vu/");

        $this->service()->enregistrer(
            $this->soignant(), $membre, $this->triagePour($membre),
            RegistreRetourTriage::SOUS_TRIAGE, '   '
        );
    }

    /**
     * On ne justifie pas un accord — et on ne conserve pas « au cas où » du texte clinique libre
     * dans une chaîne immuable que rien n'exige de remplir.
     */
    public function test_une_justification_sur_un_accord_n_est_pas_conservee(): void
    {
        [, $membre] = $this->famille();

        $entree = $this->service()->enregistrer(
            $this->soignant(), $membre, $this->triagePour($membre),
            RegistreRetourTriage::ADAPTEE, 'Le patient allait bien.'
        );

        $this->assertNull($entree->ecart_justification);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Les garanties du journal — ce que le retour ne doit PAS faire
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * L'entrée de retour ne recopie AUCUN protocole. Le recopier en ferait une seconde vérité, et
     * ferait surtout diverger l'empreinte : `charge()` relit le libellé de version DANS
     * `protocoles_json`, qui est vide ici.
     */
    public function test_l_entree_de_retour_ne_recopie_aucun_protocole(): void
    {
        [, $membre] = $this->famille();

        $entree = $this->service()->enregistrer(
            $this->soignant(), $membre, $this->triagePour($membre), RegistreRetourTriage::ADAPTEE
        );

        $this->assertNull($entree->protocole_retenu_code);
        $this->assertNull($entree->protocole_retenu_version);
        $this->assertSame([], $entree->protocoles_json);
        $this->assertSame([], $entree->recommandations_json);
    }

    /** Le vecteur central : la chaîne du §10 reste vérifiable après un retour. */
    public function test_la_chaine_d_audit_reste_intacte_apres_un_retour(): void
    {
        [, $membre] = $this->famille();
        $soignant = $this->soignant();
        $triage = $this->triagePour($membre);

        $this->service()->enregistrer($soignant, $membre, $triage, RegistreRetourTriage::ADAPTEE);
        $this->service()->enregistrer(
            $soignant, $membre, $triage, RegistreRetourTriage::SUR_TRIAGE, 'Fièvre isolée, sans gravité.'
        );

        $verdict = app(JournalApplicationProtocole::class)->verifierChaine();

        $this->assertTrue($verdict['intacte'], 'La chaîne du §10 doit rester intacte après un retour.');
        $this->assertSame(2, $verdict['entrees']);
    }

    /**
     * Une entrée de retour se reconnaît par `decision_finale IS NOT NULL` — jamais par une colonne
     * nouvelle, qui aurait changé la charge hachée et invalidé tout l'historique.
     */
    public function test_un_retour_se_distingue_par_sa_decision_finale(): void
    {
        [, $membre] = $this->famille();

        $this->service()->enregistrer(
            $this->soignant(), $membre, $this->triagePour($membre), RegistreRetourTriage::ADAPTEE
        );

        $this->assertSame(1, ProtocoleApplication::whereNotNull('decision_finale')->count());
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Le lien de consultation — déclaré, jamais deviné (décision F1)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * LE VECTEUR QUI PROUVE F1. Deux triages existent ; le soignant se prononce sur le PREMIER.
     * Un rattachement « au plus récent » — la déduction que ce projet refuse partout — désignerait
     * le second. Le lien doit suivre ce qui a été DÉCLARÉ.
     */
    public function test_le_lien_suit_le_triage_declare_et_non_le_plus_recent(): void
    {
        [, $membre] = $this->famille();
        $soignant = $this->soignant();

        $premier = $this->triagePour($membre);
        $this->triagePour($membre); // plus récent, et jamais désigné

        $session = app(SessionDossierService::class);
        $session->ouvrir(AccesDossier::create([
            'membre_id' => $membre->id, 'agent_id' => $soignant->id, 'type_acces' => 'qr_scan',
        ]));

        $this->service()->enregistrer($soignant, $membre, $premier, RegistreRetourTriage::ADAPTEE);
        $session->noterTriage($premier->id);
        $session->fermer('manuelle');

        $this->assertSame($premier->id, AccesDossier::latest('id')->first()->triage_id);
    }

    /** Sans déclaration, aucun lien : le serveur ne devine pas pourquoi le patient est là. */
    public function test_une_consultation_sans_declaration_ne_porte_aucun_lien(): void
    {
        [, $membre] = $this->famille();
        $soignant = $this->soignant();
        $this->triagePour($membre);

        $session = app(SessionDossierService::class);
        $session->ouvrir(AccesDossier::create([
            'membre_id' => $membre->id, 'agent_id' => $soignant->id, 'type_acces' => 'qr_scan',
        ]));
        $session->fermer('manuelle');

        $this->assertNull(AccesDossier::latest('id')->first()->triage_id);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // La chaîne HTTP du portail
    // ─────────────────────────────────────────────────────────────────────────

    public function test_le_portail_enregistre_un_retour_et_pose_le_lien(): void
    {
        [, $membre] = $this->famille();
        $soignant = $this->soignant();
        $soignant->assignRole('medecin');
        $triage = $this->triagePour($membre);

        app(SessionDossierService::class)->ouvrir(AccesDossier::create([
            'membre_id' => $membre->id, 'agent_id' => $soignant->id, 'type_acces' => 'qr_scan',
        ]));

        $this->actingAs($soignant)
            ->post(route('portail.dossier.triage.retour', $triage->id), [
                'retour' => RegistreRetourTriage::SOUS_TRIAGE,
                'justification' => 'Saturation basse constatée à l\'examen.',
            ])
            ->assertRedirect(route('portail.dossier.section', 'triage'));

        $this->assertSame($triage->id, app(SessionDossierService::class)->triageDeclare());
        $this->assertSame(
            RegistreRetourTriage::SOUS_TRIAGE,
            ProtocoleApplication::whereNotNull('decision_finale')->first()->decision_finale
        );
    }

    /**
     * Un refus ne doit pas laisser derrière lui une consultation rattachée à un triage sur lequel
     * rien n'a finalement été dit.
     */
    public function test_un_retour_refuse_ne_pose_pas_le_lien(): void
    {
        [, $membre] = $this->famille();
        $soignant = $this->soignant();
        $soignant->assignRole('medecin');
        $triage = $this->triagePour($membre);

        app(SessionDossierService::class)->ouvrir(AccesDossier::create([
            'membre_id' => $membre->id, 'agent_id' => $soignant->id, 'type_acces' => 'qr_scan',
        ]));

        // Un écart sans motif : refusé par le service.
        $this->actingAs($soignant)
            ->post(route('portail.dossier.triage.retour', $triage->id), [
                'retour' => RegistreRetourTriage::SUR_TRIAGE,
            ])
            ->assertSessionHasErrors('retour');

        $this->assertNull(app(SessionDossierService::class)->triageDeclare());
        $this->assertSame(0, ProtocoleApplication::whereNotNull('decision_finale')->count());
    }
}
