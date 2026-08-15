<?php

namespace Tests\Feature;

use App\Models\CouvertureMembre;
use App\Models\Delegation;
use App\Models\MembreFamille;
use App\Models\OrganismeAssurance;
use App\Models\User;
use App\Services\Assurance\ServiceCouvertures;
use App\Services\Referentiel\EmpreinteReferentiel;
use App\Services\Referentiel\SourceAssurances;
use Database\Seeders\PortailRolesSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\GouverneUnReferentiel;
use Tests\TestCase;

/**
 * P6.8d — Assurances et organismes agréés (CDC_09 §8, étape 8 du §14).
 *
 * Ce que ces vecteurs doivent tenir :
 *
 *  · une couverture est un CONTRAT entre une personne et un organisme, et il peut y en avoir
 *    plusieurs — trois colonnes `cmu_*` n'en portaient qu'une, et nommaient la CMU dans le schéma ;
 *  · le STATUT est CALCULÉ à partir des dates de la ligne, il ne se déclare plus, et `non_inscrit`
 *    n'existe plus : l'absence de couverture se dit par l'absence de ligne ;
 *  · le client ne peut poser NI le code national, NI `provenance = verifie` — cette dernière est
 *    réservée et inatteignable tant qu'aucune vérification auprès d'un organisme n'existe (F2) ;
 *  · le lien est relu à la VERSION PUBLIÉE, jamais à la table ni au client ; mais le NOM n'est PAS
 *    figé, à l'inverse de P6.6b / P6.7b / P6.8c — une couverture est un état courant, pas un fait
 *    historique ;
 *  · deux vecteurs en miroir sur l'empreinte, aucun ne suffisant seul : la déclaration d'un citoyen
 *    ne fait PAS diverger le registre, un agrément suspendu SI ;
 *  · le contrat de `GET /membres` (module P2, validé G5) survit **à l'identique**, par dérivation ;
 *  · l'écart hors référentiel est COMPTÉ et jamais bloqué (3ᵉ application du motif E4).
 */
class ReferentielAssurancesTest extends TestCase
{
    use GouverneUnReferentiel;
    use RefreshDatabase;

    private function source(): SourceAssurances
    {
        return new SourceAssurances();
    }

    /** Un organisme du contenu de travail, avec son code national posé de force. */
    private function organisme(array $remplacements = []): OrganismeAssurance
    {
        $organisme = new OrganismeAssurance();
        $organisme->fill(array_merge([
            'pays_code' => 'CI',
            'nom'       => 'Caisse Nationale d\'Assurance Maladie',
            'sigle'     => 'CNAM',
            'type'      => 'cnam',
            'source'    => 'demonstration',
            'actif'     => true,
        ], array_diff_key($remplacements, array_flip(['code']))));

        $organisme->forceFill(['code' => $remplacements['code'] ?? 'ASS000001'])->save();

        return $organisme->fresh();
    }

    private function membre(?User $user = null): MembreFamille
    {
        return MembreFamille::factory()->for($user ?? User::factory()->create())->create([
            'nom' => 'Kouassi', 'prenom' => 'Aya', 'date_naissance' => '1990-05-04', 'sexe' => 'F',
        ]);
    }

    /** Une couverture posée directement, sans passer par le chemin de déclaration. */
    private function couverture(MembreFamille $membre, array $attributs = []): CouvertureMembre
    {
        $couverture = new CouvertureMembre(array_merge([
            'numero_assure' => 'CMU-1234-5678-9012',
        ], $attributs));
        $couverture->membre_id = $membre->id;
        $couverture->save();

        return $couverture->fresh();
    }

