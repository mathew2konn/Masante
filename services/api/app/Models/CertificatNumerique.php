<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Le certificat numérique d'un professionnel (CDC_04 §5.2 ; CDC_09 §5.3 ; P6.5b).
 *
 * ═══ CE QUI EST CACHÉ, ET POURQUOI CE N'EST PAS DE LA PRUDENCE DÉCORATIVE ═══
 *
 * `cle_privee_chiffree`, `nonce`, `sel_kdf` et `secret_hash` ne sortent jamais du serveur.
 *
 * Le cryptogramme est inutilisable sans le secret du praticien — mais l'exposer offrirait à qui le
 * récupère un oracle hors ligne : il pourrait essayer des millions de secrets sans que rien ne
 * l'en empêche, là où le compteur d'échecs et le verrou temporaire l'arrêtent en ligne. Le nonce
 * et le sel sont publics par construction, mais les publier avec le cryptogramme reviendrait à
 * livrer le nécessaire complet.
 *
 * `secret_hash` est un BCrypt : il ne déchiffre rien, mais il se casse hors ligne comme n'importe
 * quel hachage de mot de passe.
 *
 * Ce que l'on publie d'un certificat, c'est ce qui sert à VÉRIFIER : le PEM (qui porte la clé
 * publique), l'empreinte, le numéro de série, les dates et le statut.
 *
 * ═══ AUCUN ACCESSEUR « valide ? » ICI ═══
 *
 * Ce jugement est celui du §5.4 : il doit être rendu en un seul endroit, journalisé quand il
 * refuse, et testé comme tel. Il vit dans {@see \App\Services\Pki\ReglesVerificationSignature}.
 * Le poser aussi sur le modèle créerait deux vérités — la faute que P6.4a a évitée en refusant de
 * recopier la note d'avis dans le référentiel.
 */
class CertificatNumerique extends Model
{
    protected $table = 'certificats_numeriques';

    protected $fillable = [
        'medecin_id',
        'autorite_id',
        'numero_professionnel',
        'sujet',
        'numero_serie',
        'certificat_pem',
        'empreinte',
        'cle_privee_chiffree',
        'nonce',
        'sel_kdf',
        'cle_version',
        'secret_hash',
        'statut',
        'valide_du',
        'valide_jusqu_a',
        'revoque_le',
        'revocation_motif',
        'revoque_par',
    ];

    protected $hidden = ['cle_privee_chiffree', 'nonce', 'sel_kdf', 'secret_hash'];

    protected function casts(): array
    {
        return [
            'valide_du'          => 'datetime',
            'valide_jusqu_a'     => 'datetime',
            'revoque_le'         => 'datetime',
            'verrouille_jusqu_a' => 'datetime',
            'echecs_secret'      => 'integer',
            'cle_version'        => 'integer',
        ];
    }

    public function professionnel(): BelongsTo
    {
        return $this->belongsTo(Medecin::class, 'medecin_id');
    }

    public function autorite(): BelongsTo
    {
        return $this->belongsTo(AutoriteCertification::class, 'autorite_id');
    }

    /** La clé PUBLIQUE, extraite du certificat — la seule moitié qui serve à vérifier. */
    public function clePublique(): \OpenSSLAsymmetricKey|false
    {
        return openssl_pkey_get_public($this->certificat_pem);
    }
}
