<?php

namespace App\Services;

use Illuminate\Support\Str;
use RuntimeException;

/**
 * Mint un principal signé SORTANT (canal interne, B4/ADR-056) — Laravel devient ÉMETTEUR pour la
 * première fois (jusqu'ici seul {@see VerificateurPrincipalSigne} existait, côté vérification).
 *
 * QUATRIÈME implémentation du même format (Java {@code ServicePrincipal} vérifie, Node
 * {@code apps/web/src/lib/paiement.ts} mint, Python {@code signer.py} de dev mint) — mêmes claims,
 * même encodage, jamais un sous-ensemble. Une divergence ici serait une occasion de plus qu'un
 * appel signé se voie refusé sans que personne ne comprenne pourquoi (garde d'exécution :
 * {@code PrincipalSigneSourceUniqueTest}).
 *
 * Format :
 *   X-Principal     = base64(JSON {sub,roles,iat,exp,method,path,nonce})
 *   X-Principal-Sig = base64(HMAC-SHA256(octets UTF-8 de X-Principal, secret décodé du base64))
 *
 * `path` = le pathname EXACT, SANS query string — c'est lui que le vérificateur (Java ou PHP)
 * compare au chemin réel de la requête. Ajouter une query au chemin SIGNÉ romprait la vérification ;
 * elle s'ajoute à l'URL de l'appel, jamais au principal.
 */
class SigneurPrincipalSortant
{
    /** Fenêtre de validité du principal minté — même ordre de grandeur que `paiement.ts` (120 s). */
    private const DUREE_VALIDITE_SECONDES = 120;

    /**
     * @param  array<int, string>  $roles
     * @return array{'X-Principal': string, 'X-Principal-Sig': string}
     *
     * @throws RuntimeException si le secret n'est pas configuré — échec bruyant, jamais une
     *                          signature calculée sur une chaîne vide qui semblerait valide.
     */
    public function signer(string $methode, string $chemin, string $sub, array $roles): array
    {
        $secretBase64 = (string) config('masante.paiement_service.principal_secret');
        $secret = $secretBase64 !== '' ? base64_decode($secretBase64, true) : false;
        if ($secret === false || $secret === '') {
            throw new RuntimeException(
                'MASANTE_PAYMENT_PRINCIPAL_SECRET non configuré : impossible de signer un principal sortant.'
            );
        }

        $maintenant = now()->timestamp;
        $claims = [
            'sub' => $sub,
            'roles' => $roles,
            'iat' => $maintenant,
            'exp' => $maintenant + self::DUREE_VALIDITE_SECONDES,
            'method' => $methode,
            'path' => $chemin,
            'nonce' => (string) Str::uuid(),
        ];

        $principalB64 = base64_encode(json_encode($claims, JSON_UNESCAPED_SLASHES));
        $signature = base64_encode(hash_hmac('sha256', $principalB64, $secret, true));

        return [
            'X-Principal' => $principalB64,
            'X-Principal-Sig' => $signature,
        ];
    }
}
