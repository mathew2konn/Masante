<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Jeton intermédiaire de réinitialisation de mot de passe (usage unique, ~10 min).
 * Délivré après OTP + preuve durcie, échangé au dernier appel `reset`. Voir la migration.
 */
class PasswordResetGrant extends Model
{
    protected $fillable = [
        'user_id',
        'telephone',
        'token_hash',
        'expires_at',
        'used_at',   // marqué à la consommation (usage unique).
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'used_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
