<?php

namespace App\Services\Pki;

use RuntimeException;
use SensitiveParameter;

/**
 * Le coffre où dort la clé privée d'un professionnel (CDC_09 §5.3 ; CDC_10 §4.3/§4.4).
 *
 * ═══ LA PROMESSE, ET CE QUI LA TIENT ═══
 *
 * « Le serveur seul ne peut pas signer. » Ce n'est pas une intention, c'est une propriété du code :
 * la clé qui déchiffre est DÉRIVÉE du secret du praticien, et ce secret n'est stocké nulle part.
 * Ni l'accès à cette base, ni l'accès au `.env`, ni les deux réunis ne permettent de produire une
 * signature — il faut que le praticien la saisisse.
 *
 * C'est la différence entre une non-répudiation réelle et une non-répudiation décorative. Le
 * chemin le plus simple aurait été de chiffrer avec `APP_KEY` : le serveur aurait alors pu signer
 * à la place de n'importe quel médecin, et la garantie affichée aurait été fausse.
 *
 * ═══ POURQUOI AES-256-GCM ET PAS SEULEMENT CBC ═══
 *
 * GCM est authentifié : un cryptogramme altéré ne se déchiffre pas en silence, il ÉCHOUE. Sans
 * cela, une clé privée corrompue produirait des signatures invalides que personne ne saurait
 * expliquer. Motif exact des destinations de reversement chiffrées en P5.5b-1.
 *
 * ═══ L'AAD N'EST PAS DÉCORATIVE ═══
 *
 * Les données authentifiées additionnelles lient le cryptogramme à SON certificat (numéro de série
 * et identifiant du praticien). Recopier `cle_privee_chiffree` d'une ligne vers une autre — ce
 * qu'un accès en écriture à la base permettrait — produit un déchiffrement qui ÉCHOUE, au lieu
 * d'attribuer silencieusement la clé d'un médecin à un autre.
 *
 * ═══ AUCUNE DÉPENDANCE ═══
 *
 * `openssl` et `hash_pbkdf2` sont natifs à PHP 8.3 (vérifié au G0). Aucune bibliothèque de
 * cryptographie n'est ajoutée — §2.6.
 */
final class CoffreCleProfessionnel
{
    private const CHIFFREMENT = 'aes-256-gcm';

    /** Version du schéma de dérivation. Stockée avec le cryptogramme : la faire évoluer un jour
     *  n'invalidera pas les clés déjà scellées, on saura comment chacune a été fermée. */
    public const VERSION = 1;

    /**
     * 210 000 itérations — la recommandation OWASP 2023 pour PBKDF2-HMAC-SHA256.
     *
     * Ce n'est pas un réglage de performance : c'est ce qui rend une attaque par dictionnaire sur
     * un secret court coûteuse, dans l'hypothèse où la base entière fuiterait. Le coût est payé
     * une fois par signature, par un humain qui vient de taper son secret — il ne se voit pas.
     */
    private const ITERATIONS = 210_000;

    /**
     * Scelle une clé privée. Renvoie le cryptogramme et les paramètres publics du calcul.
     *
     * @return array{cle_privee_chiffree: string, nonce: string, sel_kdf: string, cle_version: int}
     */
    public function sceller(
        #[SensitiveParameter] string $clePriveePem,
        #[SensitiveParameter] string $secret,
        string $numeroSerie,
        int $medecinId,
    ): array {
        $sel   = random_bytes(16);
        $nonce = random_bytes(12);   // 96 bits, la taille recommandée pour GCM

        $etiquette = '';
        $chiffre = openssl_encrypt(
            $clePriveePem,
            self::CHIFFREMENT,
            $this->deriver($secret, $sel),
            OPENSSL_RAW_DATA,
            $nonce,
            $etiquette,
            $this->aad($numeroSerie, $medecinId),
            16,
        );

        if ($chiffre === false) {
            throw new RuntimeException('Le scellement de la clé privée a échoué.');
        }

        return [
            // Étiquette d'authentification concaténée au cryptogramme : les deux sont inséparables,
            // les stocker dans deux colonnes n'apporterait qu'une occasion de les désolidariser.
            'cle_privee_chiffree' => base64_encode($chiffre.$etiquette),
            'nonce'               => base64_encode($nonce),
            'sel_kdf'             => base64_encode($sel),
            'cle_version'         => self::VERSION,
        ];
    }

    /**
     * Ouvre le coffre. Renvoie la clé privée en PEM.
     *
     * @throws RuntimeException si le secret est faux, ou si le cryptogramme a été altéré ou déplacé
     *                          vers un autre certificat. Les deux cas sont volontairement
     *                          indiscernables de l'extérieur : distinguer « mauvais secret » de
     *                          « données altérées » renseignerait un attaquant sur l'état du coffre.
     */
    public function ouvrir(
        string $cleChiffreeBase64,
        string $nonceBase64,
        string $selBase64,
        #[SensitiveParameter] string $secret,
        string $numeroSerie,
        int $medecinId,
    ): string {
        $brut = base64_decode($cleChiffreeBase64, true);

        if ($brut === false || strlen($brut) <= 16) {
            throw new RuntimeException('Coffre illisible.');
        }

        $chiffre   = substr($brut, 0, -16);
        $etiquette = substr($brut, -16);

        $clair = openssl_decrypt(
            $chiffre,
            self::CHIFFREMENT,
            $this->deriver($secret, (string) base64_decode($selBase64, true)),
            OPENSSL_RAW_DATA,
            (string) base64_decode($nonceBase64, true),
            $etiquette,
            $this->aad($numeroSerie, $medecinId),
        );

        if ($clair === false) {
            throw new RuntimeException('Coffre illisible.');
        }

        return $clair;
    }

    /**
     * La clé de chiffrement, dérivée du secret. Jamais stockée, recalculée à chaque ouverture.
     */
    private function deriver(#[SensitiveParameter] string $secret, string $sel): string
    {
        return hash_pbkdf2('sha256', $secret, $sel, self::ITERATIONS, 32, true);
    }

    /**
     * Ce à quoi le cryptogramme est LIÉ. Déplacer une clé scellée vers un autre certificat ou un
     * autre praticien fait échouer le déchiffrement au lieu de réussir en silence.
     */
    private function aad(string $numeroSerie, int $medecinId): string
    {
        return 'masante:pki:v'.self::VERSION.':'.$numeroSerie.':'.$medecinId;
    }
}
