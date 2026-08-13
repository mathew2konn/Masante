<?php

namespace App\Services\Pki;

use App\Models\AutoriteCertification as ModeleAutorite;
use App\Models\CertificatNumerique;
use App\Models\Medecin;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;
use SensitiveParameter;

/**
 * L'autorité de certification de MaSanté (CDC_10 §4.1 ; CDC_09 §5.3).
 *
 * ═══ CE QU'ELLE EST, DIT SANS ENJOLIVER ═══
 *
 * Une CA racine **auto-signée**. Aucune autorité de certification nationale ivoirienne n'a été
 * consultée, aucune chaîne de confiance publique n'existe. Un navigateur ne reconnaîtra pas ces
 * certificats, et c'est normal : ils ne servent pas à authentifier un serveur mais à lier une
 * prescription à un praticien identifié DANS cette plateforme.
 *
 * Prétendre le contraire — l'appeler « autorité nationale », lui donner des airs officiels — serait
 * le genre de fausseté qui ne se fait pas corriger parce qu'elle a l'air juste.
 *
 * ═══ CE QU'ELLE PEUT ET CE QU'ELLE NE PEUT PAS ═══
 *
 * Sa clé privée est chiffrée par une phrase de passe d'ENVIRONNEMENT : le serveur peut donc signer
 * EN TANT QUE CA, ce qui est nécessaire — émettre un certificat est une opération serveur
 * déclenchée par un humain. Ce qu'aucun chemin ne permet, c'est de signer EN TANT QUE PRATICIEN :
 * cette clé-là dort dans {@see CoffreCleProfessionnel}, fermée par un secret que le serveur ignore.
 *
 * ═══ POURQUOI LE `config` EST PASSÉ À CHAQUE APPEL ═══
 *
 * Les fonctions X.509 de PHP échouent sans fichier de configuration OpenSSL, et l'environnement de
 * développement n'en expose aucun (« configuration file routines::no such file », G0). On embarque
 * le nôtre plutôt que de dépendre d'un chemin propre à un poste.
 */
final class AutoriteCertification
{
    public function __construct(private readonly CoffreCleProfessionnel $coffre) {}

    /**
     * Crée l'autorité racine. IDEMPOTENTE PAR REFUS, et non par silence : si une autorité active
     * existe déjà, on lève. Régénérer une CA invaliderait TOUS les certificats émis — donc toutes
     * les signatures passées. Ce n'est pas une opération qu'on laisse réussir « au cas où ».
     */
    public function creerAutorite(): ModeleAutorite
    {
        if (ModeleAutorite::where('actif', true)->exists()) {
            throw new RuntimeException(
                'Une autorité racine active existe déjà. La régénérer invaliderait tous les '
                .'certificats émis, donc toutes les signatures posées.'
            );
        }

        $phrase = $this->phraseDePasse();
        $config = $this->options();

        $cle = openssl_pkey_new($config);

        if ($cle === false) {
            throw new RuntimeException('Génération de la clé de l\'autorité impossible : '.$this->erreurs());
        }

        $sujet = [
            'countryName'      => config('pki.ca.pays'),
            'organizationName' => config('pki.ca.organisation'),
            'commonName'       => config('pki.ca.nom'),
        ];

        $serie = $this->numeroSerie();
        $jours = (int) config('pki.ca.validite_ans') * 365;

        $demande = openssl_csr_new($sujet, $cle, $config + ['req_extensions' => 'v3_ca']);

        if ($demande === false) {
            throw new RuntimeException('Demande de certificat de l\'autorité impossible : '.$this->erreurs());
        }

        // `null` en émetteur = auto-signature. `v3_ca` lui donne le droit de signer d'autres
        // certificats — et `pathlen:0` interdit à ceux-ci d'en signer à leur tour.
        $certificat = openssl_csr_sign($demande, null, $cle, $jours, $config + ['x509_extensions' => 'v3_ca'], $serie);

        if ($certificat === false) {
            throw new RuntimeException('Auto-signature de l\'autorité impossible : '.$this->erreurs());
        }

        openssl_x509_export($certificat, $pem);
        // Chiffrement PEM standard par la phrase de passe d'environnement.
        openssl_pkey_export($cle, $clePriveePem, $phrase, $config);

        $maintenant = Carbon::now();

        return ModeleAutorite::create([
            'nom'                 => config('pki.ca.nom'),
            'pays_code'           => config('pki.ca.pays'),
            'certificat_pem'      => $pem,
            'cle_privee_chiffree' => $clePriveePem,
            'empreinte'           => hash('sha256', $pem),
            'numero_serie'        => (string) $serie,
            'valide_du'           => $maintenant,
            'valide_jusqu_a'      => $maintenant->copy()->addDays($jours),
            'actif'               => true,
        ]);
    }

