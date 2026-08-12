<?php

namespace Tests\Feature;

use App\Models\AccesDossier;
use App\Models\Delegation;
use App\Models\MembreFamille;
use App\Models\StructureSanitaire;
use App\Models\User;
use App\Services\EcritureSoignantService;
use App\Services\SessionDossierService;
use App\Support\TypeNotification;
use Database\Seeders\PortailRolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Carnet familial partagé / incrément D0 — écriture du soignant au carnet.
 *
 * CE QUE CETTE SUITE PROTÈGE. Le G0 de D2 a montré qu'aucun soignant ne pouvait écrire dans le
 * carnet : `Portail\DossierController` était en lecture seule et le chemin médecin était annoncé
 * « futur ». D0 l'ouvre — et l'ouvrir sans garde ferait de chaque agent d'accueil un rédacteur
 * d'ordonnances.
 *
 * Écrite dans les DEUX SENS : ce que l'écriture doit permettre, et tout ce qu'elle doit refuser.
 * Les trois gardes cumulatives (habilitation, voie consentie, section ouverte) ont chacune leur
 * vecteur, car aucune ne rattrape les deux autres.
 */
class EcritureSoignantTest extends TestCase
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
            $user->givePermissionTo('dossier.ecrire');
        }

        return $user->fresh();
    }

    /** @return array{0: User, 1: MembreFamille} [propriétaire, carnet de l'enfant] */
    private function famille(): array
    {
        $parent = User::factory()->create();

        return [$parent, MembreFamille::factory()->for($parent)->create()];
    }

    /** @return array<string, mixed> */
    private function antecedent(): array
    {
        return ['type' => 'maladie_chronique', 'description' => 'Paludisme simple, traité aux urgences'];
    }

    private function service(): EcritureSoignantService
    {
        return app(EcritureSoignantService::class);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Ce que D0 ouvre
    // ─────────────────────────────────────────────────────────────────────────

    public function test_un_soignant_habilite_consigne_un_antecedent(): void
    {
        [, $enfant] = $this->famille();

        $entree = $this->service()->ecrire(
            $this->soignant(), $enfant, 'qr_scan', 'antecedents', $this->antecedent()
        );

        $this->assertDatabaseCount('antecedents', 1);
        $this->assertSame('medecin', $entree->source);
        $this->assertSame('medecin', $entree->added_by);
    }

    /**
     * LA GARANTIE QUI DONNE SA VALEUR À LA FICHE DE PARCOURS (D2) : « ceci vient d'un soignant »
     * est une décision du serveur, pas une déclaration du client. Miroir exact de l'incrément C,
     * où un délégué ne peut pas se faire passer pour un soignant.
     */
    public function test_le_client_ne_peut_pas_imposer_la_provenance(): void
    {
        [, $enfant] = $this->famille();

        $entree = $this->service()->ecrire(
            $this->soignant(), $enfant, 'qr_scan', 'antecedents',
            $this->antecedent() + ['source' => 'patient', 'added_by' => 'patient'],
        );

        $this->assertSame('medecin', $entree->source);
        $this->assertSame('medecin', $entree->added_by);
    }

    /**
     * Répare la garantie VIDE que l'incrément C laissait sur cette section : `vaccinations` n'avait
     * ni `source` ni `added_by`, et Eloquent écartait les deux clés sans bruit.
     */
    public function test_la_provenance_est_desormais_persistee_sur_les_vaccinations(): void
    {
        [, $enfant] = $this->famille();

        $entree = $this->service()->ecrire(
            $this->soignant(), $enfant, 'qr_scan', 'vaccinations',
            ['vaccin_nom' => 'BCG', 'statut' => 'fait', 'date_administration' => '2026-08-01'],
        );

        $this->assertDatabaseHas('vaccinations', [
            'id' => $entree->id, 'source' => 'medecin', 'added_by' => 'medecin',
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Les trois gardes — chacune son vecteur
    // ─────────────────────────────────────────────────────────────────────────

    public function test_sans_habilitation_l_ecriture_est_refusee(): void
    {
        [, $enfant] = $this->famille();

        $this->expectException(\RuntimeException::class);
        $this->service()->ecrire(
            $this->soignant(habilite: false), $enfant, 'qr_scan', 'antecedents', $this->antecedent()
        );
    }

    /**
     * LE VECTEUR LE PLUS IMPORTANT DU LOT. Le bris de glace ouvre le vital minimal, 15 minutes,
     * SANS le consentement du patient. Y autoriser l'écriture ferait d'un accès d'exception non
     * consenti un droit de modifier le dossier.
     */
    public function test_un_bris_de_glace_n_autorise_pas_a_ecrire(): void
    {
        [, $enfant] = $this->famille();

        $this->expectException(\RuntimeException::class);
        $this->service()->ecrire(
            $this->soignant(), $enfant, 'bris_de_glace', 'antecedents', $this->antecedent()
        );
    }

    public function test_la_voie_admin_n_autorise_pas_non_plus(): void
    {
        [, $enfant] = $this->famille();

        $this->expectException(\RuntimeException::class);
        $this->service()->ecrire(
            $this->soignant(), $enfant, 'admin', 'antecedents', $this->antecedent()
        );
    }

    /** Le médecin référent, lui, a été désigné par le patient : la voie est consentie. */
    public function test_la_voie_referent_autorise_l_ecriture(): void
    {
        [, $enfant] = $this->famille();

        $this->service()->ecrire(
            $this->soignant(), $enfant, 'referent', 'antecedents', $this->antecedent()
        );

        $this->assertDatabaseCount('antecedents', 1);
    }

    /** `rappels` est ouvert aux contributions (C) mais pas au soignant : ce n'est pas un acte. */
    public function test_une_section_hors_liste_soignant_est_refusee(): void
    {
        [, $enfant] = $this->famille();

        $this->expectException(\RuntimeException::class);
        $this->service()->ecrire(
            $this->soignant(), $enfant, 'qr_scan', 'rappels',
            ['type' => 'medicament', 'titre' => 'Prise du soir', 'frequence' => 'quotidien'],
        );
    }

    public function test_une_saisie_invalide_est_rejetee(): void
    {
        [, $enfant] = $this->famille();

        $this->expectException(ValidationException::class);
        $this->service()->ecrire(
            $this->soignant(), $enfant, 'qr_scan', 'antecedents', ['type' => 'inexistant'],
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // La famille est prévenue (canal D1)
    // ─────────────────────────────────────────────────────────────────────────

    public function test_la_famille_est_prevenue_de_l_ajout(): void
    {
        [$parent, $enfant] = $this->famille();
        $soignant = $this->soignant();

        // Un proche a le carnet en lecture : il doit être prévenu lui aussi.
        $delegue = User::factory()->create();
        Delegation::create([
            'titulaire_user_id' => $parent->id, 'delegue_user_id' => $delegue->id,
            'membre_id' => $enfant->id, 'droits' => Delegation::DROIT_LECTURE,
            'invitee_at' => now(), 'acceptee_at' => now(),
        ]);

        $this->service()->ecrire($soignant, $enfant, 'qr_scan', 'ordonnances', [
            'medecin_nom' => 'Dr Aka', 'structure_sanitaire' => 'CHU de Cocody',
            'date_prescription' => '2026-08-12', 'medicaments_json' => [['nom' => 'Artéméther']],
        ]);
        $this->service()->notifier($enfant, $soignant, 'ordonnances');

        $recue = $parent->notifications()->first();
        $this->assertSame(TypeNotification::CARNET_ENRICHI->value, $recue->type);
        $this->assertCount(1, $delegue->notifications()->get());

        // Le soignant ne s'annonce pas à lui-même.
        $this->assertCount(0, $soignant->notifications()->get());
    }

    /** La règle inviolable de D1 vaut ici sans changement : on nomme la section, jamais le contenu. */
    public function test_la_notification_ne_contient_aucun_contenu_medical(): void
    {
        [$parent, $enfant] = $this->famille();
        $soignant = $this->soignant();

        $this->service()->ecrire($soignant, $enfant, 'qr_scan', 'ordonnances', [
            'medecin_nom' => 'Dr Aka', 'structure_sanitaire' => 'CHU de Cocody',
            'date_prescription' => '2026-08-12', 'medicaments_json' => [['nom' => 'Artéméther']],
        ]);
        $this->service()->notifier($enfant, $soignant, 'ordonnances');

        $charge = json_encode($parent->notifications()->first()->data, JSON_UNESCAPED_UNICODE);

        $this->assertStringNotContainsString('Artéméther', (string) $charge);
        $this->assertStringContainsString('une ordonnance', (string) $charge);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Le journal d'audit — `donnees_ajoutees` enfin renseignée
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * La colonne était déclarée depuis le Module 2 et n'avait jamais été écrite. Sans elle, la
     * fiche de parcours (D2) ne pourrait pas relier une ordonnance à l'agent qui l'a rédigée.
     */
    public function test_la_cloture_journalise_ce_qui_a_ete_ecrit_sans_contenu_clinique(): void
    {
        [, $enfant] = $this->famille();
        $soignant = $this->soignant();
        $session  = app(SessionDossierService::class);

        $ouverture = AccesDossier::create([
            'membre_id' => $enfant->id, 'agent_id' => $soignant->id, 'type_acces' => 'qr_scan',
        ]);
        $session->ouvrir($ouverture);

        $entree = $this->service()->ecrire(
            $soignant, $enfant, 'qr_scan', 'antecedents', $this->antecedent()
        );
        $session->noterEcriture('antecedents', $entree->id);
        $session->fermer('manuelle');

        $cloture = AccesDossier::latest('id')->first();
        $this->assertCount(1, $cloture->donnees_ajoutees);
        $this->assertSame('antecedents', $cloture->donnees_ajoutees[0]['section']);
        $this->assertSame($entree->id, $cloture->donnees_ajoutees[0]['id']);

        // Minimisation : le journal dit QUOI a été ajouté, jamais son contenu.
        $this->assertStringNotContainsString(
            'Paludisme', json_encode($cloture->donnees_ajoutees, JSON_UNESCAPED_UNICODE)
        );
    }

    /** Une session sans écriture doit laisser `donnees_ajoutees` à NULL, pas à un tableau vide. */
    public function test_une_session_sans_ecriture_ne_journalise_aucun_ajout(): void
    {
        [, $enfant] = $this->famille();
        $session = app(SessionDossierService::class);

        $ouverture = AccesDossier::create([
            'membre_id' => $enfant->id, 'agent_id' => $this->soignant()->id, 'type_acces' => 'qr_scan',
        ]);
        $session->ouvrir($ouverture);
        $session->fermer('manuelle');

        $this->assertNull(AccesDossier::latest('id')->first()->donnees_ajoutees);
    }
}
