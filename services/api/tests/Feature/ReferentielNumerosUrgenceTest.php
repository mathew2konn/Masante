<?php

namespace Tests\Feature;

use App\Models\AlerteSos;
use App\Models\NumeroUrgence;
use App\Models\Symptome;
use App\Models\User;
use App\Services\Referentiel\EmpreinteReferentiel;
use App\Services\Referentiel\SourceNumerosUrgence;
use App\Services\Referentiel\SourceSymptomesTriage;
use App\Services\TriageService;
use App\Services\Urgence\ServiceNumerosUrgence;
use App\Support\RegistreReferentiels;
use Database\Seeders\NumeroUrgenceSeeder;
use Database\Seeders\PortailRolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\GouverneUnReferentiel;
use Tests\TestCase;

/**
 * P6.8e — Numéros d'urgence nationaux (CDC_09 §8, étape 8 du §14).
 *
 * Ce que ces vecteurs doivent tenir :
 *
 *  · **plus aucun « 185 » en dur hors du repli déclaré** — le triage lit le référentiel publié, et
 *    publier une version corrigée change bien le texte qu'un patient lit (CDC_02 §37) ;
 *  · **le repli joue, et il est TRACÉ** — c'est le seul point du projet où la disponibilité passe
 *    devant la traçabilité, et il ne passe que parce qu'un avertissement est écrit à chaque fois ;
 *  · **le serveur reste honnête** — sans version publiée, l'API répond 503 et ne sert JAMAIS la
 *    table de travail en se faisant passer pour le référentiel ;
 *  · **un `UPDATE` direct est sans effet** avant publication (garantie L1+L2, rejouée ici) ;
 *  · **deux vecteurs en miroir**, aucun ne suffisant seul : déclencher un SOS ne fait PAS diverger
 *    l'empreinte, modifier un numéro SI ;
 *  · **le contrôle qualité refuse une version sans secours joignable** — publier une liste tout
 *    inactif ferait retomber les téléphones sur la valeur compilée sans que personne ne l'ait
 *    décidé ;
 *  · **aucun repli inventé** pour les autres secours : un numéro faux est plus dangereux qu'un
 *    numéro absent, parce qu'il sera composé ;
 *  · l'écriture est fermée à tout rôle métier — `urgence.referentiel` n'est portée par aucun.
 */
class ReferentielNumerosUrgenceTest extends TestCase
{
    use RefreshDatabase;
    use GouverneUnReferentiel;

    private function source(): SourceNumerosUrgence
    {
        return new SourceNumerosUrgence();
    }

    private function service(): ServiceNumerosUrgence
    {
        return app(ServiceNumerosUrgence::class);
    }

    /** Un compte portant la permission nationale, accordée nominativement. */
    private function autorite(): User
    {
        $this->seed(PortailRolesSeeder::class);

        $user = User::factory()->create();
        $user->assignRole('gestionnaire_etablissement');
        $user->givePermissionTo('urgence.referentiel');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user->fresh();
    }

    private function numero(array $remplacements = []): NumeroUrgence
    {
        $entree = new NumeroUrgence();
        $entree->fill(array_merge([
            'numero' => '185', 'libelle' => 'SAMU', 'description' => 'Secours médical.',
            'ordre' => 10, 'actif' => true, 'source' => 'declaration_projet',
        ], array_diff_key($remplacements, array_flip(['code', 'pays_code']))));
        $entree->forceFill([
            'code'      => $remplacements['code'] ?? 'samu',
            'pays_code' => $remplacements['pays_code'] ?? 'CI',
        ]);
        $entree->save();

        return $entree;
    }

    // ═════════════════════════════════════════════════════════════════════════════════════════
    // Le schéma et la garde du moteur
    // ═════════════════════════════════════════════════════════════════════════════════════════

    public function test_le_referentiel_est_au_registre_des_referentiels_gouvernes(): void
    {
        $this->assertTrue(RegistreReferentiels::existe(SourceNumerosUrgence::CODE));
        $this->assertInstanceOf(
            SourceNumerosUrgence::class,
            RegistreReferentiels::source(SourceNumerosUrgence::CODE),
        );
    }