    /**
     * Émet le certificat d'un professionnel et scelle sa clé privée avec SON secret.
     *
     * ═══ LA CLÉ PRIVÉE N'EXISTE EN CLAIR QUE LE TEMPS DE CET APPEL ═══
     *
     * Elle est générée, scellée, puis la variable est écrasée. Elle n'est jamais journalisée,
     * jamais renvoyée, jamais écrite ailleurs que chiffrée. C'est la seule fenêtre où le serveur
     * la voit, et elle se referme avant le retour de la méthode.
     *
     * CE QUE CETTE MÉTHODE NE VÉRIFIE PAS : le droit d'exercer. Ce jugement appartient à
     * {@see ReglesVerificationSignature}, en un seul endroit, pour qu'il ne puisse pas être rendu
     * différemment ici et là. L'appelant l'a déjà interrogé.
     */
    public function emettre(Medecin $professionnel, #[SensitiveParameter] string $secret): CertificatNumerique
    {
        $autorite = ModeleAutorite::where('actif', true)->first();

        if ($autorite === null) {
            throw new RuntimeException(
                "Aucune autorité de certification n'a été créée (php artisan masante:pki:autorite)."
            );
        }

        return DB::transaction(function () use ($autorite, $professionnel, $secret): CertificatNumerique {
            // Verrou sur la fiche : deux émissions simultanées ne peuvent pas laisser le praticien
            // avec deux certificats actifs — l'unicité étant applicative (MySQL n'a pas d'index
            // unique partiel), c'est ce verrou qui la porte, et rien d'autre.
            Medecin::whereKey($professionnel->getKey())->lockForUpdate()->firstOrFail();

            $this->revoquerLesActifs($professionnel, 'Remplacé par un nouveau certificat.');

            $config = $this->options();
            $cle    = openssl_pkey_new($config);

            if ($cle === false) {
                throw new RuntimeException('Génération de la clé du professionnel impossible : '.$this->erreurs());
            }

            $sujet = [
                'countryName'            => $professionnel->pays_code ?? config('pki.ca.pays'),
                'organizationName'       => config('pki.ca.organisation'),
                // Le numéro national dans le sujet : c'est lui qui relie le certificat au
                // référentiel de P6.5a, et non l'identifiant technique de la ligne.
                'organizationalUnitName' => $professionnel->numero_professionnel ?? 'SANS-NUMERO',
                'commonName'             => $professionnel->nom_complet,
            ];

            $serie   = $this->numeroSerie();
            $jours   = (int) config('pki.certificat.validite_jours');
            $demande = openssl_csr_new($sujet, $cle, $config);

            if ($demande === false) {
                throw new RuntimeException('Demande de certificat impossible : '.$this->erreurs());
            }

            $clePriveeCa = openssl_pkey_get_private($autorite->cle_privee_chiffree, $this->phraseDePasse());

            if ($clePriveeCa === false) {
                throw new RuntimeException(
                    "La clé de l'autorité n'a pas pu être ouverte : PKI_CA_PASSPHRASE est absente ou incorrecte."
                );
            }

            $certificat = openssl_csr_sign(
                $demande,
                $autorite->certificat_pem,
                $clePriveeCa,
                $jours,
                $config + ['x509_extensions' => 'v3_professionnel'],
                $serie,
            );

            if ($certificat === false) {
                throw new RuntimeException('Signature du certificat impossible : '.$this->erreurs());
            }

            openssl_x509_export($certificat, $pem);
            openssl_pkey_export($cle, $clePriveePem, null, $config);

            $coffre = $this->coffre->sceller($clePriveePem, $secret, (string) $serie, (int) $professionnel->getKey());

            // La clé en clair a fini son office : on l'efface avant même de sortir de la méthode.
            $clePriveePem = str_repeat("\0", strlen($clePriveePem));
            unset($clePriveePem);

            $maintenant = Carbon::now();

            return CertificatNumerique::create([
                'medecin_id'           => $professionnel->getKey(),
                'autorite_id'          => $autorite->id,
                'numero_professionnel' => $professionnel->numero_professionnel,
                'sujet'                => openssl_x509_parse($certificat)['name'] ?? '',
                'numero_serie'         => (string) $serie,
                'certificat_pem'       => $pem,
                'empreinte'            => hash('sha256', $pem),
                ...$coffre,
                // BCrypt : sert à distinguer un secret erroné d'un coffre corrompu et à compter les
                // échecs. Il ne déchiffre rien — un hachage n'est pas une clé.
                'secret_hash'          => Hash::make($secret),
                'statut'               => 'actif',
                'valide_du'            => $maintenant,
                'valide_jusqu_a'       => $maintenant->copy()->addDays($jours),
            ]);
        }, 3);
    }

    /**
     * Révoque les certificats actifs d'un praticien (§5.4 « révocation »).
     *
     * La révocation est DÉFINITIVE et le certificat n'est pas supprimé : les signatures déjà
     * posées le référencent, et une signature dont le certificat aurait disparu deviendrait
     * invérifiable — ce qui reviendrait à l'effacer.
     */
    public function revoquerLesActifs(Medecin $professionnel, string $motif, ?int $parUserId = null): int
    {
        return CertificatNumerique::where('medecin_id', $professionnel->getKey())
            ->where('statut', 'actif')
            ->update([
                'statut'           => 'revoque',
                'revoque_le'       => Carbon::now(),
                'revocation_motif' => $motif,
                'revoque_par'      => $parUserId,
                'updated_at'       => Carbon::now(),
            ]);
    }

    /** Le certificat actif d'un praticien, s'il en a un. Sert à savoir s'il peut en demander un. */
    public function certificatActif(Medecin $professionnel): ?CertificatNumerique
    {
        return CertificatNumerique::where('medecin_id', $professionnel->getKey())
            ->where('statut', 'actif')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Le DERNIER certificat d'un praticien, révoqué compris.
     *
     * ═══ POURQUOI CETTE MÉTHODE EXISTE — défaut trouvé au G2 live ═══
     *
     * Le service de signature interrogeait `certificatActif()`. Après une révocation, celle-ci ne
     * renvoie plus rien : les règles concluaient donc « **aucun certificat n'a été émis pour ce
     * professionnel** », alors qu'un certificat existait bel et bien et venait d'être révoqué.
     *
     * Deux conséquences, et la seconde est la plus grave :
     *   · le contrôle `REVOCATION` du §5.4 était **inatteignable** — un contrôle que le corpus
     *     exige nommément ne pouvait jamais se déclencher ;
     *   · le journal enregistrait `controle: certificat` au lieu de `controle: revocation`, et le
     *     praticien lisait un message faux. En litige, la trace aurait dit autre chose que le fait.
     *
     * C'est aux RÈGLES de juger, pas à la requête de préjuger : le service rassemble l'état, les
     * règles tranchent. Chercher « le certificat actif » revenait à décider avant de demander.
     */
    public function dernierCertificat(Medecin $professionnel): ?CertificatNumerique
    {
        return CertificatNumerique::where('medecin_id', $professionnel->getKey())
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Le certificat est-il bien issu de notre autorité ?
     *
     * Contrôle de la CHAÎNE, distinct de la vérification de la signature d'un document : il répond
     * à « ce certificat vient-il de nous ? », pas à « ce document a-t-il été signé avec ? ».
     */
    public function chaineValide(CertificatNumerique $certificat): bool
    {
        $autorite = $certificat->autorite;

        return $autorite !== null
            && openssl_x509_verify($certificat->certificat_pem, $autorite->certificat_pem) === 1;
    }

    /** @return array<string, mixed> */
    private function options(): array
    {
        return [
            'config'            => config('pki.openssl_conf'),
            'private_key_bits'  => (int) config('pki.certificat.taille_cle'),
            'private_key_type'  => OPENSSL_KEYTYPE_RSA,
            'digest_alg'        => 'sha256',
        ];
    }

    private function phraseDePasse(): string
    {
        $phrase = config('pki.ca_passphrase');

        // ÉCHEC BRUYANT PLUTÔT QUE VALEUR DE REPLI. Une phrase par défaut serait un secret du
        // dépôt, et pire : un secret que tout le monde croirait avoir remplacé. Même principe que
        // la commission sans seed en P5.5a — l'absence doit se voir.
        if (! is_string($phrase) || $phrase === '') {
            throw new RuntimeException(
                'PKI_CA_PASSPHRASE est absente. Aucune phrase par défaut n\'est fournie : ce serait '
                .'un secret dans le dépôt (CDC_10 §5).'
            );
        }

        return $phrase;
    }

    /**
     * Numéro de série : 63 bits aléatoires.
     *
     * Aléatoire et non incrémental — un compteur ferait fuiter le nombre de praticiens certifiés,
     * et rendrait prévisible le prochain numéro. La recommandation du RFC 5280 va dans ce sens.
     */
    private function numeroSerie(): int
    {
        return random_int(1, PHP_INT_MAX);
    }

    private function erreurs(): string
    {
        $messages = [];

        while (($erreur = openssl_error_string()) !== false) {
            $messages[] = $erreur;
        }

        return implode(' ; ', $messages) ?: 'cause inconnue';
    }
}
