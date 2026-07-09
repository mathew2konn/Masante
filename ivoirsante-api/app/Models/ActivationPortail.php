<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

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

    /**
     * Émet un jeton d'activation à usage unique (24h) pour un compte staff et renvoie sa valeur EN CLAIR.
     * Tout jeton antérieur non consommé est invalidé. Seul le hash est persisté. Source de vérité partagée
     * par le contrôleur (création d'établissement) et la commande de démo.
     */
    public static function genererPour(User $user): string
    {
        static::where('user_id', $user->id)->whereNull('used_at')->update(['used_at' => now()]);

        $token = Str::random(64);
        static::create([
            'user_id'    => $user->id,
            'token_hash' => hash('sha256', $token),
            'expires_at' => now()->addHours(24),
        ]);

        return $token;
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
