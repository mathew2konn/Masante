<?php

namespace Tests\Feature;

use App\Services\ClientPaiementGeniusPay;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

/**
 * B4 (ADR-056, S10) — client Laravel → paiement-service (Java), canal GeniusPay. Laravel devient
 * émetteur pour la première fois : chaque appel doit être signé, et injoignable ne doit JAMAIS
 * inventer un résultat.
 */
class ClientPaiementGeniusPayTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    private function client(): ClientPaiementGeniusPay
    {
        return app(ClientPaiementGeniusPay::class);
    }

    // ── 1. initierCheckout : signé, chemin exact, Idempotency-Key transmise ────────────────

    public function test_initier_checkout_envoie_un_appel_signe_au_bon_chemin(): void
    {
        Http::fake(['*/api/v1/interne/geniuspay/paiements' => Http::response([
            'referenceInterne' => 'MS-ETS000001-01K',
            'checkoutUrl' => 'https://sandbox.geniuspay.example/checkout/abc',
        ], 200)]);

        $resultat = $this->client()->initierCheckout([
            'factureId' => '11111111-1111-1111-1111-111111111111',
            'montant' => 15000,
            'etablissementRef' => 'CI-ETS000001',
        ], 'idem-key-1');

        $this->assertSame('MS-ETS000001-01K', $resultat['referenceInterne']);

        Http::assertSent(function ($requete) {
            return $requete->url() === 'http://localhost:8080/api/v1/interne/geniuspay/paiements'
                && $requete->method() === 'POST'
                && $requete->hasHeader('X-Principal')
                && $requete->hasHeader('X-Principal-Sig')
                && $requete->header('Idempotency-Key')[0] === 'idem-key-1'
                && $requete['montant'] === 15000;
        });
    }

    // ── 2. Injoignable → RuntimeException, jamais un checkout inventé ──────────────────────

    public function test_injoignable_leve_sans_inventer_de_checkout(): void
    {
        Http::fake(['*/api/v1/interne/geniuspay/paiements' => Http::failedConnection('Timed out.')]);

        $this->expectException(RuntimeException::class);

        $this->client()->initierCheckout([
            'factureId' => '11111111-1111-1111-1111-111111111111',
            'montant' => 15000,
            'etablissementRef' => 'CI-ETS000001',
        ], 'idem-key-2');
    }

    // ── 3. Réponse d'erreur → RuntimeException nommant le statut ───────────────────────────

    public function test_reponse_en_erreur_leve_en_nommant_le_statut(): void
    {
        Http::fake(['*/api/v1/interne/geniuspay/paiements' => Http::response(['motif' => 'refuse'], 422)]);

        try {
            $this->client()->initierCheckout([
                'factureId' => '11111111-1111-1111-1111-111111111111',
                'montant' => 15000,
                'etablissementRef' => 'CI-ETS000001',
            ], 'idem-key-3');
            $this->fail('Une exception était attendue.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('422', $e->getMessage());
        }
    }

    // ── 4. consulter : GET au bon chemin ────────────────────────────────────────────────────

    public function test_consulter_interroge_le_bon_chemin_en_get(): void
    {
        Http::fake(['*/api/v1/interne/geniuspay/paiements/MS-ETS000001-01K' => Http::response([
            'statut' => 'EN_ATTENTE',
        ], 200)]);

        $resultat = $this->client()->consulter('MS-ETS000001-01K');

        $this->assertSame('EN_ATTENTE', $resultat['statut']);
        Http::assertSent(fn ($r) => $r->method() === 'GET'
            && $r->url() === 'http://localhost:8080/api/v1/interne/geniuspay/paiements/MS-ETS000001-01K');
    }

    // ── 5. estConfigure : mis en cache, jamais recopié comme une seconde vérité ─────────────

    public function test_est_configure_vrai_est_mis_en_cache(): void
    {
        Http::fake(['*/api/v1/interne/geniuspay/marchands/CI-ETS000001' => Http::response([
            'etablissementRef' => 'CI-ETS000001', 'configure' => true,
        ], 200)]);

        $this->assertTrue($this->client()->estConfigure('CI-ETS000001'));
        $this->assertTrue($this->client()->estConfigure('CI-ETS000001'));

        // Le second appel doit être servi par le cache.
        Http::assertSentCount(1);
    }

    public function test_est_configure_faux_est_aussi_mis_en_cache(): void
    {
        Http::fake(['*/api/v1/interne/geniuspay/marchands/CI-ETS000002' => Http::response([
            'etablissementRef' => 'CI-ETS000002', 'configure' => false,
        ], 200)]);

        $this->assertFalse($this->client()->estConfigure('CI-ETS000002'));
        $this->assertFalse($this->client()->estConfigure('CI-ETS000002'));

        Http::assertSentCount(1);
    }

    public function test_est_configure_injoignable_repond_faux_sans_mettre_en_cache(): void
    {
        Http::fake(['*/api/v1/interne/geniuspay/marchands/CI-ETS000003' => Http::failedConnection('Timed out.')]);

        $this->assertFalse($this->client()->estConfigure('CI-ETS000003'));
        $this->assertFalse($this->client()->estConfigure('CI-ETS000003'));

        // Panne transitoire jamais mise en cache : chaque appel réessaie.
        Http::assertSentCount(2);
    }

    public function test_est_configure_signe_lui_aussi_lappel(): void
    {
        Http::fake(['*/api/v1/interne/geniuspay/marchands/CI-ETS000004' => Http::response([
            'configure' => true,
        ], 200)]);

        $this->client()->estConfigure('CI-ETS000004');

        Http::assertSent(fn ($r) => $r->hasHeader('X-Principal') && $r->hasHeader('X-Principal-Sig'));
    }
}
