<?php

namespace Tests\Feature;

use App\Models\MembreFamille;
use App\Models\MesureSante;
use App\Models\ReferentielMesure;
use App\Models\User;
use App\Services\MesureSanteService;
use App\Services\Referentiel\ServiceGouvernanceReferentiel;
use App\Services\Referentiel\SourceSeuilsMesure;
use Database\Seeders\ReferentielMesureSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\PublieLesSeuilsDeMesure;
use Tests\TestCase;

/**
 * L1 + L2 (ADR-025 §5) — le journal de bord lit le référentiel GOUVERNÉ, et chaque mesure conserve
 * la version qui l'a jugée.
 *
 * Ce que ces vecteurs doivent tenir, et pourquoi chacun est nécessaire :
 *
 *  - un `UPDATE` direct sur la table ne change plus rien (L1) — MAIS son jumeau est obligatoire :
 *    publier une version corrigée change bien la qualification. Sans le second, le premier
 *    prouverait seulement que plus rien ne fonctionne ;
 *  - sans version publiée, le service ÉCHOUE BRUYAMMENT et ne se replie jamais sur la table ;
 *  - une mesure porte la version en vigueur, deux mesures encadrant une publication en portent deux
 *    différentes, et les mesures antérieures à la bascule restent sans version plutôt que d'en
 *    recevoir une fausse (L2) ;
 *  - le quatre-yeux s'applique au chemin de lecture comme au reste ;
 *  - la validation de saisie parle de la version en vigueur, pas de la table.
 */
class SeuilsMesureGouvernesTest extends TestCase
{
    use PublieLesSeuilsDeMesure;
    use RefreshDatabase;

    private User $user;

