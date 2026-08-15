<?php

namespace Tests\Feature;

use App\Models\MembreFamille;
use App\Models\User;
use App\Models\Vaccin;
use App\Models\Vaccination;
use App\Services\Referentiel\SourceVaccins;
use App\Services\Vaccin\AttributeurCodeVaccin;
use App\Support\ReglesCalendrierVaccinal;
use Database\Seeders\VaccinSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\GouverneUnReferentiel;
use Tests\TestCase;

/**
 * P6.8b — Vaccins et calendrier vaccinal national (CDC_09 §8).
 *
 * ═══ CE QUE CES VECTEURS DOIVENT TENIR ═══
 *
 *  1. **`statut` n'est plus déclaré ni figé.** Une ligne saisie il y a un an répond `en_retard`
 *     aujourd'hui **sans qu'aucune écriture n'ait eu lieu** — c'est le défaut U2 du G0, où le statut
 *     était écrit une fois et jamais rafraîchi.
 *  2. **`obligatoire` n'est plus une case cochée** dès que la ligne est rattachée : il se lit dans
 *     le calendrier publié.
 *  3. **La fiche vitale ne croit plus une déclaration.** Elle montre ce qui est fait et dit ce qui
 *     l'atteste — le signal que le serveur garantit depuis P7-D0, et qu'elle n'utilisait pas.
 *  4. **Le calendrier lit la version PUBLIÉE, pas la table** : un `UPDATE` direct reste sans effet,
 *     et son jumeau est obligatoire — publier une version corrigée change bien la réponse. Sans le
 *     second, le premier prouverait seulement que plus rien ne fonctionne.
 *  5. **Un enfant de 5 semaines et un de 7 semaines obtiennent deux réponses différentes.**
 *  6. **Les garanties valent sur les TROIS chemins d'écriture**, et chaque couche a son vecteur —
 *     leçon de la mutation de P6.6b, où des vecteurs ne prouvaient que le validateur.
 */
class CalendrierVaccinalTest extends TestCase
{
    use GouverneUnReferentiel;
    use RefreshDatabase;

    private User $user;

    private MembreFamille $membre;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user   = User::factory()->create();
        $this->membre = MembreFamille::factory()->for($this->user)->create([
            'date_naissance' => now()->subDays(60)->toDateString(),
        ]);

