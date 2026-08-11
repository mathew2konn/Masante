<?php

namespace Tests\Feature;

use App\Models\MembreFamille;
use App\Models\User;
use App\Services\Nis\AttributeurNis;
use App\Services\Nis\CalculateurNis;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * P6.1 — Attribution du NIS : unicité, idempotence, journal, exposition (CDC_09 §3).
 *
 * Ces tests portent sur les garanties que le calcul pur ne couvre pas : la persistance,
 * la transaction, le journal de non-réutilisation et les gardes HTTP.
 */
class NisAttributionTest extends TestCase
{
    use RefreshDatabase;

    private function membre(?User $user = null): MembreFamille
    {
        $user ??= User::factory()->create();

        return MembreFamille::factory()->create(['user_id' => $user->id]);
    }

    #[Test]
    public function un_dossier_recoit_un_nis_valide_et_une_ligne_de_journal(): void
    {
        $membre = $this->membre();
        $nis    = app(AttributeurNis::class)->attribuer($membre);

        $this->assertTrue(app(CalculateurNis::class)->estValide($nis));

        $this->assertDatabaseHas('membres_famille', [
            'id'  => $membre->id,
            'nis' => $nis,
        ]);

        // Le journal est la preuve de non-réutilisation : une ligne par NIS attribué.
        $this->assertDatabaseHas('nis_journal', [
            'nis'       => $nis,
            'membre_id' => $membre->id,
            'motif'     => AttributeurNis::MOTIF_CREATION,
        ]);
    }

    #[Test]
    public function une_seconde_attribution_ne_change_rien_et_ne_consomme_pas_la_sequence(): void
    {
        $membre = $this->membre();
        $att    = app(AttributeurNis::class);

        $premier = $att->attribuer($membre);
        $second  = $att->attribuer($membre->fresh());

        $this->assertSame($premier, $second, 'Le NIS doit accompagner le patient à vie.');
        $this->assertSame(1, DB::table('nis_journal')->where('nis', $premier)->count());
        $this->assertSame(1, (int) DB::table('nis_compteurs')->value('dernier'));
    }

    #[Test]
    public function les_nis_successifs_sont_tous_distincts(): void
    {
        $att = app(AttributeurNis::class);
        $nis = [];

        for ($i = 0; $i < 25; $i++) {
            $nis[] = $att->attribuer($this->membre());
        }

        $this->assertCount(25, array_unique($nis), 'Collision détectée dans la séquence.');
        $this->assertSame(25, DB::table('nis_journal')->count());
    }

    #[Test]
    public function le_nis_reste_reserve_apres_suppression_du_dossier(): void
    {
        // Cœur de l'exigence « non réutilisable » (CDC_09 §3.2) : la ligne de journal survit
        // au dossier (membre_id en nullOnDelete, jamais cascade).
        $membre = $this->membre();
        $nis    = app(AttributeurNis::class)->attribuer($membre);

        $membre->delete();

        $this->assertDatabaseMissing('membres_famille', ['nis' => $nis]);
        $this->assertDatabaseHas('nis_journal', ['nis' => $nis, 'membre_id' => null]);
    }

    #[Test]
    public function un_compte_ne_peut_pas_avoir_deux_dossiers_titulaires(): void
    {
        $user = User::factory()->create();

        MembreFamille::factory()->create(['user_id' => $user->id, 'est_titulaire' => true]);

        $this->expectException(\Illuminate\Database\QueryException::class);

        MembreFamille::factory()->create(['user_id' => $user->id, 'est_titulaire' => true]);
    }

    #[Test]
    public function deux_comptes_peuvent_chacun_avoir_leur_dossier_titulaire(): void
    {
        // Le garde-fou porte sur (compte, titulaire), pas sur « titulaire » globalement.
        MembreFamille::factory()->create(['user_id' => User::factory()->create()->id, 'est_titulaire' => true]);
        MembreFamille::factory()->create(['user_id' => User::factory()->create()->id, 'est_titulaire' => true]);

        $this->assertSame(2, MembreFamille::where('est_titulaire', true)->count());
    }

    #[Test]
    public function la_verification_publique_valide_le_format_sans_reveler_l_existence(): void
    {
        $membre = $this->membre();
        $nis    = app(AttributeurNis::class)->attribuer($membre);

        // Un NIS réel : accepté.
        $this->getJson("/api/v1/nis/{$nis}/verifier")
            ->assertOk()
            ->assertJsonPath('data.valide', true);

        // Un NIS jamais attribué mais bien formé : accepté AUSSI. C'est volontaire —
        // l'endpoint ne doit pas permettre de distinguer « existe » de « n'existe pas »
        // (anti-énumération, CDC_10 §5).
        $inexistant = app(CalculateurNis::class)->composer('CIS', 99, 87_654_321);

        $this->getJson("/api/v1/nis/{$inexistant}/verifier")
            ->assertOk()
            ->assertJsonPath('data.valide', true);
    }

    #[Test]
    public function la_verification_publique_rejette_une_cle_incorrecte(): void
    {
        $this->getJson('/api/v1/nis/CIS241200012547/verifier')
            ->assertOk()
            ->assertJsonPath('data.valide', false)
            ->assertJsonPath('data.motif', CalculateurNis::MOTIF_CLE);
    }

    #[Test]
    public function un_titulaire_lit_le_nis_de_son_dossier_mais_pas_celui_d_un_autre(): void
    {
        $proprietaire = User::factory()->create();
        $intrus       = User::factory()->create();
        $membre       = $this->membre($proprietaire);

        app(AttributeurNis::class)->attribuer($membre);

        $this->actingAs($proprietaire)
            ->getJson("/api/v1/membres/{$membre->id}/nis")
            ->assertOk()
            ->assertJsonPath('data.nis', $membre->fresh()->nis);

        // Anti-IDOR : la Policy existante (P2, validée G5) fait le travail, non réécrite.
        $this->actingAs($intrus)
            ->getJson("/api/v1/membres/{$membre->id}/nis")
            ->assertForbidden();
    }

    #[Test]
    public function le_backfill_est_idempotent(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->membre();
        }

        $this->artisan('masante:nis:backfill')->assertSuccessful();
        $this->assertSame(0, MembreFamille::whereNull('nis')->count());

        $avant = MembreFamille::orderBy('id')->pluck('nis')->all();

        // Rejeu : aucun NIS ne change, aucune ligne de journal supplémentaire.
        $this->artisan('masante:nis:backfill')->assertSuccessful();

        $this->assertSame($avant, MembreFamille::orderBy('id')->pluck('nis')->all());
        $this->assertSame(5, DB::table('nis_journal')->count());
    }

    #[Test]
    public function le_matricule_interne_reste_cache_alors_que_le_nis_est_expose(): void
    {
        $user   = User::factory()->create();
        $membre = $this->membre($user);
        app(AttributeurNis::class)->attribuer($membre);

        // Le contrat existant de P2 renvoie { membre: … } et non { data: … } : on s'y conforme,
        // on ne le modifie pas (module validé G5).
        $charge = $this->actingAs($user)
            ->getJson("/api/v1/membres/{$membre->id}")
            ->assertOk()
            ->json('membre');

        $this->assertArrayNotHasKey('matricule_ivs', $charge, 'Le matricule interne ne doit jamais fuiter.');
        $this->assertArrayNotHasKey('titulaire_du_compte', $charge, 'Colonne technique exposée.');
        $this->assertSame($membre->fresh()->nis, $charge['nis'] ?? null, 'Le NIS doit être exposé (CDC_09 §3.5).');
    }
}
