<?php

namespace Tests\Feature;

use App\Models\AccesDossier;
use App\Models\Contribution;
use App\Models\Delegation;
use App\Models\MembreFamille;
use App\Models\ResponsableFamille;
use App\Models\StructureSanitaire;
use App\Models\User;
use App\Services\ServiceNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Carnet familial partagé / incrément D2 — fiche de parcours.
 *
 * CE QUE CES TESTS PROTÈGENT :
 *  - la séparation demandée par le propriétaire : toute la famille VOIT, seuls les responsables
 *    DÉCIDENT (un même délégué lit la fiche et se voit refuser la validation) ;
 *  - l'honnêteté de la fiche : aucun lien inventé entre une entrée et une visite, aucune durée
 *    supposée, aucun établissement déduit après coup ;
 *  - ce qui n'y entre pas : l'adresse IP, et les lectures familiales.
 */
class FicheParcoursTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: User, 1: MembreFamille} [propriétaire, carnet de l'enfant] */
    private function famille(): array
    {
        $parent = User::factory()->create();

        return [$parent, MembreFamille::factory()->for($parent)->create()];
    }

    private function delegue(User $parent, MembreFamille $membre, string $droits = Delegation::DROIT_LECTURE): User
    {
        $delegue = User::factory()->create();

        Delegation::create([
            'titulaire_user_id' => $parent->id,
            'delegue_user_id'   => $delegue->id,
            'membre_id'         => $membre->id,
            'droits'            => $droits,
            'invitee_at'        => now(),
            'acceptee_at'       => now(),
        ]);

        return $delegue;
    }

    /**
     * Écrit les DEUX lignes d'une consultation, comme le fait le portail : une ouverture au scan,
     * une clôture à la fermeture — le journal étant immuable, rien n'est complété après coup.
     *
     * @param  array<int, array<string, mixed>>|null  $donneesAjoutees
     */
    private function consultation(
        MembreFamille $membre,
        User $agent,
        string $type = 'qr_scan',
        ?string $etablissement = 'CHU de Cocody',
        ?array $donneesAjoutees = null,
    ): AccesDossier {
        $ouverture = AccesDossier::create([
            'membre_id'     => $membre->id,
            'agent_id'      => $agent->id,
            'type_acces'    => $type,
            'etablissement' => $etablissement,
            'ip_address'    => '10.0.0.7',
        ]);

        AccesDossier::create([
            'membre_id'           => $membre->id,
            'agent_id'            => $agent->id,
            'type_acces'          => $type,
            'etablissement'       => $etablissement,
            'acces_ouverture_id'  => $ouverture->id,
            'sections_consultees' => ['dossier', 'ordonnances'],
            'donnees_ajoutees'    => $donneesAjoutees,
            'ip_address'          => '10.0.0.7',
            'duree_minutes'       => 12,
        ]);

        return $ouverture;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Audience : qui peut VOIR la fiche
    // ─────────────────────────────────────────────────────────────────────────

    public function test_le_proprietaire_consulte_la_fiche(): void
    {
        [$parent, $enfant] = $this->famille();

        Sanctum::actingAs($parent);
        $this->getJson("/api/v1/membres/{$enfant->id}/parcours")
            ->assertOk()
            ->assertJsonPath('membre.id', $enfant->id);
    }

    public function test_un_delegue_en_lecture_consulte_la_fiche(): void
    {
        [$parent, $enfant] = $this->famille();
        $delegue = $this->delegue($parent, $enfant);

        Sanctum::actingAs($delegue);
        $this->getJson("/api/v1/membres/{$enfant->id}/parcours")->assertOk();
    }

    /**
     * Un second responsable n'est pas forcément délégué SUR CE MEMBRE. Sans cette branche de la
     * Policy, on lui demanderait de valider une contribution sans lui laisser vérifier le passage
     * à l'hôpital qui la motive.
     */
    public function test_un_second_responsable_non_delegue_consulte_la_fiche(): void
    {
        [$parent, $enfant] = $this->famille();
        $second = User::factory()->create();

        ResponsableFamille::create([
            'titulaire_user_id'   => $parent->id,
            'responsable_user_id' => $second->id,
            'designe_le'          => now(),
        ]);

        Sanctum::actingAs($second);
        $this->getJson("/api/v1/membres/{$enfant->id}/parcours")->assertOk();
    }

    public function test_un_tiers_n_accede_pas_a_la_fiche(): void
    {
        [, $enfant] = $this->famille();

        Sanctum::actingAs(User::factory()->create());
        $this->getJson("/api/v1/membres/{$enfant->id}/parcours")->assertForbidden();
    }

    public function test_une_delegation_revoquee_ferme_la_fiche_immediatement(): void
    {
        [$parent, $enfant] = $this->famille();
        $delegue = $this->delegue($parent, $enfant);

        Delegation::query()->update(['revoquee_at' => now()]);

        Sanctum::actingAs($delegue);
        $this->getJson("/api/v1/membres/{$enfant->id}/parcours")->assertForbidden();
    }

    /**
     * `qr_generation` est le droit des délégations d'avant l'incrément A : il permet de générer un
     * QR, jamais d'ouvrir le dossier. Il ne doit donc pas ouvrir son parcours non plus.
     */
    public function test_un_delegue_qr_generation_seul_n_accede_pas_a_la_fiche(): void
    {
        [$parent, $enfant] = $this->famille();
        $delegue = $this->delegue($parent, $enfant, Delegation::DROIT_QR);

        Sanctum::actingAs($delegue);
        $this->getJson("/api/v1/membres/{$enfant->id}/parcours")->assertForbidden();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Voir n'est pas décider — LE vecteur de la décision propriétaire
    // ─────────────────────────────────────────────────────────────────────────

    public function test_un_delegue_voit_la_fiche_mais_ne_valide_pas(): void
    {
        [$parent, $enfant] = $this->famille();
        $delegue = $this->delegue($parent, $enfant, Delegation::DROIT_LECTURE_ECRITURE);

        $contribution = Contribution::create([
            'membre_id'      => $enfant->id,
            'auteur_user_id' => $delegue->id,
            'section'        => 'antecedents',
            'donnees'        => ['type' => 'autre', 'description' => 'Vu aux urgences'],
            'statut'         => Contribution::BROUILLON,
        ]);

        Sanctum::actingAs($delegue);

        $this->getJson("/api/v1/membres/{$enfant->id}/parcours")->assertOk();

        // 409 et non 403 : l'incrément C traduit tout refus de décision en « décision impossible ».
        // On assère le comportement réel du projet, pas celui qu'on aurait choisi — et le message
        // dit bien pourquoi : ce délégué peut voir, il n'est pas responsable.
        $this->postJson("/api/v1/contributions/{$contribution->id}/valider")
            ->assertStatus(409)
            ->assertJsonPath('error.message', 'Seul un responsable de ce carnet peut décider.');

        $this->assertSame(Contribution::BROUILLON, $contribution->fresh()->statut);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Ce que la fiche dit — et ce qu'elle refuse de dire
    // ─────────────────────────────────────────────────────────────────────────

    public function test_une_consultation_en_deux_lignes_devient_une_seule_visite(): void
    {
        [$parent, $enfant] = $this->famille();
        $agent = User::factory()->create(['prenom' => 'Aka', 'nom' => 'Konan']);

        $this->consultation($enfant, $agent);

        Sanctum::actingAs($parent);
        $this->getJson("/api/v1/membres/{$enfant->id}/parcours")
            ->assertOk()
            ->assertJsonCount(1, 'visites')
            ->assertJsonPath('visites.0.agent', 'Aka Konan')
            ->assertJsonPath('visites.0.etablissement', 'CHU de Cocody')
            ->assertJsonPath('visites.0.cloturee', true)
            ->assertJsonPath('visites.0.duree_minutes', 12);
    }

    /**
     * L'agent a fermé son navigateur : `fermer()` n'a jamais été appelé, la durée est INCONNUE.
     * On l'annonce — inventer une durée serait pire que de ne rien dire.
     */
    public function test_une_session_jamais_cloturee_est_annoncee_comme_telle(): void
    {
        [$parent, $enfant] = $this->famille();

        AccesDossier::create([
            'membre_id'     => $enfant->id,
            'agent_id'      => User::factory()->create()->id,
            'type_acces'    => 'qr_scan',
            'etablissement' => 'CHU de Cocody',
        ]);

        Sanctum::actingAs($parent);
        $this->getJson("/api/v1/membres/{$enfant->id}/parcours")
            ->assertOk()
            ->assertJsonCount(1, 'visites')
            ->assertJsonPath('visites.0.cloturee', false)
            ->assertJsonPath('visites.0.duree_minutes', null);
    }

    /**
     * Une lecture familiale n'est pas un passage à l'hôpital — et il y en a une ligne PAR SECTION
     * lue. Les mêler noierait la visite et travestirait la lecture d'un proche en acte de soin.
     */
    public function test_les_lectures_familiales_ne_sont_pas_des_visites(): void
    {
        [$parent, $enfant] = $this->famille();
        $delegue = $this->delegue($parent, $enfant);

        foreach (['dossier', 'antecedents', 'ordonnances'] as $section) {
            AccesDossier::create([
                'membre_id'           => $enfant->id,
                'agent_id'            => $delegue->id,
                'type_acces'          => 'delegation',
                'sections_consultees' => [$section],
            ]);
        }

        Sanctum::actingAs($parent);
        $this->getJson("/api/v1/membres/{$enfant->id}/parcours")
            ->assertOk()
            ->assertJsonCount(0, 'visites');
    }

    /**
     * L'adresse IP n'apprend rien à une famille et désigne un lieu de connexion. Elle reste dans
     * le journal brut, réservé au propriétaire (§10.3) — y compris pour le propriétaire lui-même.
     */
    public function test_l_adresse_ip_n_apparait_jamais_dans_la_fiche(): void
    {
        [$parent, $enfant] = $this->famille();
        $this->consultation($enfant, User::factory()->create());

        Sanctum::actingAs($parent);
        $reponse = $this->getJson("/api/v1/membres/{$enfant->id}/parcours")->assertOk();

        $this->assertStringNotContainsString('10.0.0.7', $reponse->getContent());
        $this->assertStringNotContainsString('ip_address', $reponse->getContent());
    }

    /**
     * Les lignes écrites avant D2 n'ont pas d'établissement. La fiche le laisse à `null` : c'est à
     * l'écran de dire « non enregistré ». Le déduire du compte de l'agent ferait changer
     * d'établissement toutes ses visites passées le jour où il change d'hôpital.
     */
    public function test_un_acces_sans_etablissement_ne_l_invente_pas(): void
    {
        [$parent, $enfant] = $this->famille();
        // L'agent EST rattaché à un établissement aujourd'hui : la fiche ne doit pas s'en servir
        // pour combler une ligne ancienne. C'est tout l'enjeu de la copie à l'écriture.
        $agent = User::factory()->create();
        $agent->structure_id = StructureSanitaire::create([
            'nom'      => 'Centre de santé de Port-Bouët',
            'type'     => 'centre_sante',
            'commune'  => 'Port-Bouët',
            'adresse'  => 'Abidjan',
            'latitude' => 5.25,
            'longitude' => -3.93,
        ])->id;
        $agent->save();

        $this->consultation($enfant, $agent, etablissement: null);

        Sanctum::actingAs($parent);
        $this->getJson("/api/v1/membres/{$enfant->id}/parcours")
            ->assertOk()
            ->assertJsonPath('visites.0.etablissement', null);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Les deux blocs d'entrées : lien certain / rapprochement possible
    // ─────────────────────────────────────────────────────────────────────────

    public function test_une_entree_ecrite_pendant_la_visite_lui_est_rattachee(): void
    {
        [$parent, $enfant] = $this->famille();

        $ordonnance = $enfant->ordonnances()->create([
            'medecin_nom'         => 'Dr Aka Konan',
            'structure_sanitaire' => 'CHU de Cocody',
            'date_prescription'   => now()->toDateString(),
            'medicaments_json'    => [['nom' => 'Artemether-Lumefantrine']],
            'source'              => 'medecin',
            'added_by'            => 'medecin',
        ]);

        $this->consultation($enfant, User::factory()->create(), donneesAjoutees: [
            ['section' => 'ordonnances', 'id' => $ordonnance->id, 'a' => now()->toIso8601String()],
        ]);

        Sanctum::actingAs($parent);
        $reponse = $this->getJson("/api/v1/membres/{$enfant->id}/parcours")
            ->assertOk()
            ->assertJsonCount(1, 'visites.0.entrees')
            ->assertJsonPath('visites.0.entrees.0.section', 'ordonnances')
            ->assertJsonPath('visites.0.entrees.0.libelle', 'Ordonnance du Dr Aka Konan')
            // Rattachée à une visite, elle ne réapparaît PAS dans le second bloc.
            ->assertJsonCount(0, 'autres_entrees');

        // Le libellé nomme l'acte, jamais le médicament.
        $this->assertStringNotContainsString('Artemether', $reponse->getContent());
    }

    /**
     * Une entrée de soignant qu'aucune visite ne réclame — le cas des écritures antérieures à D0.
     * Elle est montrée, mais dans un bloc SÉPARÉ : le lien n'est pas connu, donc pas affirmé.
     */
    public function test_une_entree_medicale_orpheline_va_dans_le_second_bloc(): void
    {
        [$parent, $enfant] = $this->famille();

        $enfant->vaccinations()->create([
            'vaccin_nom'          => 'Rougeole-Rubéole',
            'statut'              => 'fait',
            'date_administration' => now()->toDateString(),
            'source'              => 'medecin',
            'added_by'            => 'medecin',
        ]);

        Sanctum::actingAs($parent);
        $this->getJson("/api/v1/membres/{$enfant->id}/parcours")
            ->assertOk()
            ->assertJsonCount(0, 'visites')
            ->assertJsonCount(1, 'autres_entrees')
            ->assertJsonPath('autres_entrees.0.libelle', 'Vaccination : Rougeole-Rubéole');
    }

    /** Une entrée auto-déclarée par la famille n'est pas un acte médical : elle n'entre pas. */
    public function test_une_entree_auto_declaree_n_apparait_pas_dans_les_entrees_medicales(): void
    {
        [$parent, $enfant] = $this->famille();

        $enfant->vaccinations()->create([
            'vaccin_nom'          => 'BCG (carnet papier)',
            'statut'              => 'fait',
            'date_administration' => now()->toDateString(),
        ]);

        Sanctum::actingAs($parent);
        $this->getJson("/api/v1/membres/{$enfant->id}/parcours")
            ->assertOk()
            ->assertJsonCount(0, 'autres_entrees');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Extension D1 : la décision se sait dans toute la famille
    // ─────────────────────────────────────────────────────────────────────────

    public function test_la_validation_previent_la_famille_sans_prevenir_le_decideur(): void
    {
        [$parent, $enfant] = $this->famille();
        $auteur = $this->delegue($parent, $enfant, Delegation::DROIT_LECTURE_ECRITURE);
        $proche = $this->delegue($parent, $enfant);

        $contribution = Contribution::create([
            'membre_id'      => $enfant->id,
            'auteur_user_id' => $auteur->id,
            'section'        => 'antecedents',
            'donnees'        => ['type' => 'autre', 'description' => 'Paludisme confirmé'],
            'statut'         => Contribution::VALIDEE,
        ]);

        app(ServiceNotification::class)->contributionDecidee($contribution, $parent);

        // L'auteur et le proche en lecture savent ; le décideur ne s'annonce pas sa propre décision.
        $this->assertDatabaseHas('notifications', ['notifiable_id' => $auteur->id]);
        $this->assertDatabaseHas('notifications', ['notifiable_id' => $proche->id]);
        $this->assertDatabaseMissing('notifications', ['notifiable_id' => $parent->id]);
    }

    /**
     * Élargir une audience élargit une surface de fuite : la règle inviolable de D1 est rejouée
     * sur les destinataires nouvellement ajoutés.
     */
    public function test_aucune_notification_de_decision_ne_porte_de_contenu_medical(): void
    {
        [$parent, $enfant] = $this->famille();
        $auteur = $this->delegue($parent, $enfant, Delegation::DROIT_LECTURE_ECRITURE);
        $this->delegue($parent, $enfant);

        $contribution = Contribution::create([
            'membre_id'      => $enfant->id,
            'auteur_user_id' => $auteur->id,
            'section'        => 'antecedents',
            'donnees'        => ['type' => 'autre', 'description' => 'Paludisme confirmé'],
            'statut'         => Contribution::VALIDEE,
        ]);

        app(ServiceNotification::class)->contributionDecidee($contribution, $parent);

        foreach (\DB::table('notifications')->pluck('data') as $charge) {
            $this->assertStringNotContainsString('Paludisme', (string) $charge);
        }
    }
}
