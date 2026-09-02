<?php

namespace App\Services;

use App\Exceptions\PrincipalSigneInvalideException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Vérifie un principal signé ENTRANT (canal interne, lot 6) — miroir côté Laravel de ce que
 * `ServicePrincipal` vérifie côté Java pour les appels d'`apps/web` : mêmes contrôles (signature à
 * temps constant, fraîcheur, liaison method+path, anti-rejeu par nonce), jamais un sous-ensemble
 * (recommandation validée en Phase 0 — le prompt n'exigeait explicitement que la signature).
 *
 * Une seule cause d'échec est exposée à l'appelant : « invalide », 401 sans détail. Le motif précis
 * (signature fausse, expiré, nonce rejoué...) est journalisé par l'appelant, jamais renvoyé — un
 * attaquant ne doit rien apprendre de la raison exacte du refus.
 */
class VerificateurPrincipalSigne
{
    /** Fenêtre de fraîcheur tolérée, en secondes (même ordre de grandeur que `ServicePrincipal`, ±5 min). */
    private const FENETRE_FRAICHEUR_SECONDES = 300;

    /** @throws PrincipalSigneInvalideException */
    public function verifier(Request $request, string $secretBase64): void
    {
        $principalB64 = (string) $request->header('X-Principal', '');
        $signatureRecue = (string) $request->header('X-Principal-Sig', '');

        if ($principalB64 === '' || $signatureRecue === '') {
            throw new PrincipalSigneInvalideException('En-têtes X-Principal/X-Principal-Sig absents.');
        }

        $secret = $secretBase64 !== '' ? base64_decode($secretBase64, true) : false;
        if ($secret === false || $secret === '') {
            throw new PrincipalSigneInvalideException('Secret de vérification non configuré.');
        }

        $signatureAttendue = base64_encode(hash_hmac('sha256', $principalB64, $secret, true));

        // hash_equals : la comparaison ne fuit pas la position du premier octet divergent.
        if (! hash_equals($signatureAttendue, $signatureRecue)) {
            throw new PrincipalSigneInvalideException('Signature invalide.');
        }

        $claims = json_decode((string) base64_decode($principalB64, true), true);
        if (! is_array($claims)) {
            throw new PrincipalSigneInvalideException('Principal illisible.');
        }

        $maintenant = now()->timestamp;
        $iat = (int) ($claims['iat'] ?? 0);
        $exp = (int) ($claims['exp'] ?? 0);

        if ($exp <= $maintenant || $iat > $maintenant + self::FENETRE_FRAICHEUR_SECONDES) {
            throw new PrincipalSigneInvalideException('Principal expiré ou horodatage incohérent.');
        }

        // Liaison method+path : un principal signé pour un autre appel ne peut pas être rejoué ici.
        $cheminAttendu = '/'.ltrim($request->path(), '/');
        if (($claims['method'] ?? null) !== $request->method() || ($claims['path'] ?? null) !== $cheminAttendu) {
            throw new PrincipalSigneInvalideException('Method/path non liés au principal signé.');
        }

        $nonce = (string) ($claims['nonce'] ?? '');
        if ($nonce === '') {
            throw new PrincipalSigneInvalideException('Nonce absent.');
        }

        // `Cache::add()` est atomique (échoue si la clé existe déjà) : un vrai anti-rejeu, pas une
        // lecture puis une écriture séparées qui laisserait une fenêtre de course. TTL aligné sur la
        // fenêtre de fraîcheur : inutile de retenir un nonce plus longtemps que le principal n'est
        // valide.
        $cle = 'principal-signe:nonce:'.hash('sha256', $nonce);
        if (! Cache::add($cle, true, self::FENETRE_FRAICHEUR_SECONDES)) {
            throw new PrincipalSigneInvalideException('Nonce déjà utilisé (rejeu).');
        }
    }
}
