<?php

namespace Tests\Feature;

use App\Exceptions\PrincipalSigneInvalideException;
use App\Services\SigneurPrincipalSortant;
use App\Services\VerificateurPrincipalSigne;
use Illuminate\Http\Request;
use RuntimeException;
use Tests\TestCase;

/**
 * B4 (ADR-056, S4) — {@code SigneurPrincipalSortant} est la QUATRIÈME implémentation du même
 * format de principal signé (Java {@code ServicePrincipal} vérifie, Node {@code paiement.ts} mint,
 * Python {@code signer.py} mint). Quatre implémentations, c'est quatre occasions de diverger — cette
 * garde vérifie qu'un principal minté ICI est accepté par le vérificateur PHP, et que sa FORME
 * (les sept claims documentées, ni plus ni moins) est celle des trois autres.
 *
 * Ce que ce test NE prouve PAS : que Java accepterait réellement ce principal (aucun harnais
 * inter-langages ici). La mécanique HMAC cross-langage est déjà prouvée par le vecteur partagé de
 * {@see CanalInternePaiementTest::test_vecteur_partage_avec_le_signeur_java} — dans l'autre sens du
 * canal, mais avec le même algorithme (HMAC-SHA256 sur les octets bruts de X-Principal, même
 * secret). Ce qui reste propre à ce sens est la FORME produite par le signeur PHP lui-même.
 */
class PrincipalSigneSourceUniqueTest extends TestCase
{
    private const CHEMIN = '/api/v1/interne/geniuspay/paiements';

    private function signeur(): SigneurPrincipalSortant
    {
        return app(SigneurPrincipalSortant::class);
    }

    // ── 1. Round-trip : ce que PHP signe, PHP le vérifie ────────────────────────────────────

    public function test_principal_minte_est_accepte_par_le_verificateur(): void
    {
        $entetes = $this->signeur()->signer('POST', self::CHEMIN, 'laravel-masante', ['SYSTEME']);

        $request = Request::create(self::CHEMIN, 'POST');
        $request->headers->set('X-Principal', $entetes['X-Principal']);
        $request->headers->set('X-Principal-Sig', $entetes['X-Principal-Sig']);

        // N'importe quelle exception ferait échouer ce test — c'est voulu, la seule assertion utile
        // est « aucune levée ».
        app(VerificateurPrincipalSigne::class)
            ->verifier($request, (string) config('masante.paiement_service.principal_secret'));

        $this->assertTrue(true);
    }

    public function test_methode_ou_chemin_different_de_celui_signe_est_refuse(): void
    {
        $entetes = $this->signeur()->signer('POST', self::CHEMIN, 'laravel-masante', ['SYSTEME']);

        // Un GET sur le même chemin ne doit PAS être accepté par un principal signé pour un POST —
        // la liaison method+path est ce qui empêche un principal volé de servir à un autre appel.
        $request = Request::create(self::CHEMIN, 'GET');
        $request->headers->set('X-Principal', $entetes['X-Principal']);
        $request->headers->set('X-Principal-Sig', $entetes['X-Principal-Sig']);

        $this->expectException(PrincipalSigneInvalideException::class);

        app(VerificateurPrincipalSigne::class)
            ->verifier($request, (string) config('masante.paiement_service.principal_secret'));
    }

    // ── 2. Forme : exactement les sept claims documentées, ni plus ni moins ─────────────────

    public function test_le_principal_porte_exactement_les_sept_claims_documentees(): void
    {
        $entetes = $this->signeur()->signer('POST', self::CHEMIN, 'laravel-masante', ['SYSTEME']);
        $claims = json_decode((string) base64_decode($entetes['X-Principal'], true), true);

        $this->assertIsArray($claims);
        $this->assertSame(
            ['sub', 'roles', 'iat', 'exp', 'method', 'path', 'nonce'],
            array_keys($claims),
            'La forme documentée (Java ServicePrincipal, Node paiement.ts, Python signer.py) porte '
            .'exactement ces sept claims. Une clé de plus ou de moins est une divergence entre '
            .'implémentations, même si le vérificateur PHP la tolère.'
        );
    }

    public function test_roles_est_un_tableau_et_sub_path_method_sont_recopies_tels_quels(): void
    {
        $entetes = $this->signeur()->signer('POST', self::CHEMIN, 'laravel-masante', ['SYSTEME', 'ADMIN_FINANCE']);
        $claims = json_decode((string) base64_decode($entetes['X-Principal'], true), true);

        $this->assertSame('laravel-masante', $claims['sub']);
        $this->assertSame(['SYSTEME', 'ADMIN_FINANCE'], $claims['roles']);
        $this->assertSame('POST', $claims['method']);
        $this->assertSame(self::CHEMIN, $claims['path']);
    }

    public function test_fenetre_de_validite_est_de_cent_vingt_secondes_comme_les_trois_autres(): void
    {
        $entetes = $this->signeur()->signer('GET', self::CHEMIN, 'laravel-masante', ['SYSTEME']);
        $claims = json_decode((string) base64_decode($entetes['X-Principal'], true), true);

        $this->assertSame(120, $claims['exp'] - $claims['iat']);
    }

    public function test_deux_appels_produisent_des_nonces_distincts(): void
    {
        $premier = $this->signeur()->signer('GET', self::CHEMIN, 'laravel-masante', ['SYSTEME']);
        $second = $this->signeur()->signer('GET', self::CHEMIN, 'laravel-masante', ['SYSTEME']);

        $claimsA = json_decode((string) base64_decode($premier['X-Principal'], true), true);
        $claimsB = json_decode((string) base64_decode($second['X-Principal'], true), true);

        $this->assertNotSame(
            $claimsA['nonce'],
            $claimsB['nonce'],
            'Un nonce répété casserait l\'anti-rejeu du second appel légitime.'
        );
    }

    // ── 3. Secret manquant → échec bruyant, jamais une signature sur chaîne vide ────────────

    public function test_secret_absent_leve_bruyamment(): void
    {
        config(['masante.paiement_service.principal_secret' => '']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('MASANTE_PAYMENT_PRINCIPAL_SECRET');

        $this->signeur()->signer('POST', self::CHEMIN, 'laravel-masante', ['SYSTEME']);
    }
}