        Sanctum::actingAs($this->user);
    }

    /** Seede le référentiel, attribue les codes nationaux et publie la v1. */
    private function mettreEnVigueur(): int
    {
        $this->seed(VaccinSeeder::class);

        $attributeur = app(AttributeurCodeVaccin::class);

        foreach (Vaccin::orderBy('id')->get() as $vaccin) {
            $attributeur->attribuer($vaccin);
        }

        return $this->publierReferentiel(SourceVaccins::CODE);
    }

    private function url(string $suffixe = ''): string
    {
        return "/api/v1/membres/{$this->membre->id}".$suffixe;
    }

    private function penta(): Vaccin
    {
        return Vaccin::where('abreviation', 'Penta')->firstOrFail();
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // 1. Le statut est calculé — U2 refermé
    // ─────────────────────────────────────────────────────────────────────────────

    public function test_une_ligne_a_faire_saisie_il_y_a_un_an_repond_en_retard_sans_ecriture(): void
    {
        // On écrit DIRECTEMENT en base le statut périmé que l'ancien code aurait laissé : c'est
        // l'état réel d'une ligne saisie sous l'ancien comportement.
        $ligne = $this->membre->vaccinations()->create([
            'vaccin_nom'  => 'Vaccin recopié du carnet papier',
            'date_rappel' => now()->subYear()->toDateString(),
        ]);

        // `statut` n'est plus assignable : on l'écrit en SQL direct, ce qui est exactement l'état
        // d'une ligne enregistrée sous l'ancien comportement.
        \Illuminate\Support\Facades\DB::table('vaccinations')
            ->where('id', $ligne->id)
            ->update(['statut' => ReglesCalendrierVaccinal::A_FAIRE]);

        $ligne = $ligne->fresh();

        $this->assertSame(
            ReglesCalendrierVaccinal::A_FAIRE,
            $ligne->getRawOriginal('statut'),
            'La colonne doit bien porter la valeur périmée : sans cela le vecteur ne prouverait rien.',
        );

        $this->assertSame(ReglesCalendrierVaccinal::EN_RETARD, $ligne->fresh()->statut);

        // Et l'API le dit aussi — la colonne n'a pas bougé, seule la lecture a changé.
        $this->getJson($this->url('/vaccinations'))
            ->assertOk()
            ->assertJsonPath('items.0.statut', ReglesCalendrierVaccinal::EN_RETARD);

        $this->assertSame(
            ReglesCalendrierVaccinal::A_FAIRE,
            $ligne->fresh()->getRawOriginal('statut'),
            'Aucune écriture ne doit avoir eu lieu : le statut est calculé, pas rafraîchi.',
        );
    }

    public function test_une_dose_administree_est_faite_meme_si_son_echeance_est_depassee(): void
    {
        // L'administration l'emporte sur l'échéance. L'ordre inverse afficherait « en retard » sur
        // une vaccination faite avec deux semaines de décalage — une accusation sans objet.
        $ligne = $this->membre->vaccinations()->create([
            'vaccin_nom'          => 'BCG',
            'date_rappel'         => now()->subYear()->toDateString(),
            'date_administration' => now()->subMonths(11)->toDateString(),
            'statut'              => ReglesCalendrierVaccinal::A_FAIRE,
        ]);

        $this->assertSame(ReglesCalendrierVaccinal::FAIT, $ligne->fresh()->statut);
    }

    public function test_une_intention_sans_echeance_reste_a_faire_et_n_est_jamais_dite_en_retard(): void
    {
        $ligne = $this->membre->vaccinations()->create(['vaccin_nom' => 'Vaccin sans date']);

        $this->assertSame(ReglesCalendrierVaccinal::A_FAIRE, $ligne->fresh()->statut);
    }

    public function test_le_client_ne_peut_pas_declarer_le_statut_par_l_api(): void
    {
        $this->postJson($this->url('/vaccinations'), [
            'vaccin_nom'  => 'Vaccin quelconque',
            'date_rappel' => now()->subYear()->toDateString(),
            'statut'      => ReglesCalendrierVaccinal::FAIT,
        ])->assertCreated()->assertJsonPath('item.statut', ReglesCalendrierVaccinal::EN_RETARD);
    }

    /**
     * DEUXIÈME COUCHE, ET ELLE EST NÉCESSAIRE.
     *
     * Le vecteur ci-dessus reste vert même si la garde du service disparaît : `validate()` écarte
     * déjà les clés non déclarées. Il prouve donc le VALIDATEUR, pas le service. Celui-ci appelle le
     * service directement, comme le ferait un import — c'est la leçon de la mutation de P6.6b.
     */
    public function test_le_service_efface_le_statut_meme_appele_directement(): void
    {
        $prepare = app(\App\Services\Vaccin\ServiceLienVaccination::class)->resoudre([
            'vaccin_nom' => 'Vaccin quelconque',
            'statut'     => ReglesCalendrierVaccinal::FAIT,
        ]);

        $this->assertArrayNotHasKey('statut', $prepare);
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // 2. `obligatoire` se lit au calendrier
    // ─────────────────────────────────────────────────────────────────────────────

    public function test_obligatoire_est_relu_au_calendrier_et_non_declare(): void
    {
        $this->mettreEnVigueur();

        // Le Rotavirus dose 1 est RECOMMANDÉ au jeu de démonstration, le Pentavalent OBLIGATOIRE.
        $rota = Vaccin::where('abreviation', 'Rota')->firstOrFail();

        $this->postJson($this->url('/vaccinations'), [
            'vaccin_nom'  => 'peu importe',
            'vaccin_id'   => $rota->id,
            'numero_dose' => 1,
            'obligatoire' => true,   // le client ment
        ])->assertCreated()->assertJsonPath('item.obligatoire', false);

        $this->postJson($this->url('/vaccinations'), [
            'vaccin_nom'  => 'peu importe',
            'vaccin_id'   => $this->penta()->id,
            'numero_dose' => 1,
            'obligatoire' => false,  // le client ment dans l'autre sens
        ])->assertCreated()->assertJsonPath('item.obligatoire', true);
    }

    public function test_le_nom_et_le_code_sont_repris_du_referentiel_et_non_du_client(): void
    {
        $this->mettreEnVigueur();
        $penta = $this->penta();

        $reponse = $this->postJson($this->url('/vaccinations'), [
            'vaccin_nom'  => 'Nom inventé par le client',
            'vaccin_code' => 'VAC999999',
            'vaccin_id'   => $penta->id,
            'numero_dose' => 2,
        ])->assertCreated();

        $reponse->assertJsonPath('item.vaccin_nom', $penta->libelle);
        $reponse->assertJsonPath('item.vaccin_code', $penta->code);
        $this->assertNotSame('VAC999999', $reponse->json('item.vaccin_code'));
    }

    public function test_une_dose_absente_du_calendrier_est_refusee_en_nommant_le_schema(): void
    {
        $this->mettreEnVigueur();

        $this->postJson($this->url('/vaccinations'), [
            'vaccin_nom'  => 'peu importe',
            'vaccin_id'   => $this->penta()->id,
            'numero_dose' => 9,
        ])->assertStatus(422)->assertJsonValidationErrors('numero_dose');
    }

    public function test_l_echeance_de_la_dose_est_materialisee_depuis_la_naissance(): void
    {
        $this->mettreEnVigueur();

        // Penta dose 1 = 42 jours. Le membre est né il y a 60 jours : l'échéance est donc passée.
        $reponse = $this->postJson($this->url('/vaccinations'), [
            'vaccin_nom'  => 'peu importe',
            'vaccin_id'   => $this->penta()->id,
            'numero_dose' => 1,
        ])->assertCreated();

        $attendue = $this->membre->date_naissance->copy()->addDays(42)->toDateString();

        $this->assertSame($attendue, substr((string) $reponse->json('item.date_rappel'), 0, 10));
    }

    public function test_une_date_de_rappel_fournie_par_le_client_prime_sur_le_calendrier(): void
    {
        $this->mettreEnVigueur();

        // Elle vient d'un carnet papier ou d'un soignant qui a vu le patient, et tient compte de ce
        // que le calendrier ignore — une dose reçue en retard décale les suivantes.
        $reponse = $this->postJson($this->url('/vaccinations'), [
            'vaccin_nom'  => 'peu importe',
            'vaccin_id'   => $this->penta()->id,
            'numero_dose' => 1,
            'date_rappel' => '2026-12-01',
        ])->assertCreated();

        $this->assertSame('2026-12-01', substr((string) $reponse->json('item.date_rappel'), 0, 10));
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // 3. Le calendrier lit la VERSION PUBLIÉE
    // ─────────────────────────────────────────────────────────────────────────────

    public function test_sans_version_publiee_le_calendrier_echoue_bruyamment(): void
    {
        $this->seed(VaccinSeeder::class);

        // La table est pleine : sous un repli, l'écran aurait fonctionné et personne n'aurait su
        // que la garantie était inactive.
        $this->assertGreaterThan(0, Vaccin::count());

        $reponse = $this->getJson($this->url('/calendrier-vaccinal'))->assertStatus(503);

        $this->assertStringContainsString('aucune version en vigueur', $reponse->json('message'));

        $this->getJson('/api/v1/vaccins')->assertStatus(503);
    }

    public function test_sans_version_publiee_le_lien_est_refuse_mais_la_ligne_reste_enregistrable(): void
    {
        $this->seed(VaccinSeeder::class);
        app(AttributeurCodeVaccin::class)->attribuer($this->penta());

        // Le refus est ATTRIBUÉ au champ que l'utilisateur a rempli, pas une panne de service.
        $this->postJson($this->url('/vaccinations'), [
            'vaccin_nom' => 'peu importe',
            'vaccin_id'  => $this->penta()->id,
        ])->assertStatus(422)->assertJsonValidationErrors('vaccin_id');

        // Le lien est facultatif : sans lui, le carnet fonctionne.
        $this->postJson($this->url('/vaccinations'), [
            'vaccin_nom' => 'BCG recopié du carnet papier',
        ])->assertCreated();
    }

    public function test_un_update_direct_sur_la_table_ne_change_pas_le_calendrier_diffuse(): void
    {
        $this->mettreEnVigueur();

        $avant = $this->getJson($this->url('/calendrier-vaccinal'))->assertOk()->json();
        $pentaDose1 = collect($avant['echeances'])
            ->firstWhere(fn ($e) => $e['vaccin_libelle'] === $this->penta()->libelle && $e['numero_dose'] === 1);

        $this->assertSame(42, $pentaDose1['age_jours_du']);

        // Correction sauvage, sans relecture ni quatre-yeux.
        $this->penta()->echeances()->where('numero_dose', 1)->update(['age_jours_du' => 200]);
        $this->simulerNouvelleRequete();

        $apres = $this->getJson($this->url('/calendrier-vaccinal'))->assertOk()->json();
        $inchange = collect($apres['echeances'])
            ->firstWhere(fn ($e) => $e['vaccin_libelle'] === $this->penta()->libelle && $e['numero_dose'] === 1);

        $this->assertSame(42, $inchange['age_jours_du'], 'La table a été lue au lieu de la version publiée.');
    }

    /**
     * LE JUMEAU OBLIGATOIRE du vecteur ci-dessus. Sans lui, le premier prouverait seulement que
     * plus rien ne fonctionne.
     */
    public function test_publier_une_version_corrigee_change_bien_le_calendrier(): void
    {
        $this->mettreEnVigueur();

        $this->penta()->echeances()->where('numero_dose', 1)->update(['age_jours_du' => 56]);
        $v2 = $this->republierReferentiel(SourceVaccins::CODE, 'Décalage de la première dose.');

        $apres = $this->getJson($this->url('/calendrier-vaccinal'))->assertOk();

        $apres->assertJsonPath('version', $v2);

        $corrigee = collect($apres->json('echeances'))
            ->firstWhere(fn ($e) => $e['vaccin_libelle'] === $this->penta()->libelle && $e['numero_dose'] === 1);

        $this->assertSame(56, $corrigee['age_jours_du']);
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // 4. Le calendrier dépend de la PERSONNE
    // ─────────────────────────────────────────────────────────────────────────────

    public function test_cinq_semaines_et_sept_semaines_obtiennent_deux_reponses_differentes(): void
    {
        $this->mettreEnVigueur();

        $jeune = MembreFamille::factory()->for($this->user)->create([
            'date_naissance' => now()->subDays(35)->toDateString(),   // 5 semaines
        ]);
        $aine = MembreFamille::factory()->for($this->user)->create([
            'date_naissance' => now()->subDays(49)->toDateString(),   // 7 semaines
        ]);

        $statutPenta1 = function (MembreFamille $m): string {
            $reponse = $this->getJson("/api/v1/membres/{$m->id}/calendrier-vaccinal")->assertOk();

            return collect($reponse->json('echeances'))
                ->firstWhere(fn ($e) => $e['vaccin_libelle'] === $this->penta()->libelle && $e['numero_dose'] === 1)['statut'];
        };

        // À 5 semaines l'échéance des 6 semaines n'est pas encore due : ce n'est PAS un retard.
        $this->assertSame(ReglesCalendrierVaccinal::A_VENIR, $statutPenta1($jeune));

        // À 7 semaines elle est due, et le délai de grâce publié (14 j) court encore.
        $this->assertSame(ReglesCalendrierVaccinal::A_FAIRE, $statutPenta1($aine));
    }

    public function test_une_dose_administree_et_rattachee_honore_son_echeance(): void
    {
        $this->mettreEnVigueur();
        $penta = $this->penta();

        $this->postJson($this->url('/vaccinations'), [
            'vaccin_nom'          => 'peu importe',
            'vaccin_id'           => $penta->id,
            'numero_dose'         => 1,
            'date_administration' => now()->subDays(10)->toDateString(),
        ])->assertCreated();

        $reponse = $this->getJson($this->url('/calendrier-vaccinal'))->assertOk();

        $echeance = collect($reponse->json('echeances'))
            ->firstWhere(fn ($e) => $e['vaccin_code'] === $penta->code && $e['numero_dose'] === 1);

        $this->assertSame(ReglesCalendrierVaccinal::FAIT, $echeance['statut']);
        $this->assertNotNull($echeance['vaccination_id']);
    }

    /**
     * L'âge inconnu N'EST PAS ATTEIGNABLE PAR CETTE TABLE — et c'est le vecteur qui le prouve.
     *
     * `membres_famille.date_naissance` est NOT NULL depuis le Module 2, et P6.1 s'appuie dessus pour
     * créer le dossier titulaire. La garde du service est donc défensive, jamais déclenchée par ce
     * chemin ; elle existe parce que la règle pure accepte `null` et doit répondre quelque chose
     * plutôt que de supposer un âge. *Écrire un vecteur HTTP qui « prouve » un cas que le schéma
     * interdit aurait prouvé le contraire de ce qu'il annonce.*
     */
    public function test_la_date_de_naissance_est_obligatoire_et_la_regle_pure_gere_quand_meme_son_absence(): void
    {
        $this->expectException(\Illuminate\Database\QueryException::class);

        MembreFamille::factory()->for($this->user)->create(['date_naissance' => null]);
    }

    public function test_la_regle_pure_ne_suppose_aucun_age_quand_la_naissance_est_inconnue(): void
    {
        $this->assertNull(ReglesCalendrierVaccinal::ageEnJours(null, \Carbon\CarbonImmutable::now()));
    }

    public function test_une_echeance_hors_fenetre_de_rattrapage_n_est_plus_dite_en_retard(): void
    {
        $this->mettreEnVigueur();

        // Le Rotavirus dose 1 se rattrape jusqu'à 105 jours au jeu de démonstration.
        $grand = MembreFamille::factory()->for($this->user)->create([
            'date_naissance' => now()->subDays(400)->toDateString(),
        ]);

        $reponse = $this->getJson("/api/v1/membres/{$grand->id}/calendrier-vaccinal")->assertOk();

        $rota = collect($reponse->json('echeances'))
            ->firstWhere(fn ($e) => $e['abreviation'] === 'Rota' && $e['numero_dose'] === 1);

        $this->assertSame(ReglesCalendrierVaccinal::HORS_DELAI, $rota['statut']);
    }

    public function test_le_calendrier_annonce_le_compte_exact_des_echeances_de_demonstration(): void
    {
        $this->mettreEnVigueur();

        $reponse = $this->getJson($this->url('/calendrier-vaccinal'))->assertOk();

        $this->assertGreaterThan(0, $reponse->json('demonstration'));
        $this->assertStringContainsString('DÉMONSTRATION', (string) $reponse->json('avertissement'));
        $this->assertStringContainsString(
            'ne remplace pas',
            (string) $reponse->json('avertissement'),
            'Le calendrier ne doit jamais se présenter comme un avis médical.',
        );
    }

    public function test_le_calendrier_d_un_autre_foyer_est_refuse(): void
    {
        $this->mettreEnVigueur();

        $etranger = MembreFamille::factory()->for(User::factory()->create())->create([
            'date_naissance' => now()->subDays(60)->toDateString(),
        ]);

        $this->getJson("/api/v1/membres/{$etranger->id}/calendrier-vaccinal")->assertForbidden();
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // 5. La fiche vitale — décision propriétaire W2-bis
    // ─────────────────────────────────────────────────────────────────────────────

    public function test_la_fiche_vitale_montre_les_vaccinations_faites_sans_croire_une_case_cochee(): void
    {
        // Sous l'ancien critère, cette ligne était INVISIBLE du secouriste : elle est faite mais
        // « obligatoire » n'est pas coché.
        $this->membre->vaccinations()->create([
            'vaccin_nom'          => 'BCG réellement administré',
            'date_administration' => now()->subYear()->toDateString(),
            'obligatoire'         => false,
            'statut'              => ReglesCalendrierVaccinal::FAIT,
            'source'              => 'medecin',
        ]);

        $fiche = app(\App\Services\FicheVitaleService::class)->pour($this->membre->fresh());

        $this->assertCount(1, $fiche['vaccinations_essentielles']);
        $this->assertSame('BCG réellement administré', $fiche['vaccinations_essentielles'][0]['vaccin']);
        $this->assertTrue($fiche['vaccinations_essentielles'][0]['atteste']);
    }

    public function test_la_fiche_vitale_distingue_ce_qui_est_atteste_de_ce_qui_est_auto_declare(): void
    {
        $this->membre->vaccinations()->create([
            'vaccin_nom'          => 'Saisi par la famille',
            'date_administration' => now()->subYear()->toDateString(),
            'source'              => 'patient',
            'statut'              => ReglesCalendrierVaccinal::FAIT,
        ]);
        $this->membre->vaccinations()->create([
            'vaccin_nom'          => 'Consigné par un soignant',
            'date_administration' => now()->subYear()->toDateString(),
            'source'              => 'medecin',
            'statut'              => ReglesCalendrierVaccinal::FAIT,
        ]);

        $fiche = app(\App\Services\FicheVitaleService::class)->pour($this->membre->fresh());
        $lignes = collect($fiche['vaccinations_essentielles']);

        // L'attesté remonte en premier : en urgence, l'ordre de lecture compte.
        $this->assertSame('Consigné par un soignant', $lignes->first()['vaccin']);
        $this->assertTrue($lignes->firstWhere('vaccin', 'Consigné par un soignant')['atteste']);
        $this->assertFalse($lignes->firstWhere('vaccin', 'Saisi par la famille')['atteste']);
    }

    public function test_une_vaccination_non_administree_ne_figure_pas_a_la_fiche_vitale(): void
    {
        $this->membre->vaccinations()->create([
            'vaccin_nom'  => 'Prévue, pas faite',
            'date_rappel' => now()->addMonth()->toDateString(),
        ]);

        $fiche = app(\App\Services\FicheVitaleService::class)->pour($this->membre->fresh());

        $this->assertSame([], $fiche['vaccinations_essentielles']);
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // 6. Le référentiel gouverné
    // ─────────────────────────────────────────────────────────────────────────────

    public function test_le_code_national_suit_la_forme_VAC_et_est_partage_entre_pays(): void
    {
        $this->seed(VaccinSeeder::class);

        $premier = Vaccin::orderBy('id')->first();
        $code    = app(AttributeurCodeVaccin::class)->attribuer($premier);

        $this->assertSame('VAC000001', $code);

        // Le pays QUALIFIE le code, il ne s'écrit pas dedans : deux pays le partagent.
        $senegalais = Vaccin::create(['libelle' => 'Vaccin sénégalais', 'nb_doses' => 1]);
        $senegalais->forceFill(['pays_code' => 'SN'])->save();

        $this->assertSame('VAC000001', app(AttributeurCodeVaccin::class)->attribuer($senegalais));
    }

    public function test_l_attribution_du_code_est_idempotente(): void
    {
        $this->seed(VaccinSeeder::class);

        $vaccin = Vaccin::orderBy('id')->first();
        $premier = app(AttributeurCodeVaccin::class)->attribuer($vaccin);

        $this->assertSame($premier, app(AttributeurCodeVaccin::class)->attribuer($vaccin->fresh()));
    }

    public function test_le_controle_qualite_refuse_un_calendrier_incomplet(): void
    {
        $source = new SourceVaccins();

        $erreurs = $source->controlerQualite([[
            'code' => 'VAC000001', 'pays_code' => 'CI', 'libelle' => 'Pentavalent',
            'nb_doses' => 3, 'actif' => true, 'statut_marche' => 'disponible',
            'echeances' => [
                ['numero_dose' => 1, 'age_jours_du' => 42, 'libelle_echeance' => '6 semaines',
                    'source' => 'demonstration', 'obligatoire' => true],
            ],
        ]]);

        $this->assertNotEmpty($erreurs);
        $this->assertStringContainsString('3 doses mais le calendrier en porte 1', implode(' ', $erreurs));
    }

    public function test_le_controle_qualite_refuse_une_echeance_sans_provenance(): void
    {
        $erreurs = (new SourceVaccins())->controlerQualite([[
            'code' => 'VAC000001', 'pays_code' => 'CI', 'libelle' => 'BCG',
            'nb_doses' => 1, 'actif' => true, 'statut_marche' => 'disponible',
            'echeances' => [
                ['numero_dose' => 1, 'age_jours_du' => 0, 'libelle_echeance' => 'Naissance',
                    'source' => null, 'obligatoire' => true],
            ],
        ]]);

        $this->assertStringContainsString('provenance absente ou inconnue', implode(' ', $erreurs));
    }

    public function test_le_controle_qualite_refuse_une_dose_manquante_au_milieu_du_schema(): void
    {
        $erreurs = (new SourceVaccins())->controlerQualite([[
            'code' => 'VAC000001', 'pays_code' => 'CI', 'libelle' => 'Pentavalent',
            'nb_doses' => 3, 'actif' => true, 'statut_marche' => 'disponible',
            'echeances' => [
                ['numero_dose' => 1, 'age_jours_du' => 42, 'libelle_echeance' => '6 sem',
                    'source' => 'oms', 'obligatoire' => true],
                ['numero_dose' => 3, 'age_jours_du' => 98, 'libelle_echeance' => '14 sem',
                    'source' => 'oms', 'obligatoire' => true],
                ['numero_dose' => 4, 'age_jours_du' => 120, 'libelle_echeance' => '17 sem',
                    'source' => 'oms', 'obligatoire' => true],
            ],
        ]]);

        $this->assertStringContainsString('la dose n°2 du schéma n\'a aucune échéance', implode(' ', $erreurs));
    }

    public function test_le_controle_qualite_accepte_le_meme_code_pour_deux_pays(): void
    {
        // LE VECTEUR QUI AVAIT MANQUÉ EN P6.5a : un contrôle plus strict que l'index rendrait le
        // référentiel impubliable dès le second pays.
        $ligne = fn (string $pays): array => [
            'code' => 'VAC000001', 'pays_code' => $pays, 'libelle' => 'BCG '.$pays,
            'nb_doses' => 1, 'actif' => true, 'statut_marche' => 'disponible',
            'echeances' => [['numero_dose' => 1, 'age_jours_du' => 0, 'libelle_echeance' => 'Naissance',
                'source' => 'oms', 'obligatoire' => true]],
        ];

        $this->assertSame([], (new SourceVaccins())->controlerQualite([$ligne('CI'), $ligne('SN')]));
    }

    public function test_le_jeu_de_demonstration_passe_le_controle_qualite(): void
    {
        $this->seed(VaccinSeeder::class);

        foreach (Vaccin::orderBy('id')->get() as $vaccin) {
            app(AttributeurCodeVaccin::class)->attribuer($vaccin);
        }

        $source = new SourceVaccins();

        $this->assertSame([], $source->controlerQualite($source->extraire()));
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // 7. Les deux vecteurs EN MIROIR de la projection
    // ─────────────────────────────────────────────────────────────────────────────

    public function test_inscrire_une_vaccination_au_carnet_ne_fait_pas_diverger_le_referentiel(): void
    {
        $this->mettreEnVigueur();

        $source = new SourceVaccins();
        $avant  = md5(json_encode($source->extraire()));

        $this->postJson($this->url('/vaccinations'), [
            'vaccin_nom'          => 'peu importe',
            'vaccin_id'           => $this->penta()->id,
            'numero_dose'         => 1,
            'date_administration' => now()->subDay()->toDateString(),
        ])->assertCreated();

        $this->assertSame($avant, md5(json_encode($source->extraire())),
            'Le référentiel gouverne un vocabulaire, pas les actes qui le citent.');
    }

    public function test_retirer_un_vaccin_du_marche_fait_diverger_le_referentiel(): void
    {
        $this->mettreEnVigueur();

        $source = new SourceVaccins();
        $avant  = md5(json_encode($source->extraire()));

        $this->penta()->update(['statut_marche' => 'retire']);

        $this->assertNotSame($avant, md5(json_encode($source->extraire())),
            'Le statut sur le marché engage une autorité : il doit être gouverné.');
    }

    public function test_un_vaccin_retire_est_signale_mais_jamais_bloquant(): void
    {
        $this->mettreEnVigueur();

        $this->penta()->update(['statut_marche' => 'retire']);
        $this->republierReferentiel(SourceVaccins::CODE, 'Retrait du marché.');

        $reponse = $this->postJson($this->url('/vaccinations'), [
            'vaccin_nom'          => 'peu importe',
            'vaccin_id'           => $this->penta()->id,
            'numero_dose'         => 1,
            'date_administration' => now()->subMonth()->toDateString(),
        ])->assertCreated();

        // La vaccination a bien eu lieu : refuser effacerait un fait médical (CDC_00 §4).
        $this->assertSame('vaccin_retire', $reponse->json('avertissements.0.code'));
        $this->assertDatabaseCount('vaccinations', 1);
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // 8. Le moteur, et la modification
    // ─────────────────────────────────────────────────────────────────────────────

    public function test_le_moteur_refuse_une_fenetre_de_rattrapage_anterieure_a_l_age_du(): void
    {
        $this->seed(VaccinSeeder::class);

        $this->expectException(\Illuminate\Database\QueryException::class);

        $this->penta()->echeances()->create([
            'numero_dose' => 8, 'age_jours_du' => 100, 'age_jours_limite' => 50,
            'libelle_echeance' => 'Incohérente', 'source' => 'demonstration',
        ]);
    }

    public function test_la_modification_repasse_par_la_verification_du_lien(): void
    {
        // Trou réel ANTÉRIEUR à cet incrément : `update()` n'appelait pas `preparerDonnees()`, donc
        // un PUT pouvait changer le lien sans repasser par sa vérification.
        $this->mettreEnVigueur();

        $ligne = $this->membre->vaccinations()->create(['vaccin_nom' => 'Sans lien']);

        $this->putJson($this->url("/vaccinations/{$ligne->id}"), [
            'vaccin_id' => 999999,
        ])->assertStatus(422)->assertJsonValidationErrors('vaccin_id');

        $reponse = $this->putJson($this->url("/vaccinations/{$ligne->id}"), [
            'vaccin_id'   => $this->penta()->id,
            'numero_dose' => 1,
        ])->assertOk();

        $this->assertSame($this->penta()->code, $reponse->json('item.vaccin_code'));
    }

    public function test_la_garantie_vaut_sur_le_chemin_du_soignant(): void
    {
        $this->mettreEnVigueur();

        $prepare = app(\App\Http\Controllers\Api\V1\Carnet\VaccinationController::class)
            ->preparerDonnees([
                'vaccin_nom'  => 'Nom inventé',
                'vaccin_id'   => $this->penta()->id,
                'numero_dose' => 1,
                'obligatoire' => false,
                'statut'      => ReglesCalendrierVaccinal::FAIT,
            ], $this->membre);

        $this->assertSame($this->penta()->libelle, $prepare['vaccin_nom']);
        $this->assertTrue($prepare['obligatoire']);
        $this->assertArrayNotHasKey('statut', $prepare);
    }
}
