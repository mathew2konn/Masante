<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * L'autorité de certification racine (CDC_10 §4.1 ; P6.5b).
 *
 * **Auto-signée**, et l'ADR-032 le dit sans détour : aucune autorité nationale n'a été consultée.
 * Elle ne sert pas à authentifier un serveur mais à lier une prescription à un praticien identifié
 * dans cette plateforme.
 *
 * `cle_privee_chiffree` est CACHÉE : elle est protégée par une phrase de passe d'environnement,
 * mais une clé privée n'a rien à faire dans une réponse JSON, fût-elle chiffrée. Ce que l'on
 * publie d'une autorité, c'est son certificat et son empreinte.
 */
class AutoriteCertification extends Model
{
    protected $table = 'autorites_certification';

    protected $fillable = [
        'nom',
        'pays_code',
        'certificat_pem',
        'cle_privee_chiffree',
        'empreinte',
        'numero_serie',
        'valide_du',
        'valide_jusqu_a',
        'actif',
    ];

    protected $hidden = ['cle_privee_chiffree'];

    protected function casts(): array
    {
        return [
            'valide_du'      => 'datetime',
            'valide_jusqu_a' => 'datetime',
            'actif'          => 'boolean',
        ];
    }

    public function certificats(): HasMany
    {
        return $this->hasMany(CertificatNumerique::class, 'autorite_id');
    }
}