    public function test_le_moteur_refuse_un_numero_vide_a_l_insertion(): void
    {
        // *Un numéro d'urgence vide est un bouton qui ne compose rien.* La garde vit dans le moteur
        // parce qu'un import ou une restauration ne passe par aucun formulaire.
        $this->expectException(\Illuminate\Database\QueryException::class);

        DB::table('numeros_urgence')->insert([
            'pays_code' => 'CI', 'code' => 'samu', 'numero' => '   ', 'libelle' => 'SAMU',
            'ordre' => 10, 'actif' => 1, 'source' => 'declaration_projet',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_le_moteur_refuse_un_numero_vide_a_la_mise_a_jour(): void
    {
        $entree = $this->numero();

        // La garde porte sur les DEUX événements : vider un numéro existant est le chemin le plus
        // plausible en exploitation, et il serait le plus silencieux.
        $this->expectException(\Illuminate\Database\QueryException::class);

        DB::table('numeros_urgence')->where('id', $entree->id)->update(['numero' => '']);
    }

    public function test_deux_numeros_ne_peuvent_pas_partager_un_code_dans_le_meme_pays(): void
    {
        $this->numero();

        $this->expectException(\Illuminate\Database\QueryException::class);

        $this->numero(['code' => 'samu', 'numero' => '999']);
    }

    public function test_deux_pays_peuvent_porter_le_meme_code(): void
    {
        // Un numéro d'urgence n'existe QUE dans un plan de numérotation national : le même terme
        // `samu` désigne deux valeurs différentes selon le pays, et c'est le cas nominal.
        $this->numero(['pays_code' => 'CI', 'numero' => '185']);
        $this->numero(['pays_code' => 'SN', 'numero' => '1515']);

        $this->assertSame(2, NumeroUrgence::where('code', 'samu')->count());
    }

    // ═════════════════════════════════════════════════════════════════════════════════════════
    // Les contrôles qualité §10
    // ═════════════════════════════════════════════════════════════════════════════════════════

    public function test_le_jeu_seede_passe_les_controles_qualite(): void
    {
        $this->seed(NumeroUrgenceSeeder::class);

        $this->assertSame([], $this->source()->controlerQualite($this->source()->extraire()));
    }

    public function test_le_seeder_est_idempotent(): void
    {
        $this->seed(NumeroUrgenceSeeder::class);
        $premier = NumeroUrgence::count();

        $this->seed(NumeroUrgenceSeeder::class);

        $this->assertSame($premier, NumeroUrgence::count());
        $this->assertSame(3, $premier);
    }

    public function test_aucun_numero_seede_ne_pretend_venir_d_une_autorite(): void
    {
        $this->seed(NumeroUrgenceSeeder::class);

        // Le SAMU vient du corpus, le 100 et le 180 d'une déclaration du propriétaire : aucun n'a
        // été confronté à un arrêté. Les ranger sous `autorite_nationale` affirmerait une
        // vérification qui n'a pas eu lieu.
        $this->assertSame(0, NumeroUrgence::whereIn('source', ['autorite_nationale', 'publication'])->count());
        $this->assertSame(3, NumeroUrgence::where('source', 'declaration_projet')->count());
    }

    public function test_le_controle_refuse_un_numero_non_composable(): void
    {
        $this->numero(['numero' => 'SAMU']);

        $erreurs = $this->source()->controlerQualite($this->source()->extraire());

        $this->assertNotEmpty($erreurs);
        $this->assertStringContainsString('composable', implode(' ', $erreurs));
    }

    public function test_le_controle_accepte_un_numero_international(): void
    {
        // Le `+` doit passer : ce référentiel est multi-pays par construction, et un numéro écrit
        // en forme internationale n'est pas une anomalie.
        $this->numero(['numero' => '+225 27 20 00 00 00']);

        $this->assertSame([], $this->source()->controlerQualite($this->source()->extraire()));
    }

    public function test_le_controle_refuse_une_provenance_inconnue(): void
    {
        $contenu = [[
            'code' => 'samu', 'pays_code' => 'CI', 'numero' => '185', 'libelle' => 'SAMU',
            'description' => null, 'ordre' => 10, 'actif' => true,
            'source' => 'entendu_dire', 'source_detail' => null,
        ]];

        $this->assertStringContainsString(
            'provenance',
            implode(' ', $this->source()->controlerQualite($contenu)),
        );
    }

    public function test_le_controle_refuse_une_version_ou_plus_rien_n_est_joignable(): void
    {
        // LE CONTRÔLE CENTRAL DU MODULE. Publier une liste tout inactif ne casserait rien de
        // visible : les téléphones retomberaient sur la valeur compilée, en silence, sans que
        // personne ne l'ait décidé.
        $this->numero(['actif' => false]);

        $erreurs = $this->source()->controlerQualite($this->source()->extraire());

        $this->assertNotEmpty($erreurs);
        $this->assertStringContainsString('Aucun numéro actif', implode(' ', $erreurs));
    }

    public function test_le_controle_refuse_un_doublon_de_code(): void
    {
        // Aussi strict que `uq_numero_urgence_pays_code`, ni plus ni moins — leçon du G2 de P6.5a,
        // où un contrôle plus strict que le moteur rendait le référentiel impubliable.
        $contenu = [
            ['code' => 'samu', 'pays_code' => 'CI', 'numero' => '185', 'libelle' => 'SAMU', 'actif' => true, 'source' => 'declaration_projet'],
            ['code' => 'samu', 'pays_code' => 'CI', 'numero' => '186', 'libelle' => 'SAMU bis', 'actif' => true, 'source' => 'declaration_projet'],
        ];

        $this->assertStringContainsString('Doublon', implode(' ', $this->source()->controlerQualite($contenu)));
    }

    public function test_le_controle_accepte_le_meme_code_dans_deux_pays(): void
    {
        $contenu = [
            ['code' => 'samu', 'pays_code' => 'CI', 'numero' => '185', 'libelle' => 'SAMU', 'actif' => true, 'source' => 'declaration_projet'],
            ['code' => 'samu', 'pays_code' => 'SN', 'numero' => '1515', 'libelle' => 'SAMU', 'actif' => true, 'source' => 'declaration_projet'],
        ];

        $this->assertSame([], $this->source()->controlerQualite($contenu));
    }

    public function test_le_controle_refuse_un_referentiel_vide(): void
    {
        $this->assertNotEmpty($this->source()->controlerQualite([]));
    }

    // ═════════════════════════════════════════════════════════════════════════════════════════
    // Les deux vecteurs en miroir — aucun ne suffit seul
    // ═════════════════════════════════════════════════════════════════════════════════════════

    public function test_miroir_declencher_un_sos_ne_fait_pas_diverger_l_empreinte(): void
    {
        $this->seed(NumeroUrgenceSeeder::class);
        $avant = EmpreinteReferentiel::duContenu($this->source()->extraire());

        AlerteSos::create([
            'user_id' => User::factory()->create()->id,
            'canal'   => 'appel',
        ]);

        $this->assertSame(
            $avant,
            EmpreinteReferentiel::duContenu($this->source()->extraire()),
            'Un SOS est un fait individuel : le référentiel national ne doit pas bouger. La table '
            .'est construite pour cela — elle ne porte AUCUN compteur d\'appels.',
        );
    }

    public function test_miroir_modifier_un_numero_fait_diverger_l_empreinte(): void
    {
        $this->seed(NumeroUrgenceSeeder::class);
        $avant = EmpreinteReferentiel::duContenu($this->source()->extraire());

        NumeroUrgence::where('code', 'samu')->update(['numero' => '186']);

        $this->assertNotSame(
            $avant,
            EmpreinteReferentiel::duContenu($this->source()->extraire()),
            'Changer un numéro composé est exactement ce que la gouvernance doit voir.',
        );
    }

    // ═════════════════════════════════════════════════════════════════════════════════════════
    // Le service : la chaîne de repli, et sa trace
    // ═════════════════════════════════════════════════════════════════════════════════════════

    public function test_sans_version_publiee_le_service_replie_sur_la_valeur_livree(): void
    {
        $this->seed(NumeroUrgenceSeeder::class);
        NumeroUrgence::where('code', 'samu')->update(['numero' => '999']);

        // La table dit « 999 », mais rien n'est publié : le service ne sert JAMAIS la table de
        // travail — il replie sur la valeur livrée avec l'application.
        $this->assertSame(ServiceNumerosUrgence::REPLI['numero'], $this->service()->numero('samu'));
        $this->assertFalse($this->service()->estEnVigueur());
        $this->assertNull($this->service()->version());
        $this->assertSame([], $this->service()->actifs());
    }

    public function test_le_repli_est_journalise(): void
    {
        // C'EST CE VECTEUR QUI REND LE REPLI ACCEPTABLE. Sans trace, la disponibilité gagnerait
        // contre la traçabilité en silence — et un oubli de publication resterait invisible.
        Log::spy();

        $this->service()->numero('samu');

        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message): bool => str_contains($message, 'repli'))
            ->once();
    }

    public function test_le_repli_n_est_journalise_qu_une_fois_par_requete(): void
    {
        // Trois lignes identiques feraient dire au journal « il s'est passé beaucoup de choses »
        // au lieu de « une version manque » : c'est ainsi qu'un avertissement devient invisible.
        Log::spy();

        $service = $this->service();
        $service->numero('samu');
        $service->numero('samu');
        $service->actifs();

        Log::shouldHaveReceived('warning')->once();
    }

    public function test_estEnVigueur_ne_replie_ni_ne_journalise(): void
    {
        // L'écran du portail lit CETTE méthode pour annoncer « aucune version en vigueur » : si
        // elle repliait, elle mentirait à l'exploitant exactement là où il attend la vérité brute.
        Log::spy();

        $this->assertFalse($this->service()->estEnVigueur());

        Log::shouldNotHaveReceived('warning');
    }

    public function test_apres_publication_le_service_sert_la_version_publiee(): void
    {
        $this->seed(NumeroUrgenceSeeder::class);
        $version = $this->publierReferentiel(SourceNumerosUrgence::CODE);

        $this->assertSame('185', $this->service()->numero('samu'));
        $this->assertTrue($this->service()->estEnVigueur());
        $this->assertSame($version, $this->service()->version());
        $this->assertCount(3, $this->service()->actifs());
    }

    public function test_un_update_direct_reste_sans_effet_avant_publication(): void
    {
        // La garantie de L1+L2, rejouée ici : corriger un numéro par un `UPDATE` direct n'a aucun
        // effet tant que deux personnes ne l'ont pas décidé.
        $this->seed(NumeroUrgenceSeeder::class);
        $this->publierReferentiel(SourceNumerosUrgence::CODE);

        NumeroUrgence::where('code', 'samu')->update(['numero' => '999']);
        $this->simulerNouvelleRequete();

        $this->assertSame('185', $this->service()->numero('samu'));
    }

    public function test_publier_une_version_corrigee_change_bien_le_numero(): void
    {
        // Le jumeau du vecteur précédent : sans lui, « rien ne change jamais » satisferait les deux.
        $this->seed(NumeroUrgenceSeeder::class);
        $this->publierReferentiel(SourceNumerosUrgence::CODE);

        NumeroUrgence::where('code', 'samu')->update(['numero' => '186']);
        $this->republierReferentiel(SourceNumerosUrgence::CODE, 'Renumérotation du secours médical.');

        $this->assertSame('186', $this->service()->numero('samu'));
    }

    public function test_un_numero_desactive_n_est_plus_servi(): void
    {
        $this->seed(NumeroUrgenceSeeder::class);
        $this->publierReferentiel(SourceNumerosUrgence::CODE);

        NumeroUrgence::where('code', 'police')->update(['actif' => false]);
        $this->republierReferentiel(SourceNumerosUrgence::CODE, 'Retrait du numéro de police.');

        $codes = array_column($this->service()->actifs(), 'code');

        $this->assertNotContains('police', $codes);
        $this->assertContains('samu', $codes);
    }

    public function test_aucun_repli_n_est_invente_pour_les_autres_secours(): void
    {
        // *Un numéro d'urgence faux est plus dangereux qu'un numéro absent, parce qu'il sera
        // composé.* Le service refuse d'en fabriquer un, même pour un code qu'il connaît.
        $this->expectException(\RuntimeException::class);

        $this->service()->numero('pompiers');
    }

    public function test_le_service_rend_les_numeros_dans_l_ordre_du_referentiel(): void
    {
        // L'ordre n'est pas décoratif : c'est lui qui met le secours médical en tête sur une
        // application de santé.
        $this->seed(NumeroUrgenceSeeder::class);
        $this->publierReferentiel(SourceNumerosUrgence::CODE);

        $this->assertSame(
            ['samu', 'pompiers', 'police'],
            array_column($this->service()->actifs(), 'code'),
        );
    }

    // ═════════════════════════════════════════════════════════════════════════════════════════
    // Le triage — le site (1) du G0
    // ═════════════════════════════════════════════════════════════════════════════════════════

    public function test_le_texte_de_triage_porte_le_numero_publie(): void
    {
        $this->seed(NumeroUrgenceSeeder::class);
        NumeroUrgence::where('code', 'samu')->update(['numero' => '186']);
        $this->publierReferentiel(SourceNumerosUrgence::CODE);

        $symptome = Symptome::create([
            'nom_fr' => 'Douleur thoracique', 'categorie' => 'cardiaque',
            'poids_severite' => 90, 'drapeau_rouge' => true, 'actif' => true,
        ]);

        // P10a — Le triage lit désormais SA propre version publiée. Ce vecteur ne porte pas sur ce
        // point ; il faut seulement que le triage puisse s'exécuter pour observer son texte.
        $this->publierReferentiel(SourceSymptomesTriage::CODE);

        $resultat = app(TriageService::class)->analyser([$symptome->id]);

        // Le numéro vient du référentiel, plus d'une constante du service (CDC_02 §37).
        $this->assertStringContainsString('186', $resultat['recommandation_texte']);
        $this->assertStringNotContainsString('185', $resultat['recommandation_texte']);
    }

    public function test_le_texte_de_triage_reste_utilisable_sans_version_publiee(): void
    {
        // Un texte de triage URGENT sans numéro de secours serait pire que le défaut qu'on referme.
        $symptome = Symptome::create([
            'nom_fr' => 'Douleur thoracique', 'categorie' => 'cardiaque',
            'poids_severite' => 90, 'drapeau_rouge' => true, 'actif' => true,
        ]);

        // « Sans version publiée » porte ici sur les NUMÉROS D'URGENCE — c'est le sujet du vecteur,
        // et ils restent délibérément non publiés. Le référentiel des SYMPTÔMES, lui, est mis en
        // vigueur : sans lui le triage refuse de s'exécuter (P10a) et on n'observerait aucun texte.
        // Deux référentiels, deux politiques, et c'est exactement ce que P6.8e a décidé : le repli
        // du numéro vit côté client, celui du triage n'existe pas.
        $this->publierReferentiel(SourceSymptomesTriage::CODE);

        $resultat = app(TriageService::class)->analyser([$symptome->id]);

        $this->assertStringContainsString('185', $resultat['recommandation_texte']);
    }

    // ═════════════════════════════════════════════════════════════════════════════════════════
    // L'API publique
    // ═════════════════════════════════════════════════════════════════════════════════════════

    public function test_l_api_refuse_honnetement_avant_la_premiere_publication(): void
    {
        $this->seed(NumeroUrgenceSeeder::class);

        // Le serveur ne sert jamais la table de travail en se faisant passer pour le référentiel :
        // c'est le CLIENT qui porte le repli.
        $this->getJson('/api/v1/numeros-urgence')->assertStatus(503);
    }

    public function test_l_api_est_publique_et_sert_la_version_publiee(): void
    {
        $this->seed(NumeroUrgenceSeeder::class);
        $version = $this->publierReferentiel(SourceNumerosUrgence::CODE);

        // SANS JETON : la carte vitale d'urgence s'ouvre depuis l'écran de connexion, pour un
        // secouriste qui n'a pas de compte.
        $this->getJson('/api/v1/numeros-urgence')
            ->assertOk()
            ->assertJsonPath('version', $version)
            ->assertJsonPath('numeros.0.code', 'samu')
            ->assertJsonPath('numeros.0.numero', '185')
            ->assertJsonCount(3, 'numeros');
    }

    public function test_l_api_expose_la_provenance(): void
    {
        // Une application qui affiche un numéro d'urgence doit pouvoir dire d'où il vient si on le
        // lui demande — même si l'écran SOS, lui, n'en montre rien (décision C1).
        $this->seed(NumeroUrgenceSeeder::class);
        $this->publierReferentiel(SourceNumerosUrgence::CODE);

        $this->getJson('/api/v1/numeros-urgence')
            ->assertOk()
            ->assertJsonPath('numeros.0.source', 'declaration_projet');
    }

    // ═════════════════════════════════════════════════════════════════════════════════════════
    // Le portail
    // ═════════════════════════════════════════════════════════════════════════════════════════

    public function test_un_gestionnaire_sans_la_permission_est_refuse(): void
    {
        $this->seed(PortailRolesSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $user = User::factory()->create();
        $user->assignRole('gestionnaire_etablissement');

        $this->actingAs($user->fresh())->get('/portail/numeros-urgence')->assertForbidden();
    }

    public function test_l_autorite_accede_a_l_ecran(): void
    {
        $this->seed(NumeroUrgenceSeeder::class);

        $this->actingAs($this->autorite())->get('/portail/numeros-urgence')->assertOk();
    }

    /**
     * LA GARDE DU MODÈLE, ÉPROUVÉE SANS PASSER PAR HTTP.
     *
     * Ce vecteur est le JUMEAU de celui qui suit, et il existe parce que le premier essai n'a rien
     * prouvé : la mutation « `code` redevient `$fillable` » **a survécu** à toute la suite. La raison
     * est que `validate()` écarte déjà les clés non déclarées, si bien que le vecteur HTTP prouvait
     * le **validateur**, pas le modèle.
     *
     * C'est la QUATRIÈME instance du piège relevé en P6.6b, puis P6.7b, puis P6.8d. La parade est la
     * même : **une couche, un vecteur** — celui-ci appelle le modèle directement, comme le ferait un
     * import ou une commande, c'est-à-dire par le chemin où il n'y a aucun validateur devant.
     */
    public function test_le_code_national_n_est_pas_assignable_en_masse(): void
    {
        $entree = $this->numero(['code' => 'samu']);

        $entree->update(['code' => 'usurpe', 'pays_code' => 'SN', 'numero' => '186']);

        $entree->refresh();

        $this->assertSame('samu', $entree->code);
        $this->assertSame('CI', $entree->pays_code);
        // Le reste de la mise à jour doit avoir eu lieu : la garde est ciblée, pas globale.
        $this->assertSame('186', $entree->numero);
    }

    public function test_le_client_ne_choisit_pas_le_code_national_a_la_modification(): void
    {
        // Le jumeau HTTP du vecteur ci-dessus : il prouve que le validateur n'accepte pas ces clés.
        // À lui seul il ne prouve PAS `$fillable` — voir le commentaire du vecteur précédent.
        $entree = $this->numero(['code' => 'samu']);

        $this->actingAs($this->autorite())
            ->put("/portail/numeros-urgence/{$entree->id}", [
                'code' => 'usurpe', 'pays_code' => 'SN',
                'numero' => '186', 'libelle' => 'SAMU', 'ordre' => 10,
                'source' => 'declaration_projet', 'actif' => 1,
            ])
            ->assertRedirect();

        $entree->refresh();

        $this->assertSame('samu', $entree->code);
        $this->assertSame('CI', $entree->pays_code);
        $this->assertSame('186', $entree->numero);
    }

    public function test_le_portail_refuse_un_numero_non_composable(): void
    {
        // Passage de la détection à l'interdiction : au formulaire, l'agent a encore l'information
        // sous les yeux (motif du contrôle région/district de P6.4d).
        $entree = $this->numero();

        $this->actingAs($this->autorite())
            ->put("/portail/numeros-urgence/{$entree->id}", [
                'numero' => 'SAMU', 'libelle' => 'SAMU', 'ordre' => 10,
                'source' => 'declaration_projet', 'actif' => 1,
            ])
            ->assertSessionHasErrors('numero');

        $this->assertSame('185', $entree->fresh()->numero);
    }

    public function test_le_portail_cree_un_numero_avec_son_code(): void
    {
        $this->actingAs($this->autorite())
            ->post('/portail/numeros-urgence', [
                'code' => 'antipoison', 'numero' => '+225 27 20 00 00 00',
                'libelle' => 'Centre antipoison', 'ordre' => 40,
                'source' => 'autorite_nationale', 'source_detail' => 'Arrêté n° X',
            ])
            ->assertRedirect();

        $cree = NumeroUrgence::where('code', 'antipoison')->firstOrFail();

        $this->assertSame('+225 27 20 00 00 00', $cree->numero);
        $this->assertSame('CI', $cree->pays_code);
        $this->assertTrue($cree->actif);
    }

    public function test_le_portail_refuse_un_code_deja_pris(): void
    {
        $this->numero(['code' => 'samu']);

        $this->actingAs($this->autorite())
            ->post('/portail/numeros-urgence', [
                'code' => 'samu', 'numero' => '999', 'libelle' => 'Doublon', 'ordre' => 50,
                'source' => 'declaration_projet',
            ])
            ->assertSessionHasErrors('code');

        $this->assertSame(1, NumeroUrgence::where('code', 'samu')->count());
    }

    public function test_le_portail_refuse_un_code_hors_forme(): void
    {
        $this->actingAs($this->autorite())
            ->post('/portail/numeros-urgence', [
                'code' => 'SAMU 185', 'numero' => '185', 'libelle' => 'SAMU', 'ordre' => 10,
                'source' => 'declaration_projet',
            ])
            ->assertSessionHasErrors('code');

        $this->assertSame(0, NumeroUrgence::count());
    }
}