    private MembreFamille $membre;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ReferentielMesureSeeder::class);

        $this->user = User::factory()->create();
        $this->membre = MembreFamille::factory()->for($this->user)->create();

        Sanctum::actingAs($this->user);
    }

    private function url(string $suffixe = ''): string
    {
        return "/api/v1/membres/{$this->membre->id}/mesures".$suffixe;
    }

    /** Une glycémie normale au référentiel seedé (0,70–1,10 g/L). */
    private function saisirGlycemie(float $valeur)
    {
        return $this->postJson($this->url(), [
            'type_mesure' => 'glycemie',
            'valeur'      => $valeur,
            'date_mesure' => now()->subHour()->toDateTimeString(),
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // L1 — la lecture bascule
    // ─────────────────────────────────────────────────────────────────────────────

    public function test_sans_version_publiee_le_journal_echoue_bruyamment(): void
    {
        // La table est seedée : sous l'ancien comportement, l'écran aurait fonctionné.
        $this->assertGreaterThan(0, ReferentielMesure::count());

        $this->getJson($this->url())->assertStatus(503);

        $this->saisirGlycemie(0.9)->assertStatus(503);

        $this->assertSame(0, MesureSante::count(), 'Une mesure a été écrite sans référentiel en vigueur.');
    }

    public function test_sans_version_publiee_il_n_y_a_aucun_repli_sur_la_table(): void
    {
        $reponse = $this->getJson($this->url())->assertStatus(503);

        // Le refus doit DIRE ce qui manque : un 503 muet enverrait chercher une panne réseau.
        $this->assertStringContainsString('version en vigueur', $reponse->json('message'));

        // Et surtout : aucun seuil n'est servi malgré une table pleine.
        $this->assertNull($reponse->json('referentiels'));
    }

    public function test_un_update_direct_de_la_table_ne_change_plus_la_qualification(): void
    {
        $this->publierLesSeuils();

        // 0,90 g/L est normale au référentiel publié.
        $this->saisirGlycemie(0.9)->assertCreated()->assertJsonPath('mesures.0.statut_norme', 'normal');

        // Un « correcteur » abaisse brutalement la normale par un UPDATE direct — le geste qui,
        // avant L1, rendait inexplicable tout jugement antérieur.
        ReferentielMesure::where('type_mesure', 'glycemie')->update([
            'normal_max'    => 0.5,
            'critique_haut' => 0.6,
        ]);

        // FRONTIÈRE DE REQUÊTE OBLIGATOIRE ICI. Sans elle, la seconde saisie réutiliserait les
        // seuils déjà chargés et ce vecteur passerait même si le service relisait la table —
        // il prouverait la mémoïsation au lieu de prouver la bascule.
        $this->simulerNouvelleRequete();

        // Le référentiel EN VIGUEUR n'a pas bougé : la même valeur reste normale.
        $this->saisirGlycemie(0.9)->assertCreated()->assertJsonPath('mesures.0.statut_norme', 'normal');
    }

    public function test_publier_une_version_corrigee_change_bien_la_qualification(): void
    {
        // Le jumeau obligatoire du vecteur précédent : sans lui, on ne prouverait que la panne.
        $this->publierLesSeuils();

        $this->saisirGlycemie(1.4)->assertCreated()->assertJsonPath('mesures.0.statut_norme', 'eleve');

        $this->corrigerUnSeuilEtPublier(
            'glycemie',
            ['critique_haut' => 1.3],
            'Abaissement du seuil critique haut sur avis du comité clinique.',
        );

        $this->saisirGlycemie(1.4)->assertCreated()->assertJsonPath('mesures.0.statut_norme', 'critique');
    }

    public function test_le_referentiel_servi_au_mobile_est_celui_de_la_version_publiee(): void
    {
        $this->publierLesSeuils();

        ReferentielMesure::where('type_mesure', 'glycemie')->update(['libelle' => 'Glycémie MODIFIÉE EN DIRECT']);

        $servi = collect($this->getJson($this->url())->assertOk()->json('referentiels'))
            ->firstWhere('type_mesure', 'glycemie');

        $this->assertNotSame('Glycémie MODIFIÉE EN DIRECT', $servi['libelle']);
    }

    public function test_un_type_absent_de_la_version_publiee_est_refuse_a_la_saisie(): void
    {
        // Publié SANS la glycémie, puis la ligne est remise en table sans être publiée.
        ReferentielMesure::where('type_mesure', 'glycemie')->delete();
        $this->publierLesSeuils();

        $this->seed(ReferentielMesureSeeder::class);
        $this->assertNotNull(ReferentielMesure::where('type_mesure', 'glycemie')->first());

        // La table la connaît, la version en vigueur non : la saisie est refusée. C'est le défaut
        // que les deux `pluck` du contrôleur laissaient passer (constat C1 du plan G1).
        $this->saisirGlycemie(0.9)->assertStatus(422)->assertJsonValidationErrors('type_mesure');

        $this->getJson($this->url().'?type=glycemie')->assertStatus(422);
    }

    public function test_les_seuils_hydrates_ne_sont_pas_persistes(): void
    {
        $this->publierLesSeuils();

        $avant = ReferentielMesure::count();

        $seuils = app(MesureSanteService::class)->referentiels();

        $this->assertSame($avant, ReferentielMesure::count(), 'La lecture du référentiel a écrit en base.');
        $this->assertFalse($seuils->first()->exists, 'Les seuils servis prétendent exister en base.');
        $this->assertNull($seuils->first()->id);
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // L2 — l'estampille
    // ─────────────────────────────────────────────────────────────────────────────

    public function test_une_mesure_porte_la_version_en_vigueur(): void
    {
        $version = $this->publierLesSeuils();

        $this->saisirGlycemie(0.9)->assertCreated();

        $this->assertSame($version, MesureSante::first()->referentiel_version);
    }

    public function test_deux_mesures_encadrant_une_publication_portent_deux_versions(): void
    {
        // LE vecteur qui referme le défaut du G0 : après une correction de seuil, une valeur jugée
        // hier reste explicable, parce qu'elle dit avec quels seuils elle a été jugée.
        $v1 = $this->publierLesSeuils();
        $this->saisirGlycemie(1.4)->assertCreated()->assertJsonPath('mesures.0.statut_norme', 'eleve');

        $v2 = $this->corrigerUnSeuilEtPublier(
            'glycemie',
            ['critique_haut' => 1.3],
            'Abaissement du seuil critique haut.',
        );
        $this->saisirGlycemie(1.4)->assertCreated()->assertJsonPath('mesures.0.statut_norme', 'critique');

        $this->assertNotSame($v1, $v2);

        $mesures = MesureSante::orderBy('id')->get();
        $this->assertSame($v1, $mesures[0]->referentiel_version);
        $this->assertSame($v2, $mesures[1]->referentiel_version);

        // Deux jugements opposés sur la même valeur, chacun rattaché à sa version : la contradiction
        // est explicable, ce qui était impossible avant L2.
        $this->assertSame('eleve', $mesures[0]->statut_norme);
        $this->assertSame('critique', $mesures[1]->statut_norme);
    }

    public function test_une_mesure_anterieure_a_la_bascule_reste_sans_version(): void
    {
        $this->publierLesSeuils();

        // Une ligne écrite avant L1 : elle n'a eu aucune version, et on refuse de lui en inventer une.
        $ancienne = new MesureSante([
            'type_mesure' => 'glycemie',
            'valeur'      => 0.9,
            'date_mesure' => now()->subYear(),
        ]);
        $ancienne->unite = 'g/L';
        $ancienne->statut_norme = 'normal';
        $this->membre->mesuresSante()->save($ancienne);

        $this->saisirGlycemie(0.9)->assertCreated();

        $this->assertNull($ancienne->fresh()->referentiel_version);
        $this->assertNotNull(MesureSante::orderByDesc('id')->first()->referentiel_version);
    }

    public function test_les_deux_lignes_d_une_tension_portent_la_meme_version(): void
    {
        $version = $this->publierLesSeuils();

        $this->postJson($this->url(), [
            'type_mesure' => 'tension',
            'systolique'  => 150,
            'diastolique' => 95,
            'date_mesure' => now()->subHour()->toDateTimeString(),
        ])->assertCreated();

        $versions = MesureSante::pluck('referentiel_version')->unique();

        $this->assertCount(1, $versions);
        $this->assertSame($version, $versions->first());
    }

    public function test_le_client_ne_peut_pas_declarer_la_version_qui_l_a_juge(): void
    {
        $version = $this->publierLesSeuils();

        $this->postJson($this->url(), [
            'type_mesure'         => 'glycemie',
            'valeur'              => 0.9,
            'date_mesure'         => now()->subHour()->toDateTimeString(),
            'referentiel_version' => 999,
        ])->assertCreated();

        $this->assertSame($version, MesureSante::first()->referentiel_version);
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // La gouvernance s'applique aussi à ce chemin
    // ─────────────────────────────────────────────────────────────────────────────

    public function test_l_auteur_d_une_proposition_ne_peut_pas_la_publier_lui_meme(): void
    {
        $this->publierLesSeuils();

        $gouvernance = app(ServiceGouvernanceReferentiel::class);
        $seul = $this->agentReferentiel(
            ServiceGouvernanceReferentiel::PERMISSION_PROPOSER,
            ServiceGouvernanceReferentiel::PERMISSION_PUBLIER,
        );

        ReferentielMesure::where('type_mesure', 'glycemie')->update(['normal_max' => 1.2]);
        $gouvernance->proposer(SourceSeuilsMesure::CODE, 'CI', $seul, 'Je propose et je publierai.');

        try {
            $gouvernance->publier(SourceSeuilsMesure::CODE, 'CI', $seul, 'Je me valide moi-même.');
            $this->fail('Un auteur a pu mettre en vigueur sa propre proposition de seuils cliniques.');
        } catch (\App\Services\Referentiel\ReferentielException $e) {
            $this->assertSame(409, $e->statut);
        }

        // Et la version en vigueur n'a pas bougé : 1,15 g/L reste au-dessus de la normale publiée.
        $this->saisirGlycemie(1.15)->assertCreated()->assertJsonPath('mesures.0.statut_norme', 'eleve');
    }
}
