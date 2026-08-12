<?php

namespace Tests\Feature;

use App\Models\AppareilPush;
use App\Models\Delegation;
use App\Models\MembreFamille;
use App\Models\NotificationEnvoi;
use App\Models\ResponsableFamille;
use App\Models\User;
use App\Services\BrisDeGlaceService;
use App\Support\TypeNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Carnet familial partagé / incrément D1 — notifications en application.
 *
 * CE QUE CETTE SUITE PROTÈGE, écrit dans les DEUX SENS : que les bonnes personnes soient
 * prévenues, et surtout que les mauvaises ne le soient pas. Une notification de santé envoyée au
 * mauvais destinataire est une fuite, pas un désagrément.
 *
 * Elle protège aussi la règle la plus facile à casser par inadvertance : **aucun contenu médical
 * dans une notification**. Il suffirait qu'un jour quelqu'un concatène `$contribution->donnees`
 * dans le corps du message pour que le diagnostic d'un enfant s'affiche sur un écran verrouillé.
 */
class NotificationCarnetTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: User, 1: MembreFamille, 2: User} [parent, carnet enfant, delegue] */
    private function famille(string $droits = Delegation::DROIT_LECTURE_ECRITURE): array
    {
        $parent  = User::factory()->create();
        $enfant  = MembreFamille::factory()->for($parent)->create();
        $delegue = User::factory()->create();

        Delegation::create([
            'titulaire_user_id' => $parent->id,
            'delegue_user_id'   => $delegue->id,
            'membre_id'         => $enfant->id,
            'droits'            => $droits,
            'invitee_at'        => now(),
            'acceptee_at'       => now(),
        ]);

        return [$parent, $enfant, $delegue];
    }

    /** @return array<string, mixed> */
    private function antecedent(): array
    {
        return ['type' => 'maladie_chronique', 'description' => 'Fièvre à 39°C, vue aux urgences'];
    }

    private function deposer(User $delegue, MembreFamille $enfant): int
    {
        Sanctum::actingAs($delegue);
        $reponse = $this->postJson("/api/v1/membres/{$enfant->id}/contributions", [
            'section' => 'antecedents',
            'donnees' => $this->antecedent(),
        ])->assertCreated();

        return $reponse->json('contribution.id');
    }

    /** @return array<int, \Illuminate\Notifications\DatabaseNotification> */
    private function notificationsDe(User $user): array
    {
        return $user->notifications()->get()->all();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Contributions — qui est prévenu, et qui ne l'est pas
    // ─────────────────────────────────────────────────────────────────────────

    public function test_le_depot_notifie_le_proprietaire_du_carnet(): void
    {
        [$parent, $enfant, $delegue] = $this->famille();

        $this->deposer($delegue, $enfant);

        $notifications = $this->notificationsDe($parent);
        $this->assertCount(1, $notifications);
        $this->assertSame(TypeNotification::CONTRIBUTION_DEPOSEE->value, $notifications[0]->type);
    }

    /** L'auteur sait ce qu'il vient de faire : le notifier serait du bruit. */
    public function test_le_depot_ne_notifie_pas_son_auteur(): void
    {
        [, $enfant, $delegue] = $this->famille();

        $this->deposer($delegue, $enfant);

        $this->assertCount(0, $this->notificationsDe($delegue));
    }

    public function test_le_depot_notifie_aussi_le_second_responsable(): void
    {
        [$parent, $enfant, $delegue] = $this->famille();
        $second = User::factory()->create();

        ResponsableFamille::create([
            'titulaire_user_id'   => $parent->id,
            'responsable_user_id' => $second->id,
            'designe_le'          => now(),
        ]);

        $this->deposer($delegue, $enfant);

        $this->assertCount(1, $this->notificationsDe($parent));
        $this->assertCount(1, $this->notificationsDe($second));
    }

    /**
     * LA RÈGLE POSÉE AU G1 : le corps ne dit jamais ce qu'il y a dans le dossier.
     *
     * Le test cherche la donnée clinique déposée dans TOUTE la charge utile sérialisée — titre,
     * corps et identifiants compris. S'il la trouve, c'est qu'elle atteindra un écran verrouillé.
     */
    public function test_une_notification_ne_contient_aucun_contenu_medical(): void
    {
        [$parent, $enfant, $delegue] = $this->famille();

        $this->deposer($delegue, $enfant);

        $charge = json_encode($this->notificationsDe($parent)[0]->data, JSON_UNESCAPED_UNICODE);

        $this->assertStringNotContainsString('Fièvre', (string) $charge);
        $this->assertStringNotContainsString('maladie_chronique', (string) $charge);
        $this->assertStringNotContainsString('urgences', (string) $charge);

        // ... mais elle nomme bien la personne et l'acte, sinon elle serait inutile.
        $this->assertStringContainsString($enfant->prenom, (string) $charge);
    }

    public function test_la_validation_notifie_l_auteur_mais_pas_le_decideur(): void
    {
        [$parent, $enfant, $delegue] = $this->famille();
        $id = $this->deposer($delegue, $enfant);

        Sanctum::actingAs($parent);
        $this->postJson("/api/v1/contributions/{$id}/valider")->assertOk();

        $auteur = $this->notificationsDe($delegue);
        $this->assertCount(1, $auteur);
        $this->assertSame(TypeNotification::CONTRIBUTION_VALIDEE->value, $auteur[0]->type);

        // Le parent n'a que la notification de DÉPÔT : on ne s'annonce pas sa propre décision.
        $this->assertCount(1, $this->notificationsDe($parent));
    }

    /** « Tel responsable a validé l'ajout du carnet de X par Y » — demandé explicitement au G1. */
    public function test_la_validation_previent_le_second_responsable(): void
    {
        [$parent, $enfant, $delegue] = $this->famille();
        $second = User::factory()->create();

        ResponsableFamille::create([
            'titulaire_user_id'   => $parent->id,
            'responsable_user_id' => $second->id,
            'designe_le'          => now(),
        ]);

        $id = $this->deposer($delegue, $enfant);

        Sanctum::actingAs($parent);
        $this->postJson("/api/v1/contributions/{$id}/valider")->assertOk();

        // Dépôt + validation par l'autre responsable.
        $recues = $this->notificationsDe($second);
        $this->assertCount(2, $recues);
        $this->assertContains(
            TypeNotification::CONTRIBUTION_VALIDEE->value,
            array_column(array_map(fn ($n) => ['t' => $n->type], $recues), 't'),
        );
    }

    public function test_le_rejet_notifie_l_auteur_avec_le_motif(): void
    {
        [$parent, $enfant, $delegue] = $this->famille();
        $id = $this->deposer($delegue, $enfant);

        Sanctum::actingAs($parent);
        $this->postJson("/api/v1/contributions/{$id}/rejeter", [
            'motif' => 'Vérification faite : pas de consultation ce jour',
        ])->assertOk();

        $recue = $this->notificationsDe($delegue)[0];
        $this->assertSame(TypeNotification::CONTRIBUTION_REJETEE->value, $recue->type);
        $this->assertStringContainsString('pas de consultation', $recue->data['corps']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Délégations et responsables
    // ─────────────────────────────────────────────────────────────────────────

    public function test_une_invitation_notifie_le_delegue(): void
    {
        $parent  = User::factory()->create();
        $enfant  = MembreFamille::factory()->for($parent)->create();
        $delegue = User::factory()->create();

        Sanctum::actingAs($parent);
        $this->postJson("/api/v1/membres/{$enfant->id}/delegations", [
            'telephone' => $delegue->telephone,
        ])->assertCreated();

        $recues = $this->notificationsDe($delegue);
        $this->assertCount(1, $recues);
        $this->assertSame(TypeNotification::DELEGATION_RECUE->value, $recues[0]->type);
    }

    /**
     * UNE notification pour tout le lot, pas une par carnet.
     *
     * Quinze lignes identiques n'informeraient pas mieux : elles décourageraient de lire la liste.
     */
    public function test_un_partage_en_masse_ne_produit_qu_une_notification(): void
    {
        $parent = User::factory()->create();
        MembreFamille::factory()->count(4)->for($parent)->create();
        $delegue = User::factory()->create();

        Sanctum::actingAs($parent);
        $this->postJson('/api/v1/delegations/en-masse', ['telephone' => $delegue->telephone])
            ->assertCreated()
            ->assertJsonPath('invitations_creees', 4);

        $this->assertCount(1, $this->notificationsDe($delegue));
    }

    /** Rejouer un partage déjà fait ne doit rien réannoncer. */
    public function test_un_partage_en_masse_rejoue_ne_notifie_pas(): void
    {
        $parent = User::factory()->create();
        MembreFamille::factory()->count(3)->for($parent)->create();
        $delegue = User::factory()->create();

        Sanctum::actingAs($parent);
        $this->postJson('/api/v1/delegations/en-masse', ['telephone' => $delegue->telephone])
            ->assertCreated();
        $this->postJson('/api/v1/delegations/en-masse', ['telephone' => $delegue->telephone])
            ->assertCreated()
            ->assertJsonPath('invitations_creees', 0)
            ->assertJsonPath('deja_partages', 3);

        $this->assertCount(1, $this->notificationsDe($delegue));
    }

    public function test_la_designation_d_un_responsable_le_notifie(): void
    {
        $parent = User::factory()->create();
        $second = User::factory()->create();

        Sanctum::actingAs($parent);
        $this->postJson('/api/v1/responsables', ['telephone' => $second->telephone])
            ->assertCreated();

        $recues = $this->notificationsDe($second);
        $this->assertCount(1, $recues);
        $this->assertSame(TypeNotification::RESPONSABLE_DESIGNE->value, $recues[0]->type);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Dossier consulté — le scénario de l'accident
    // ─────────────────────────────────────────────────────────────────────────

    public function test_un_bris_de_glace_previent_le_proprietaire_et_les_delegues_en_lecture(): void
    {
        [$parent, $enfant, $delegue] = $this->famille(Delegation::DROIT_LECTURE);
        $agent = User::factory()->create();

        app(BrisDeGlaceService::class)->ouvrir($enfant, $agent, 'Patient inconscient', '127.0.0.1');

        $duParent = $this->notificationsDe($parent);
        $this->assertCount(1, $duParent);
        $this->assertSame(TypeNotification::DOSSIER_CONSULTE->value, $duParent[0]->type);
        $this->assertTrue($duParent[0]->data['urgent']);

        // « Tous les autres le sauront sans même qu'on les appelle. »
        $this->assertCount(1, $this->notificationsDe($delegue));
    }

    /**
     * Une délégation HISTORIQUE `qr_generation` ne lit pas le dossier — elle ne doit donc pas être
     * informée de sa consultation. Le contraire divulguerait un passage à l'hôpital à quelqu'un qui
     * n'a aucun accès au carnet.
     */
    public function test_une_delegation_qr_seule_n_est_pas_prevenue(): void
    {
        [$parent, $enfant, $delegue] = $this->famille(Delegation::DROIT_QR);
        $agent = User::factory()->create();

        app(BrisDeGlaceService::class)->ouvrir($enfant, $agent, 'Patient inconscient', null);

        $this->assertCount(1, $this->notificationsDe($parent));
        $this->assertCount(0, $this->notificationsDe($delegue));
    }

    /** Un soignant par ailleurs délégué du carnet ne s'alerte pas lui-même. */
    public function test_l_agent_qui_consulte_n_est_pas_notifie(): void
    {
        [, $enfant, $delegue] = $this->famille(Delegation::DROIT_LECTURE);

        app(BrisDeGlaceService::class)->ouvrir($enfant, $delegue, 'Urgence vitale', null);

        $this->assertCount(0, $this->notificationsDe($delegue));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Endpoints — anti-IDOR et idempotence
    // ─────────────────────────────────────────────────────────────────────────

    public function test_on_ne_voit_que_ses_propres_notifications(): void
    {
        [$parent, $enfant, $delegue] = $this->famille();
        $this->deposer($delegue, $enfant);

        $tiers = User::factory()->create();
        Sanctum::actingAs($tiers);
        $this->getJson('/api/v1/notifications')
            ->assertOk()
            ->assertJsonPath('non_lues', 0)
            ->assertJsonCount(0, 'notifications');

        Sanctum::actingAs($parent);
        $this->getJson('/api/v1/notifications')
            ->assertOk()
            ->assertJsonPath('non_lues', 1);
    }

    /** 404 et non 403 : un 403 confirmerait l'existence de la notification d'autrui. */
    public function test_marquer_lue_la_notification_d_autrui_est_introuvable(): void
    {
        [$parent, $enfant, $delegue] = $this->famille();
        $this->deposer($delegue, $enfant);

        $id = $this->notificationsDe($parent)[0]->id;

        Sanctum::actingAs(User::factory()->create());
        $this->postJson("/api/v1/notifications/{$id}/lu")->assertNotFound();
    }

    /**
     * Idempotent, et la date de PREMIÈRE lecture est préservée.
     *
     * C'est ce qui donne sa valeur à la question « le responsable était-il au courant, et depuis
     * quand ? ». Écraser avec la dernière consultation effacerait la réponse.
     */
    public function test_marquer_lue_est_idempotent_et_preserve_la_premiere_date(): void
    {
        [$parent, $enfant, $delegue] = $this->famille();
        $this->deposer($delegue, $enfant);

        $id = $this->notificationsDe($parent)[0]->id;

        Sanctum::actingAs($parent);
        $this->postJson("/api/v1/notifications/{$id}/lu")->assertOk()->assertJsonPath('non_lues', 0);

        $premiere = $parent->notifications()->find($id)->read_at;

        $this->travel(5)->minutes();
        $this->postJson("/api/v1/notifications/{$id}/lu")->assertOk();

        $this->assertEquals($premiere, $parent->notifications()->find($id)->read_at);
    }

    public function test_tout_marquer_lu_remet_le_compteur_a_zero(): void
    {
        [$parent, $enfant, $delegue] = $this->famille();
        $this->deposer($delegue, $enfant);
        $this->deposer($delegue, $enfant);

        Sanctum::actingAs($parent);
        $this->getJson('/api/v1/notifications')->assertJsonPath('non_lues', 2);
        $this->postJson('/api/v1/notifications/tout-lu')->assertOk()->assertJsonPath('non_lues', 0);
        $this->getJson('/api/v1/notifications/non-lues')->assertJsonPath('non_lues', 0);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Jetons de push
    // ─────────────────────────────────────────────────────────────────────────

    public function test_un_jeton_expo_malforme_est_refuse(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $this->postJson('/api/v1/appareils-push', ['jeton' => 'pas-un-jeton'])
            ->assertStatus(422);
    }

    /**
     * LA GARDE DE CONFIDENTIALITÉ : un téléphone revendu, ou une application réinstallée, peut
     * recevoir un jeton déjà connu. S'il était empilé, le nouveau propriétaire recevrait les
     * notifications de santé de l'ancien.
     */
    public function test_un_jeton_reenregistre_est_reaffecte_et_jamais_duplique(): void
    {
        $jeton = 'ExponentPushToken[abcDEF123-_]';

        Sanctum::actingAs($premier = User::factory()->create());
        $this->postJson('/api/v1/appareils-push', ['jeton' => $jeton, 'plateforme' => 'android'])
            ->assertCreated();

        Sanctum::actingAs($second = User::factory()->create());
        $this->postJson('/api/v1/appareils-push', ['jeton' => $jeton, 'plateforme' => 'android'])
            ->assertCreated();

        $this->assertDatabaseCount('appareils_push', 1);
        $this->assertSame($second->id, AppareilPush::first()->user_id);
        $this->assertNotSame($premier->id, AppareilPush::first()->user_id);
    }

    public function test_la_deconnexion_revoque_l_appareil(): void
    {
        $jeton = 'ExponentPushToken[zzz999]';

        Sanctum::actingAs(User::factory()->create());
        $this->postJson('/api/v1/appareils-push', ['jeton' => $jeton])->assertCreated();
        $this->deleteJson('/api/v1/appareils-push', ['jeton' => $jeton])->assertOk();

        $this->assertNotNull(AppareilPush::first()->revoque_le);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Canal push — gaté OFF, et sans jamais mettre en péril l'acte métier
    // ─────────────────────────────────────────────────────────────────────────

    public function test_le_canal_push_est_gate_off_par_defaut(): void
    {
        Http::fake();
        [$parent, $enfant, $delegue] = $this->famille();

        AppareilPush::create([
            'user_id'    => $parent->id,
            'jeton_expo' => 'ExponentPushToken[off]',
        ]);

        $this->deposer($delegue, $enfant);

        // La notification en application existe bien...
        $this->assertCount(1, $this->notificationsDe($parent));
        // ... mais rien n'est parti vers Expo.
        Http::assertNothingSent();
        $this->assertDatabaseCount('notification_envois', 0);
    }

    public function test_push_active_l_envoi_part_et_est_trace(): void
    {
        config(['masante.notifications.push.enabled' => true]);
        Http::fake([
            '*' => Http::response(['data' => [['status' => 'ok', 'id' => 'ticket-1']]]),
        ]);

        [$parent, $enfant, $delegue] = $this->famille();
        AppareilPush::create([
            'user_id'    => $parent->id,
            'jeton_expo' => 'ExponentPushToken[on]',
        ]);

        $this->deposer($delegue, $enfant);

        Http::assertSentCount(1);
        $envoi = NotificationEnvoi::first();
        $this->assertSame(NotificationEnvoi::ENVOYEE, $envoi->statut);
        $this->assertSame('ticket-1', $envoi->ticket_id);
    }

    /**
     * Expo dit d'arrêter d'écrire à ce jeton : l'appareil est révoqué sur-le-champ.
     * Continuer gaspillerait du quota et, pire, livrerait la notification au nouveau propriétaire.
     */
    public function test_device_not_registered_revoque_l_appareil(): void
    {
        config(['masante.notifications.push.enabled' => true]);
        Http::fake([
            '*' => Http::response(['data' => [[
                'status'  => 'error',
                'message' => 'not registered',
                'details' => ['error' => 'DeviceNotRegistered'],
            ]]]),
        ]);

        [$parent, $enfant, $delegue] = $this->famille();
        $appareil = AppareilPush::create([
            'user_id'    => $parent->id,
            'jeton_expo' => 'ExponentPushToken[mort]',
        ]);

        $this->deposer($delegue, $enfant);

        $this->assertSame(NotificationEnvoi::ECHOUEE, NotificationEnvoi::first()->statut);
        $this->assertNotNull($appareil->fresh()->revoque_le);
    }

    /**
     * LE TEST QUI COMPTE LE PLUS : Expo injoignable ne doit pas faire échouer le dépôt.
     *
     * Un service tiers n'a jamais le droit de mettre en péril l'écriture d'un dossier médical. La
     * contribution est créée, la notification en application est là, seul le push est perdu — et
     * sa perte est tracée.
     */
    public function test_un_push_en_echec_ne_fait_pas_echouer_l_acte_metier(): void
    {
        config(['masante.notifications.push.enabled' => true]);
        Http::fake(fn () => throw new \RuntimeException('exp.host injoignable'));

        [$parent, $enfant, $delegue] = $this->famille();
        AppareilPush::create([
            'user_id'    => $parent->id,
            'jeton_expo' => 'ExponentPushToken[panne]',
        ]);

        $this->deposer($delegue, $enfant);   // assertCreated() est dans le helper

        $this->assertDatabaseCount('contributions', 1);
        $this->assertCount(1, $this->notificationsDe($parent));
        $this->assertSame(NotificationEnvoi::ECHOUEE, NotificationEnvoi::first()->statut);
    }
}
