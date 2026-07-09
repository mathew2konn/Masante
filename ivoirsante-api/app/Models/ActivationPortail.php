<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Module 4 / 4.2 — Jeton d'activation d'un compte staff du portail (CdC §5.4.1).
 *
 * Usage unique (`used_at`), expiration 24h (`expires_at`). Seul le HASH du jeton est stocké
 * (`token_hash`) ; la valeur en clair n'existe que dans le lien remis au titulaire. Voir la migration.
 */
class ActivationPortail extends Model
{
    protected $table = 'activations_portail';

    protected $fillable = [
        'user_id',
        'token_hash',
        'expires_at',
        'used_at',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'used_at' => 'datetime',
        ];
    }

    /** Le jeton est-il encore utilisable (non consommé et non expiré) ? */
    public function estValide(): bool
    {
        return $this->used_at === null && $this->expires_at->isFuture();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
