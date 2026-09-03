<?php

namespace Tests\Feature;

use App\Models\AccesDossier;
use App\Models\Consultation;
use App\Models\Medecin;
use App\Models\MembreFamille;
use App\Models\ServiceEtablissement;
use App\Models\StructureSanitaire;
use App\Models\User;
use App\Services\ServiceConsultation;
use App\Services\SessionDossierService;
use App\Support\StatutConsultation;
use Database\Seeders\PortailRolesSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * B2-a — la consultation (CDC_11 §5.2, CDC_04 §12 étape 7).
 *
 * CE QUE CETTE SUITE PROTÈGE. Le G0 a montré que ce projet n'avait AUCUNE entité consultation :
 * chaque écriture du soignant était isolée, une ordonnance et un antécédent écrits le même jour
 * par le même médecin ne se savaient pas liés. Trois modules validés G5 nommaient ce trou comme
 * leur propre verrou.
 *
 * Écrite dans les DEUX SENS : ce que la consultation doit permettre, et tout ce qu'elle doit
 * refuser. Les cinq gardes ont chacune leur vecteur, car aucune ne rattrape les autres — et
 * lorsqu'elles partagent un même type de refus, le vecteur vérifie le MESSAGE et pas seulement
 * l'échec (leçon de B1-d, neuvième occurrence de « le vecteur prouve autre chose »).
 */
class ConsultationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PortailRolesSeeder::class);
    }

    private function structure(): StructureSanitaire
    {
        return StructureSanitaire::create([
            'nom' => 'CHU de Cocody', 'type' => 'chu', 'adresse' => 'Abidjan', 'commune' => 'Cocody',
            'latitude' => 5.35, 'longitude' => -3.98, 'actif' => true,
        ]);
    }

    private function soignant(bool $habilite = true, ?StructureSanitaire $structure = null): User
    {
        $structure ??= $this->structure();
        $user = User::factory()->create(['structure_id' => $structure->id]);

        if ($habilite) {
            $user->givePermissionTo('dossier.ecrire');
        }

        return $user->fresh();
    }

    private function patient(): MembreFamille
    {
        return MembreFamille::factory()->for(User::factory()->create())->create();
    }

    /** Ouvre une vraie session de dossier, comme le ferait un scan de QR au guichet. */
    private function ouvrirSession(
        MembreFamille $membre,
        User $soignant,
        string $voie = 'qr_scan',
    ): AccesDossier {
        $acces = AccesDossier::create([
            'membre_id' => $membre->id,
            'agent_id' => $soignant->id,
            'type_acces' => $voie,
            'etablissement' => 'CHU de Cocody',
            'motif_urgence' => $voie === 'bris_de_glace' ? 'Patient inconscient' : null,
        ]);

        app(SessionDossierService::class)->ouvrir($acces);

        return $acces;
    }

    private function service(): ServiceConsultation
    {
        return app(ServiceConsultation::class);
    }

    /**
     * L'état de session RÉEL, tel que `SessionDossierService::ouvrir()` l'a posé.
     *
     * DÉFAUT DE MÉTHODE TROUVÉ PAR LA MUTATION : les premiers vecteurs HTTP fabriquaient cet état
     * à la main, avec les seules clés dont ils croyaient avoir besoin. Il en manquait (`sections`,
     * `ecritures`), la page rendait un 500 — et `assertSee` passait quand même, parce qu'une page
     * d'erreur Laravel affiche le code source de la pile, donc le texte cherché, qui figure dans
     * ce fichier même. Le vecteur prouvait sa propre existence.
     *
     * @return array<string, mixed>
     */
    private function sessionReelle(): array
    {
        return session('dossier_ouvert');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Ce que B2-a ouvre
    // ─────────────────────────────────────────────────────────────────────────

    public function test_un_soignant_habilite_ouvre_une_consultation(): void
    {
        $patient = $this->patient();
        $soignant = $this->soignant();
        $acces = $this->ouvrirSession($patient, $soignant);

        $consultation = $this->service()->ouvrir($soignant, 'Fièvre depuis trois jours');

        $this->assertDatabaseCount('consultations', 1);
        $this->assertSame($patient->id, $consultation->membre_id);
        $this->assertSame($acces->id, $consultation->acces_dossier_id);
        $this->assertSame(StatutConsultation::EN_COURS, $consultation->statut);
        $this->assertSame('Fièvre depuis trois jours', $consultation->motif);
        $this->assertNotNull($consultation->debutee_le);
        $this->assertNull($consultation->cloturee_le);
    }

    /**
     * LE NOM DE CELUI QUI MÈNE L'ACTE EST FIGÉ PAR LE SERVEUR, jamais déclaré. Même mouvement que
     * `medecin_nom` (P6.5a), `source`/`added_by` (P7-C, P7-D0), `provenance` (P6.8d) : ce que le
     * serveur SAIT n'a pas à être demandé à celui qu'on contrôle.
     */
    public function test_l_auteur_et_l_etablissement_viennent_de_la_fiche_professionnelle(): void
    {
        $structure = $this->structure();
        $soignant = $this->soignant(true, $structure);
        $patient = $this->patient();

        $service = ServiceEtablissement::create([
            'structure_id' => $structure->id, 'nom_service' => 'Cardiologie',
            'specialite' => 'cardiologie', 'actif' => true,
        ]);

        $fiche = Medecin::create([
            'user_id' => $soignant->id, 'structure_id' => $structure->id,
            'service_id' => $service->id,
            'nom' => 'Kablan', 'prenom' => 'Koffi', 'specialite' => 'cardiologie',
        ]);

        $this->ouvrirSession($patient, $soignant);
        $consultation = $this->service()->ouvrir($soignant);

        // `fresh()` et non l'instance en mémoire : le service RELIT la fiche en base plutôt que
        // de faire confiance à ce qu'on lui passe, donc il obtient le titre (`profession`) que la
        // colonne porte par défaut. Comparer à l'instance non rechargée testerait ma fixture.
        $this->assertSame($fiche->fresh()->nom_complet, $consultation->soignant_nom);
        $this->assertSame($fiche->id, $consultation->medecin_id);
        $this->assertSame($structure->id, $consultation->structure_id);
        $this->assertSame('CHU de Cocody', $consultation->structure_nom);
    }

    /**
     * Sans fiche professionnelle, l'acte doit quand même dire qui l'a mené. `nomLisible()` est la
     * source unique depuis P10b-1, qui a trouvé que `$user->name` n'existe pas sur ce modèle et
     * faisait écrire « Système » pour tout acteur humain dans trois journaux d'audit.
     */
    public function test_sans_fiche_professionnelle_le_nom_vient_du_compte(): void
    {
        $soignant = $this->soignant();
        $this->ouvrirSession($this->patient(), $soignant);

        $consultation = $this->service()->ouvrir($soignant);

        $this->assertSame($soignant->nomLisible(), $consultation->soignant_nom);
        $this->assertNotSame('', trim($consultation->soignant_nom));
        $this->assertNull($consultation->medecin_id);
    }

    /**
     * Le rattachement au rendez-vous et au triage vient de la SESSION, jamais du client : c'est
     * l'accueil (check-in, B1-c) et le soignant (déclaration de triage, P10c-2-i) qui les ont
     * posés en amont.
     */
    public function test_le_rattachement_au_rdv_et_au_triage_vient_de_la_session(): void
    {
        $soignant = $this->soignant();
        $patient = $this->patient();
        $this->ouvrirSession($patient, $soignant);

        $session = app(SessionDossierService::class);
        $session->noterTriage(4242);

        $consultation = $this->service()->ouvrir($soignant);

        $this->assertSame(4242, $consultation->triage_id);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Les gardes de l'ouverture — chacune son vecteur, chacune son message
    // ─────────────────────────────────────────────────────────────────────────

    public function test_sans_dossier_ouvert_aucune_consultation(): void
    {
        $soignant = $this->soignant();

        $this->attendRefus(
            fn () => $this->service()->ouvrir($soignant),
            'Aucun dossier n\'est ouvert : une consultation se mène dans un dossier.'
        );

        $this->assertDatabaseCount('consultations', 0);
    }

    public function test_un_soignant_non_habilite_ne_peut_pas_mener_de_consultation(): void
    {
        $soignant = $this->soignant(habilite: false);
        $this->ouvrirSession($this->patient(), $soignant);

        $this->attendRefus(
            fn () => $this->service()->ouvrir($soignant),
            'Vous n\'êtes pas habilité à mener une consultation.'
        );

        $this->assertDatabaseCount('consultations', 0);
    }

    /**
     * LE BRIS DE GLACE EST EXCLU, ET C'EST UNE DÉCISION, PAS UN OUBLI. Cette voie ouvre le vital
     * minimal SANS le consentement du patient (P7-D0) : y mener une consultation ferait d'un
     * accès d'exception un droit de soigner. Le soignant est ici pleinement habilité — seule la
     * voie le refuse, sinon le vecteur prouverait l'habilitation.
     */
    public function test_le_bris_de_glace_ne_permet_pas_de_mener_une_consultation(): void
    {
        $soignant = $this->soignant();
        $this->ouvrirSession($this->patient(), $soignant, 'bris_de_glace');

        $this->attendRefus(
            fn () => $this->service()->ouvrir($soignant),
            'Cet accès est en lecture seule : le patient n\'a pas consenti à une écriture.'
        );

        $this->assertDatabaseCount('consultations', 0);
    }

    public function test_un_seul_acte_par_acces_au_dossier(): void
    {
        $soignant = $this->soignant();
        $this->ouvrirSession($this->patient(), $soignant);

        $this->service()->ouvrir($soignant);

        $this->attendRefus(
            fn () => $this->service()->ouvrir($soignant),
            'Une consultation est déjà ouverte pour cet accès au dossier.'
        );

        $this->assertDatabaseCount('consultations', 1);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Les observations (Z-a : la table existe depuis le 2026-07-02, on ne la double pas)
    // ─────────────────────────────────────────────────────────────────────────

    public function test_une_observation_est_rattachee_a_la_consultation_et_au_patient(): void
    {
        $soignant = $this->soignant();
        $patient = $this->patient();
        $this->ouvrirSession($patient, $soignant);
        $consultation = $this->service()->ouvrir($soignant);

        $note = $this->service()->observer($soignant, $consultation, 'Température 39,2 °C');

        $this->assertDatabaseCount('notes_observations', 1);
        $this->assertSame($consultation->id, $note->consultation_id);
        $this->assertSame($patient->id, $note->membre_id);
        $this->assertSame('Température 39,2 °C', $note->contenu);
    }

    /**
     * Miroir exact de P7-C et P7-D0 : un soignant ne peut pas faire passer son observation pour
     * une note du patient. La provenance est une décision du serveur.
     */
    public function test_la_provenance_d_une_observation_est_reecrite_par_le_serveur(): void
    {
        $soignant = $this->soignant();
        $this->ouvrirSession($this->patient(), $soignant);
        $consultation = $this->service()->ouvrir($soignant);

        $note = $this->service()->observer($soignant, $consultation, 'Auscultation normale');

        $this->assertSame('medecin', $note->auteur_type);
        $this->assertSame($soignant->id, $note->auteur_user_id);
    }

    public function test_une_observation_vide_est_refusee(): void
    {
        $soignant = $this->soignant();
        $this->ouvrirSession($this->patient(), $soignant);
        $consultation = $this->service()->ouvrir($soignant);

        $this->attendRefus(
            fn () => $this->service()->observer($soignant, $consultation, '   '),
            'Une observation ne peut pas être vide.'
        );

        $this->assertDatabaseCount('notes_observations', 0);
    }

    /**
     * Un autre soignant, FÛT-IL HABILITÉ ET SUR UNE VOIE CONSENTIE, ne complète pas l'acte d'un
     * confrère. Les deux autres gardes sont délibérément satisfaites pour isoler celle-ci.
     */
    public function test_un_autre_soignant_ne_complete_pas_la_consultation_d_un_confrere(): void
    {
        $premier = $this->soignant();
        $patient = $this->patient();
        $this->ouvrirSession($patient, $premier);
        $consultation = $this->service()->ouvrir($premier);

        $second = $this->soignant();
        $this->ouvrirSession($patient, $second);

        $this->attendRefus(
            fn () => $this->service()->observer($second, $consultation, 'Note d\'un confrère'),
            'Cette consultation est menée par un autre soignant.'
        );

        $this->assertDatabaseCount('notes_observations', 0);
    }

    public function test_une_consultation_cloturee_n_accepte_plus_d_observation(): void
    {
        $soignant = $this->soignant();
        $this->ouvrirSession($this->patient(), $soignant);
        $consultation = $this->service()->ouvrir($soignant);
        $this->service()->cloturer($soignant, $consultation);

        $this->attendRefus(
            fn () => $this->service()->observer($soignant, $consultation->fresh(), 'Trop tard'),
            'Cette consultation est clôturée.'
        );

        $this->assertDatabaseCount('notes_observations', 0);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // La clôture
    // ─────────────────────────────────────────────────────────────────────────

    public function test_la_cloture_horodate_et_rend_l_acte_terminal(): void
    {
        $soignant = $this->soignant();
        $this->ouvrirSession($this->patient(), $soignant);
        $consultation = $this->service()->ouvrir($soignant);

        $close = $this->service()->cloturer($soignant, $consultation);

        $this->assertSame(StatutConsultation::CLOTUREE, $close->statut);
        $this->assertNotNull($close->cloturee_le);

        $this->attendRefus(
            fn () => $this->service()->cloturer($soignant, $close->fresh()),
            'Cette consultation est clôturée.'
        );
    }

    public function test_un_autre_soignant_ne_cloture_pas_la_consultation_d_un_confrere(): void
    {
        $premier = $this->soignant();
        $patient = $this->patient();
        $this->ouvrirSession($patient, $premier);
        $consultation = $this->service()->ouvrir($premier);

        $second = $this->soignant();
        $this->ouvrirSession($patient, $second);

        $this->attendRefus(
            fn () => $this->service()->cloturer($second, $consultation),
            'Cette consultation est menée par un autre soignant.'
        );

        $this->assertTrue($consultation->fresh()->estEnCours());
    }

    /**
     * La clôture n'exige PAS la voie consentie, à la différence de l'ouverture : refermer un acte
     * n'ajoute rien au dossier, et laisser une consultation « en cours » indéfiniment parce que
     * le consentement a expiré serait pire.
     */
    public function test_la_cloture_reste_possible_apres_expiration_du_consentement(): void
    {
        $soignant = $this->soignant();
        $patient = $this->patient();
        $this->ouvrirSession($patient, $soignant);
        $consultation = $this->service()->ouvrir($soignant);

        // La voie devient une voie de lecture seule (le patient a refermé, l'agent rouvre
        // autrement) : la clôture doit rester possible.
        $this->ouvrirSession($patient, $soignant, 'bris_de_glace');

        $close = $this->service()->cloturer($soignant, $consultation);

        $this->assertSame(StatutConsultation::CLOTUREE, $close->statut);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // La garde du moteur
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Une consultation close sans heure de clôture est une ligne qui ne veut rien dire, et c'est
     * en base qu'on la relira dans dix ans. Déclencheurs dans les DEUX dialectes : la faire vivre
     * dans un seul moteur la rendrait vraie en production et fausse en test (P6.8c, P6.8e).
     */
    public function test_le_moteur_refuse_une_cloture_sans_heure(): void
    {
        $soignant = $this->soignant();
        $this->ouvrirSession($this->patient(), $soignant);
        $consultation = $this->service()->ouvrir($soignant);

        $this->expectException(QueryException::class);

        \DB::table('consultations')
            ->where('id', $consultation->id)
            ->update(['statut' => 'cloturee', 'cloturee_le' => null]);
    }

    public function test_le_moteur_refuse_une_consultation_en_cours_qui_porte_une_cloture(): void
    {
        $soignant = $this->soignant();
        $this->ouvrirSession($this->patient(), $soignant);
        $consultation = $this->service()->ouvrir($soignant);

        $this->expectException(QueryException::class);

        \DB::table('consultations')
            ->where('id', $consultation->id)
            ->update(['statut' => 'en_cours', 'cloturee_le' => now()]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Le chemin HTTP réel — l'anti-IDOR est STRUCTUREL
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * IL N'Y A AUCUN CHAMP POUR NOMMER UN AUTRE PATIENT. La consultation porte sur le dossier que
     * tient la session, jamais sur un identifiant reçu — règle héritée du Module 4 et de P7-D0.
     * Ce vecteur passe par la VRAIE route : la garde de route (`permission:dossier.ecrire`) et
     * celle du service sont deux couches distinctes, et une couche, un vecteur (parade P6.6b).
     */
    public function test_la_route_reelle_ouvre_la_consultation_du_dossier_de_la_session(): void
    {
        $soignant = $this->soignant();
        $patient = $this->patient();
        $autrePatient = $this->patient();
        $acces = $this->ouvrirSession($patient, $soignant);

        $reponse = $this->actingAs($soignant, 'web')
            ->withSession(['dossier_ouvert' => $this->sessionReelle()])
            ->post(route('portail.dossier.consultation.ouvrir'), [
                'motif' => 'Toux persistante',
                // Envoyés délibérément : ils ne doivent avoir AUCUN effet.
                'membre_id' => $autrePatient->id,
                'soignant_nom' => 'Dr Quelqu\'un d\'Autre',
            ]);

        // `assertRedirect` seul ne dirait pas si la page cible plante : on suit la redirection et
        // on exige un 200 (leçon de la mutation, ci-dessus).
        $reponse->assertRedirect(route('portail.dossier.show'));

        $consultation = Consultation::firstOrFail();
        $this->assertSame($patient->id, $consultation->membre_id);
        $this->assertNotSame($autrePatient->id, $consultation->membre_id);
        $this->assertNotSame('Dr Quelqu\'un d\'Autre', $consultation->soignant_nom);
    }

    /**
     * DÉFAUT TROUVÉ AU G2 LIVE, INVISIBLE AUX VECTEURS DE SERVICE. Le service refusait correctement
     * une observation vide — la base le confirmait, une seule ligne y était écrite — et l'écran ne
     * DISAIT rien : le médecin voyait sa page recharger sans son texte et sans explication. Aucun
     * vecteur n'exerçait le RENDU, exactement le trou que B1-b avait trouvé dans B1-a.
     *
     * Le nom de ce vecteur dit « transmis » et non « affiché » : voir le commentaire du corps.
     */
    public function test_le_refus_d_une_observation_est_transmis_a_l_ecran(): void
    {
        $soignant = $this->soignant();
        $patient = $this->patient();
        $acces = $this->ouvrirSession($patient, $soignant);
        $this->service()->ouvrir($soignant);

        $session = $this->sessionReelle();

        // CE QUE CE VECTEUR PROUVE, ET CE QU'IL NE PROUVE PAS — dit plutot que suppose.
        //
        // Il prouve que le refus PART vers l'ecran (`assertSessionHasErrors`), sous la cle que la
        // vue lit. Il ne prouve PAS le rendu : `withSession()` remplace la session a chaque
        // requete, donc les erreurs flashees ne survivent pas a un second appel, et
        // `followingRedirects()` perd la session de dossier (le middleware `dossier.actif` renvoie
        // alors vers l'accueil). L'AFFICHAGE lui-meme est prouve au G2 live, ou le message est
        // passe de absent a present apres le correctif — pas ici.
        //
        // Une premiere version de ce vecteur « passait » en trouvant le texte cherche dans une
        // PAGE D'ERREUR Laravel, qui affiche le code source de la pile — donc ce fichier meme.
        // Seule la mutation l'a revele.
        $this->actingAs($soignant, 'web')
            ->withSession(['dossier_ouvert' => $session])
            ->post(route('portail.dossier.consultation.observer'), ['contenu' => '   '])
            ->assertSessionHasErrors(['contenu']);

        $this->assertDatabaseCount('notes_observations', 0);
    }

    /**
     * La route est déclarée AVANT `dossier/{section}` : sans cela « consultation » serait pris
     * pour une section à écrire. Même piège que `dossier/fermer`, `dossier/triage/...` (P10c-2-i)
     * et `signature/{type}/{id}` (P6.5b).
     */
    public function test_la_route_consultation_n_est_pas_captee_par_la_route_de_section(): void
    {
        $this->assertSame(
            'portail.dossier.consultation.ouvrir',
            app('router')->getRoutes()->getByName('portail.dossier.consultation.ouvrir')?->getName()
        );
    }

    // ─────────────────────────────────────────────────────────────────────────

    /** Vérifie qu'un refus survient ET qu'il survient pour la BONNE raison (leçon B1-d). */
    private function attendRefus(callable $action, string $message): void
    {
        try {
            $action();
            $this->fail("Refus attendu : {$message}");
        } catch (ValidationException $e) {
            $this->assertContains(
                $message,
                collect($e->errors())->flatten()->all(),
                'Le refus a bien eu lieu, mais pas pour la raison attendue.'
            );
        }
    }
}
