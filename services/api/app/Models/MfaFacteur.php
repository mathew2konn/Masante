<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * P1 (Identité) — Un facteur d'authentification à deux étapes rattaché à un compte
 * (CDC_10 §3.5). Le `secret` TOTP est chiffré au repos (cast 'encrypted') et masqué
 * des sérialisations : il ne quitte le backend qu'une seule fois, à l'enrôlement.
 */
class MfaFacteur extends Model
{
    protected $table = 'mfa_facteurs';

    protected $fillable = [
        'user_id',
        'type',
        'secret',
        'confirmed_at',
        'last_used_at',
        'last_timeslice',
    ];

    /** Le secret ne doit jamais fuiter dans une réponse API. */
    protected $hidden = [
        'secret',
    ];

    protected function casts(): array
    {
        return [
            'secret' => 'encrypted',
            'confirmed_at' => 'datetime',
            'last_used_at' => 'datetime',
        ];
    }

    /** Le facteur est-il confirmé (premier code validé) et donc exigible à la connexion ? */
    public function estConfirme(): bool
    {
        return $this->confirmed_at !== null;
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