    /** Un compte portant la permission nationale, accordée nominativement. */
    private function autorite(): User
    {
        $this->seed(PortailRolesSeeder::class);

        $user = User::factory()->create();
        $user->assignRole('gestionnaire_etablissement');
        $user->givePermissionTo('assurance.referentiel');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user->fresh();
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // Le code national — `ASS` + 6, et l'agrément est NATIONAL
    // ─────────────────────────────────────────────────────────────────────────────

    public function test_deux_organismes_du_meme_pays_ne_partagent_pas_un_code(): void
    {
        $this->organisme(['code' => 'ASS000001']);

        $this->expectException(QueryException::class);
        $this->organisme(['code' => 'ASS000001', 'nom' => 'Mutuelle de Démonstration']);
    }

    public function test_deux_pays_partagent_le_meme_code(): void
    {
        $this->organisme(['code' => 'ASS000001', 'pays_code' => 'CI']);

        // QUESTION REPOSÉE, PAS RECOPIÉE : P6.8c vient de rompre avec `pays_code` parce qu'une
        // maladie est un fait de nature. Un organisme d'assurance est une personne morale AGRÉÉE
        // PAR UN ÉTAT — son code est national, comme ETS, PRO, MED, ANA et VAC.
        $senegal = $this->organisme([
            'code' => 'ASS000001', 'pays_code' => 'SN', 'nom' => 'Institution de Prévoyance Maladie',
        ]);

        $this->assertSame('ASS000001', $senegal->code);
        $this->assertSame(2, OrganismeAssurance::where('code', 'ASS000001')->count());
    }

    public function test_deux_organismes_du_meme_pays_ne_partagent_pas_un_nom(): void
    {
        $this->organisme(['nom' => 'Mutuelle de Démonstration', 'type' => 'mutuelle']);

        // Ils seraient indiscernables dans la liste où un assuré choisit le sien — et ce choix
        // porte sur ce qu'il lit, pas sur un identifiant.
        $this->expectException(QueryException::class);
        $this->organisme(['code' => 'ASS000002', 'nom' => 'Mutuelle de Démonstration']);
    }

    public function test_le_backfill_attribue_les_codes_et_est_idempotent(): void
    {
        // Deux organismes SANS code — l'état dans lequel le seeder les laisse.
        $sans = new OrganismeAssurance();
        $sans->fill(['pays_code' => 'CI', 'nom' => 'Mutuelle A', 'type' => 'mutuelle', 'source' => 'demonstration']);
        $sans->save();

        $autre = new OrganismeAssurance();
        $autre->fill(['pays_code' => 'CI', 'nom' => 'Mutuelle B', 'type' => 'mutuelle', 'source' => 'demonstration']);
        $autre->save();

        // L'APERÇU ANNONCE EXACTEMENT CE QUE FERA LE PASSAGE RÉEL (leçon du G2 de P6.8a, où un
        // `--dry-run` annonçait 0 praticien avant que le réel n'en rattache 28).
        $this->artisan('masante:assurances:backfill --dry-run')
            ->expectsOutputToContain('2 organisme(s) recevraient un code national.')
            ->assertSuccessful();

        $this->assertSame(2, OrganismeAssurance::whereNull('code')->count());

        $this->artisan('masante:assurances:backfill')->assertSuccessful();

        $this->assertSame(['ASS000001', 'ASS000002'], OrganismeAssurance::orderBy('id')->pluck('code')->all());

        // Rejeu : aucune séquence consommée, aucun trou créé.
        $this->artisan('masante:assurances:backfill')
            ->expectsOutputToContain('Tous les organismes ont un code national')
            ->assertSuccessful();

        $this->assertSame(['ASS000001', 'ASS000002'], OrganismeAssurance::orderBy('id')->pluck('code')->all());
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // Les gardes du moteur (G4) — déclencheurs dans les deux dialectes
    // ─────────────────────────────────────────────────────────────────────────────

    public function test_le_moteur_refuse_un_agrement_qui_finit_avant_de_commencer(): void
    {
        $this->expectException(QueryException::class);

        $this->organisme([
            'agrement_debut' => '2026-12-31',
            'agrement_fin'   => '2026-01-01',
        ]);
    }

    public function test_le_moteur_refuse_une_couverture_qui_finit_avant_de_commencer(): void
    {
        $membre = $this->membre();

        $this->expectException(QueryException::class);

        $this->couverture($membre, [
            'organisme_libelle' => 'Mutuelle du village',
            'date_debut'        => '2026-12-31',
            'date_fin'          => '2026-01-01',
        ]);
    }

    public function test_le_moteur_refuse_une_couverture_qui_ne_nomme_aucun_organisme(): void
    {
        $membre = $this->membre();

        // « Je suis assuré » sans dire chez qui n'est pas une information : la ligne n'affirmerait
        // rien. Le formulaire le refuse aussi (`required_without`) — deux gardes, deux publics.
        $this->expectException(QueryException::class);

        $this->couverture($membre, ['organisme_assurance_id' => null, 'organisme_libelle' => null]);
    }

    public function test_supprimer_un_organisme_qui_couvre_des_assures_est_refuse(): void
    {
        $organisme = $this->organisme();
        $this->couverture($this->membre(), ['organisme_assurance_id' => $organisme->id]);

        // `restrictOnDelete` et non `nullOnDelete` : en `SET NULL`, les couvertures survivraient en
        // désignant le vide, et personne ne saurait plus chez qui ces gens étaient assurés. Le
        // chemin normal est la DÉSACTIVATION.
        $this->expectException(QueryException::class);
        $organisme->delete();
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // Le statut est un CALCUL — et `non_inscrit` n'existe plus
    // ─────────────────────────────────────────────────────────────────────────────

    public function test_le_statut_est_calcule_a_partir_des_dates_de_la_ligne(): void
    {
        $membre    = $this->membre();
        $organisme = $this->organisme();

        $sansFin = $this->couverture($membre, ['organisme_assurance_id' => $organisme->id]);
        $this->assertSame('active', $sansFin->statut);

        $echue = $this->couverture($membre, [
            'organisme_libelle' => 'Mutuelle échue',
            'date_fin'          => now()->subDay()->toDateString(),
        ]);
        $this->assertSame('expiree', $echue->statut);

        // ORDRE DÉLIBÉRÉ : une résiliation l'emporte sur une date de fin encore lointaine. Répondre
        // « expirée » à un contrat résilié dirait la bonne conclusion pour la mauvaise raison.
        $resiliee = $this->couverture($membre, [
            'organisme_libelle' => 'Mutuelle résiliée',
            'date_fin'          => now()->addYear()->toDateString(),
            'resiliee_le'       => now()->subMonth()->toDateString(),
        ]);
        $this->assertSame('resiliee', $resiliee->statut);
    }

    public function test_une_couverture_qui_finit_aujourdhui_vaut_encore(): void
    {
        $membre = $this->membre();

        // La comparaison porte sur le DÉBUT du jour : une couverture dont l'échéance est aujourd'hui
        // couvre encore la consultation d'aujourd'hui.
        $couverture = $this->couverture($membre, [
            'organisme_libelle' => 'Mutuelle du jour',
            'date_fin'          => now()->toDateString(),
        ]);

        $this->assertSame('active', $couverture->statut);
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // Ce que le client ne déclare pas (G2)
    // ─────────────────────────────────────────────────────────────────────────────

    public function test_le_client_ne_peut_pas_declarer_une_couverture_verifiee_par_http(): void
    {
        $user      = User::factory()->create();
        $membre    = $this->membre($user);
        $organisme = $this->organisme();
        // Publier AVANT d'avoir un contenu ferait refuser la publication : « le référentiel est
        // vide » est un contrôle qualité, pas un accident de harnais.
        $this->publierReferentiel(SourceAssurances::CODE);

        Sanctum::actingAs($user);

        $this->postJson("/api/v1/membres/{$membre->id}/couvertures", [
            'organisme_assurance_id' => $organisme->id,
            'provenance'             => 'verifie',
            'verifiee_le'            => now()->toDateTimeString(),
        ])->assertCreated()
            ->assertJsonPath('couverture.provenance', 'declare');

        $this->assertSame('declare', CouvertureMembre::first()->provenance);
        $this->assertNull(CouvertureMembre::first()->verifiee_le);
    }

    /**
     * LE MÊME VECTEUR, UNE COUCHE PLUS BAS — leçon des mutations de P6.6b.
     *
     * Le vecteur HTTP ci-dessus resterait vert si l'on retirait la garde du service : `validate()`
     * écarte déjà les clés non déclarées, donc il prouve le VALIDATEUR, pas le service. Celui-ci
     * appelle le service directement, comme le ferait un import.
     */
    public function test_le_service_efface_la_provenance_quel_que_soit_l_appelant(): void
    {
        $prepare = app(ServiceCouvertures::class)->preparer([
            'organisme_libelle' => 'Mutuelle du village',
            'provenance'        => 'verifie',
            'verifiee_le'       => now()->toDateTimeString(),
        ]);

        $this->assertArrayNotHasKey('provenance', $prepare);
        $this->assertArrayNotHasKey('verifiee_le', $prepare);

        $couverture = app(ServiceCouvertures::class)->enregistrer($this->membre(), $prepare);

        $this->assertSame('declare', $couverture->provenance);
    }

    public function test_une_modification_ne_peut_pas_non_plus_promouvoir_la_couverture(): void
    {
        $user   = User::factory()->create();
        $membre = $this->membre($user);
        $couverture = $this->couverture($membre, ['organisme_libelle' => 'Mutuelle du village']);

        Sanctum::actingAs($user);

        // *Une garantie qui ne vaudrait que sur l'un des chemins n'en serait pas une* — leçon
        // P6.8b, où le `update()` avait été oublié.
        $this->putJson("/api/v1/membres/{$membre->id}/couvertures/{$couverture->id}", [
            'organisme_libelle' => 'Mutuelle du village',
            'provenance'        => 'verifie',
        ])->assertOk()
            ->assertJsonPath('couverture.provenance', 'declare');
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // Le lien est relu à la version PUBLIÉE — et le nom n'est PAS figé
    // ─────────────────────────────────────────────────────────────────────────────

    public function test_un_organisme_absent_de_la_version_en_vigueur_est_refuse(): void
    {
        $this->organisme(); // la CNAM, elle, est publiée
        $this->publierReferentiel(SourceAssurances::CODE);

        $user   = User::factory()->create();
        $membre = $this->membre($user);
        // Créé APRÈS la publication : il existe en table, pas dans la version en vigueur.
        $organisme = $this->organisme([
            'code' => 'ASS000002', 'nom' => 'Mutuelle jamais publiée', 'type' => 'mutuelle',
        ]);

        Sanctum::actingAs($user);

        $reponse = $this->postJson("/api/v1/membres/{$membre->id}/couvertures", [
            'organisme_assurance_id' => $organisme->id,
        ])->assertStatus(422)
            // Refus BRUYANT, attribué au champ rempli : accepter en lisant la table rendrait la
            // gouvernance décorative.
            ->assertJsonValidationErrors('organisme_assurance_id');

        // Et le message NOMME l'organisme : un refus qui ne dit pas de quoi il parle oblige à
        // deviner. (`assertSee` ne convient pas ici — la réponse JSON échappe les accents.)
        $this->assertStringContainsString(
            'Mutuelle jamais publiée',
            (string) $reponse->json('errors.organisme_assurance_id.0'),
        );
    }

    public function test_le_nom_de_l_organisme_suit_le_referentiel_et_n_est_pas_fige(): void
    {
        $organisme = $this->organisme(['nom' => 'Mutuelle de Démonstration', 'type' => 'mutuelle']);
        $user      = User::factory()->create();
        $membre    = $this->membre($user);
        $this->couverture($membre, ['organisme_assurance_id' => $organisme->id]);

        // RUPTURE ASSUMÉE avec P6.6b / P6.7b / P6.8c, et la raison est de nature : ceux-là
        // inscrivaient un fait HISTORIQUE dans un carnet. Une couverture est un ÉTAT COURANT — si
        // l'organisme est renommé, afficher l'ancien nom ferait porter à l'assuré un nom que le
        // guichet ne reconnaît plus.
        $organisme->update(['nom' => 'Mutuelle Générale de Démonstration']);

        Sanctum::actingAs($user);

        $this->getJson("/api/v1/membres/{$membre->id}/couvertures")
            ->assertOk()
            ->assertJsonPath('couvertures.0.organisme_nom', 'Mutuelle Générale de Démonstration');
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // Le repli hors référentiel (motif E4) — compté, jamais bloqué
    // ─────────────────────────────────────────────────────────────────────────────

    public function test_une_couverture_hors_referentiel_est_acceptee_et_signalee(): void
    {
        $user   = User::factory()->create();
        $membre = $this->membre($user);
        Sanctum::actingAs($user);

        // Le registre livré est un jeu de démonstration : refuser ferait payer NOS lacunes à un
        // assuré réel — le « mur » refusé en P6.8c.
        $this->postJson("/api/v1/membres/{$membre->id}/couvertures", [
            'organisme_libelle' => 'Mutuelle du village',
        ])->assertCreated()
            ->assertJsonPath('couverture.hors_referentiel', true)
            ->assertJsonPath('couverture.organisme_nom', 'Mutuelle du village')
            ->assertJsonPath('avertissements.0.code', 'organisme_hors_referentiel');
    }

    public function test_une_couverture_doit_nommer_son_organisme(): void
    {
        $user   = User::factory()->create();
        $membre = $this->membre($user);
        Sanctum::actingAs($user);

        $this->postJson("/api/v1/membres/{$membre->id}/couvertures", [
            'numero_assure' => 'X-1',
        ])->assertStatus(422)->assertJsonValidationErrors('organisme_libelle');
    }

    public function test_un_lien_efface_le_libelle_libre(): void
    {
        $user      = User::factory()->create();
        $membre    = $this->membre($user);
        $organisme = $this->organisme();
        $this->publierReferentiel(SourceAssurances::CODE);

        Sanctum::actingAs($user);

        $this->postJson("/api/v1/membres/{$membre->id}/couvertures", [
            'organisme_assurance_id' => $organisme->id,
            'organisme_libelle'      => 'Un autre nom',
        ])->assertCreated()->assertJsonPath('couverture.hors_referentiel', false);

        // Les deux ensemble seraient DEUX VÉRITÉS sur la même ligne, et le lecteur choisirait
        // laquelle croire (motif P6.7b).
        $this->assertNull(CouvertureMembre::first()->organisme_libelle);
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // Une seule couverture vivante par organisme — garde APPLICATIVE, et c'est dit
    // ─────────────────────────────────────────────────────────────────────────────

    public function test_deux_couvertures_vivantes_chez_le_meme_organisme_sont_refusees(): void
    {
        $user      = User::factory()->create();
        $membre    = $this->membre($user);
        $organisme = $this->organisme();
        $this->couverture($membre, ['organisme_assurance_id' => $organisme->id]);

        $this->expectException(ValidationException::class);

        app(ServiceCouvertures::class)->enregistrer($membre, [
            'organisme_assurance_id' => $organisme->id,
        ]);
    }

    public function test_une_couverture_echue_ne_bloque_pas_un_nouveau_contrat(): void
    {
        $membre    = $this->membre();
        $organisme = $this->organisme();
        $this->couverture($membre, [
            'organisme_assurance_id' => $organisme->id,
            'date_fin'               => now()->subMonth()->toDateString(),
        ]);

        // Un assuré qui reprend un contrat chez le même organisme doit pouvoir le déclarer, et son
        // historique doit rester lisible.
        $neuve = app(ServiceCouvertures::class)->enregistrer($membre, [
            'organisme_assurance_id' => $organisme->id,
        ]);

        $this->assertSame('active', $neuve->statut);
        $this->assertSame(2, $membre->couvertures()->count());
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // Habilitation et anti-IDOR
    // ─────────────────────────────────────────────────────────────────────────────

    public function test_le_dossier_d_un_autre_compte_est_refuse(): void
    {
        $membre = $this->membre();
        Sanctum::actingAs(User::factory()->create());

        $this->getJson("/api/v1/membres/{$membre->id}/couvertures")->assertForbidden();
    }

    public function test_une_couverture_d_un_autre_membre_repond_404(): void
    {
        $user       = User::factory()->create();
        $membre     = $this->membre($user);
        $autre      = $this->membre($user);
        $couverture = $this->couverture($autre, ['organisme_libelle' => 'Mutuelle du village']);

        Sanctum::actingAs($user);

        // 404, jamais une modification transversale (même principe que les sections du carnet).
        $this->deleteJson("/api/v1/membres/{$membre->id}/couvertures/{$couverture->id}")
            ->assertNotFound();
    }

    public function test_un_delegue_en_lecture_consulte_mais_ne_souscrit_pas(): void
    {
        $proprietaire = User::factory()->create();
        $delegue      = User::factory()->create();
        $membre       = $this->membre($proprietaire);
        $this->couverture($membre, ['organisme_libelle' => 'Mutuelle du village']);

        Delegation::create([
            'titulaire_user_id' => $proprietaire->id,
            'delegue_user_id'   => $delegue->id,
            'membre_id'         => $membre->id,
            'droits'            => 'lecture',
            'invitee_at'        => now(),
            'acceptee_at'       => now(),
        ]);

        Sanctum::actingAs($delegue);

        // Un délégué lit le carnet (P7-A) ; il ne souscrit pas au nom d'autrui.
        $this->getJson("/api/v1/membres/{$membre->id}/couvertures")->assertOk();
        $this->postJson("/api/v1/membres/{$membre->id}/couvertures", [
            'organisme_libelle' => 'Mutuelle du délégué',
        ])->assertForbidden();
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // Le contrat P2 survit — par DÉRIVATION, pas par recopie
    // ─────────────────────────────────────────────────────────────────────────────

    public function test_les_valeurs_cmu_sont_derivees_de_la_couverture_cnam(): void
    {
        $user      = User::factory()->create();
        $membre    = $this->membre($user);
        $organisme = $this->organisme(); // type `cnam`

        $this->couverture($membre, [
            'organisme_assurance_id' => $organisme->id,
            'numero_assure'          => 'CMU-1234-5678-9012',
            'date_fin'               => now()->addYear()->toDateString(),
        ]);

        Sanctum::actingAs($user);

        $this->getJson("/api/v1/membres/{$membre->id}")
            ->assertOk()
            // Ni les clés, ni le vocabulaire, ni le format n'ont bougé.
            ->assertJsonPath('membre.cmu_statut', 'actif')
            ->assertJsonPath('membre.cmu_numero_masque', '•••• •••• 9012')
            ->assertJsonMissingPath('membre.cmu_numero');
    }

    public function test_sans_couverture_le_membre_repond_non_inscrit(): void
    {
        $user   = User::factory()->create();
        $membre = $this->membre($user);
        Sanctum::actingAs($user);

        // L'absence de couverture se dit par l'absence de ligne ; le contrat P2 attend une valeur,
        // et c'est LE SEUL endroit où `non_inscrit` subsiste.
        $this->getJson("/api/v1/membres/{$membre->id}")
            ->assertOk()
            ->assertJsonPath('membre.cmu_statut', 'non_inscrit')
            ->assertJsonPath('membre.cmu_numero_masque', null);
    }

    public function test_une_couverture_privee_ne_devient_pas_la_carte_cmu(): void
    {
        $user   = User::factory()->create();
        $membre = $this->membre($user);
        $mutuelle = $this->organisme(['nom' => 'Mutuelle de Démonstration', 'type' => 'mutuelle']);

        $this->couverture($membre, [
            'organisme_assurance_id' => $mutuelle->id,
            'numero_assure'          => 'MUT-0000-1111',
        ]);

        Sanctum::actingAs($user);

        // LE TYPE FAIT FOI, PAS LE NOM : « CMU » est le régime, la CNAM l'organisme qui le gère.
        // Une mutuelle ne doit pas se présenter comme une carte CMU.
        $this->getJson("/api/v1/membres/{$membre->id}")
            ->assertOk()
            ->assertJsonPath('membre.cmu_statut', 'non_inscrit');
    }

    public function test_la_carte_porte_l_organisme_et_la_mention_de_provenance(): void
    {
        $user      = User::factory()->create();
        $membre    = $this->membre($user);
        $organisme = $this->organisme();
        $this->couverture($membre, ['organisme_assurance_id' => $organisme->id]);

        Sanctum::actingAs($user);

        $this->getJson("/api/v1/membres/{$membre->id}/carte-cmu")
            ->assertOk()
            ->assertJsonPath('carte.organisme', 'Caisse Nationale d\'Assurance Maladie')
            ->assertJsonPath('carte.organisme_sigle', 'CNAM')
            // La correction du SEUL défaut corrigeable ici : le mot. Aucune vérification auprès de
            // la CNAM n'existe dans ce projet, et l'écran ne doit pas laisser croire le contraire.
            ->assertJsonPath('carte.mention_provenance', CouvertureMembre::MENTION_PROVENANCE);
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // Les deux vecteurs en miroir — aucun ne suffit seul
    // ─────────────────────────────────────────────────────────────────────────────

    public function test_miroir_une_declaration_de_citoyen_ne_fait_pas_diverger_le_registre(): void
    {
        $organisme = $this->organisme();
        $avant     = EmpreinteReferentiel::duContenu($this->source()->extraire());

        $this->couverture($this->membre(), ['organisme_assurance_id' => $organisme->id]);

        // La projection prend la LIGNE ENTIÈRE, et elle ne peut le rester que si rien n'écrit
        // automatiquement dans la table. Aucun compteur d'assurés n'y a été ajouté — il aurait été
        // utile à l'écran, il aurait rendu cette phrase fausse (précaution née de `note_moyenne`).
        $this->assertSame($avant, EmpreinteReferentiel::duContenu($this->source()->extraire()));
    }

    public function test_miroir_un_agrement_suspendu_fait_diverger_le_registre(): void
    {
        $organisme = $this->organisme(['agrement_statut' => 'valide']);
        $avant     = EmpreinteReferentiel::duContenu($this->source()->extraire());

        $organisme->update(['agrement_statut' => 'suspendu']);

        // C'est un acte d'autorité : il change ce qu'un guichet lit, et il doit passer par le
        // quatre-yeux du §10.
        $this->assertNotSame($avant, EmpreinteReferentiel::duContenu($this->source()->extraire()));
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // Contrôles qualité §10
    // ─────────────────────────────────────────────────────────────────────────────

    public function test_la_qualite_refuse_une_entree_sans_provenance(): void
    {
        $erreurs = $this->source()->controlerQualite([[
            'code' => 'ASS000001', 'pays_code' => 'CI', 'nom' => 'CNAM', 'type' => 'cnam',
            'source' => null, 'actif' => true,
        ]]);

        $this->assertNotEmpty(array_filter($erreurs, fn (string $e): bool => str_contains($e, 'provenance')));
    }

    public function test_la_qualite_n_exige_aucun_numero_d_agrement(): void
    {
        $erreurs = $this->source()->controlerQualite([[
            'code' => 'ASS000001', 'pays_code' => 'CI', 'nom' => 'CNAM', 'type' => 'cnam',
            'numero_agrement' => null, 'source' => 'demonstration', 'actif' => true,
        ]]);

        // *Un contrôle qu'on ne peut pas satisfaire n'est pas une exigence, c'est un mur* : aucun
        // numéro d'agrément n'a été chargé dans ce projet, et l'exiger rendrait le référentiel
        // impubliable dès le premier jour. L'absence est comptée et affichée, jamais bloquante.
        $this->assertSame([], $erreurs);
    }

    public function test_la_qualite_refuse_un_registre_sans_aucun_organisme_actif(): void
    {
        $erreurs = $this->source()->controlerQualite([[
            'code' => 'ASS000001', 'pays_code' => 'CI', 'nom' => 'CNAM', 'type' => 'cnam',
            'source' => 'demonstration', 'actif' => false,
        ]]);

        $this->assertNotEmpty(array_filter(
            $erreurs,
            fn (string $e): bool => str_contains($e, 'hors référentiel'),
        ));
    }

    public function test_la_qualite_compte_le_pays_dans_la_cle_du_doublon(): void
    {
        $ci = ['code' => 'ASS000001', 'pays_code' => 'CI', 'nom' => 'CNAM', 'type' => 'cnam',
            'source' => 'demonstration', 'actif' => true];
        $sn = ['code' => 'ASS000001', 'pays_code' => 'SN', 'nom' => 'IPM', 'type' => 'cnam',
            'source' => 'demonstration', 'actif' => true];

        // Le contrôle doit être AUSSI STRICT QUE LE MOTEUR, ni plus ni moins : le G2 de P6.5a a
        // montré ce que coûte un contrôle plus strict — un référentiel impubliable dès le 2ᵉ pays.
        $this->assertSame([], $this->source()->controlerQualite([$ci, $sn]));

        $this->assertNotEmpty($this->source()->controlerQualite([$ci, $ci]));
    }

    public function test_la_qualite_refuse_un_agrement_incoherent(): void
    {
        $erreurs = $this->source()->controlerQualite([[
            'code' => 'ASS000001', 'pays_code' => 'CI', 'nom' => 'CNAM', 'type' => 'cnam',
            'agrement_debut' => '2026-12-31', 'agrement_fin' => '2026-01-01',
            'source' => 'demonstration', 'actif' => true,
        ]]);

        // Le déclencheur refuse déjà d'ÉCRIRE ces dates ; ce contrôle attrape le même défaut arrivé
        // par un autre chemin — un import, une base restaurée d'ailleurs.
        $this->assertNotEmpty(array_filter(
            $erreurs,
            fn (string $e): bool => str_contains($e, 'avant de commencer'),
        ));
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // La diffusion — la table n'est jamais servie directement
    // ─────────────────────────────────────────────────────────────────────────────

    public function test_l_api_refuse_bruyamment_tant_qu_aucune_version_n_est_en_vigueur(): void
    {
        $this->organisme();

        // Un repli sur la table laisserait un oubli de publication INVISIBLE : tout fonctionnerait,
        // et personne ne saurait la garantie inactive (leçon L1+L2).
        $this->getJson('/api/v1/assurances')->assertStatus(503);
    }

    public function test_un_update_direct_reste_sans_effet_avant_publication(): void
    {
        $organisme = $this->organisme();
        $this->publierReferentiel(SourceAssurances::CODE);

        $this->getJson('/api/v1/assurances')
            ->assertOk()
            ->assertJsonPath('organismes.0.nom', 'Caisse Nationale d\'Assurance Maladie');

        $organisme->update(['nom' => 'Nom changé en douce']);
        $this->simulerNouvelleRequete();

        // C'est le but du §1.2.4 : la diffusion suit les versions, pas les écritures.
        $this->getJson('/api/v1/assurances')
            ->assertOk()
            ->assertJsonPath('organismes.0.nom', 'Caisse Nationale d\'Assurance Maladie');

        $this->republierReferentiel(SourceAssurances::CODE, 'Correction du nom.');

        $this->getJson('/api/v1/assurances')
            ->assertOk()
            ->assertJsonPath('organismes.0.nom', 'Nom changé en douce');
    }

    public function test_l_api_sert_les_libelles_des_familles(): void
    {
        $this->organisme();
        $this->publierReferentiel(SourceAssurances::CODE);

        // 4ᵉ récidive évitée du constat G-a de P6.4b : aucun client ne recopie ces libellés.
        $this->getJson('/api/v1/assurances')
            ->assertOk()
            ->assertJsonPath('types.0.code', 'cnam')
            ->assertJsonPath('organismes.0.type_libelle', 'Régime national (CNAM / CMU)')
            // La réponse dit ce que cette liste NE prouve PAS.
            ->assertJsonPath('organismes.0.numero_agrement', null)
            ->assertJsonStructure(['avertissement', 'limites', 'version']);
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // La permission n'est portée par aucun rôle (11ᵉ occurrence)
    // ─────────────────────────────────────────────────────────────────────────────

    public function test_un_gestionnaire_d_etablissement_n_edite_pas_le_registre(): void
    {
        $this->seed(PortailRolesSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $gestionnaire = User::factory()->create();
        $gestionnaire->assignRole('gestionnaire_etablissement');

        // Le rôle `assurance` désigne PRÉCISÉMENT les organismes que ce registre recense ; le
        // gestionnaire, lui, gère les conventions de SON établissement. Ni l'un ni l'autre ne
        // décide de la liste nationale des agréés.
        $this->actingAs($gestionnaire)->get('/portail/assurances')->assertForbidden();
    }

    public function test_un_agent_habilite_edite_le_registre(): void
    {
        $this->actingAs($this->autorite())->get('/portail/assurances')->assertOk();
    }

    public function test_le_formulaire_n_accepte_ni_le_code_national_ni_le_numero_d_agrement(): void
    {
        $this->actingAs($this->autorite())
            ->post('/portail/assurances', [
                'nom'             => 'Mutuelle de Démonstration',
                'type'            => 'mutuelle',
                'source'          => 'demonstration',
                'code'            => 'ASS999999',
                'numero_agrement' => 'AGR-INVENTE-001',
            ])->assertRedirect();

        $organisme = OrganismeAssurance::first();

        // Un client ne choisit pas un code national, il le reçoit. Et taper un numéro d'agrément
        // reviendrait à FABRIQUER un agrément plutôt qu'à l'enregistrer.
        $this->assertNull($organisme->code);
        $this->assertNull($organisme->numero_agrement);
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // La bascule des colonnes héritées
    // ─────────────────────────────────────────────────────────────────────────────

    public function test_le_backfill_transpose_les_colonnes_heritees(): void
    {
        $this->organisme(); // la CNAM, cible de la transposition
        $membre = MembreFamille::factory()
            ->for(User::factory()->create())
            ->avecCmuHerite('actif', 'CMU-9999-8888-7777', '2030-06-30')
            ->create();

        $this->artisan('masante:couvertures:backfill --dry-run')
            ->expectsOutputToContain('1 membre(s) recevraient une couverture')
            ->assertSuccessful();

        $this->assertSame(0, CouvertureMembre::count());

        $this->artisan('masante:couvertures:backfill')->assertSuccessful();

        $couverture = CouvertureMembre::first();
        $this->assertSame($membre->id, $couverture->membre_id);
        $this->assertSame('2030-06-30', $couverture->date_fin->toDateString());
        // Chiffré au repos, exactement comme `cmu_numero` l'était.
        $this->assertNotSame('CMU-9999-8888-7777', $couverture->getRawOriginal('numero_assure'));
        $this->assertSame('•••• •••• 7777', $couverture->numero_masque);

        // Les colonnes d'origine ne sont PAS effacées (ADR-024) : une erreur de transposition
        // reste rattrapable.
        $this->assertSame('actif', $membre->fresh()->getRawOriginal('cmu_statut'));

        // Rejeu : idempotent.
        $this->artisan('masante:couvertures:backfill')->assertSuccessful();
        $this->assertSame(1, CouvertureMembre::count());
    }

    public function test_le_backfill_approxime_une_expiration_sans_date_et_le_dit(): void
    {
        $this->organisme();
        MembreFamille::factory()
            ->for(User::factory()->create())
            ->avecCmuHerite('expire', 'CMU-1', null)
            ->create();

        $this->artisan('masante:couvertures:backfill')
            ->expectsOutputToContain('cette date est une')
            ->assertSuccessful();

        // Laisser la date vide ferait calculer « active » — le système CONTREDIRAIT l'assuré, qui a
        // lui-même déclaré sa couverture expirée. La veille dit « elle ne vaut plus », au prix
        // d'une date de fin approximative, annoncée comme telle et corrigeable.
        $this->assertSame('expiree', CouvertureMembre::first()->statut);
    }

    public function test_le_backfill_ne_tranche_pas_une_declaration_contradictoire(): void
    {
        $this->organisme();
        MembreFamille::factory()
            ->for(User::factory()->create())
            ->avecCmuHerite('non_inscrit', 'CMU-CONTRADICTOIRE', null)
            ->create();

        $this->artisan('masante:couvertures:backfill')
            ->expectsOutputToContain('se contredisent')
            ->assertSuccessful();

        // La déclaration dit « pas de couverture », le numéro dit le contraire. Leur en fabriquer
        // une trancherait à la place de l'assuré.
        $this->assertSame(0, CouvertureMembre::count());
    }

    public function test_le_backfill_echoue_bruyamment_sans_organisme_cnam(): void
    {
        MembreFamille::factory()
            ->for(User::factory()->create())
            ->avecCmuHerite()
            ->create();

        // Échec bruyant plutôt qu'un rattachement à un organisme inventé.
        $this->artisan('masante:couvertures:backfill')->assertFailed();
        $this->assertSame(0, CouvertureMembre::count());
    }
}
