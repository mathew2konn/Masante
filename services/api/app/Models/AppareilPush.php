<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un téléphone enregistré pour recevoir les notifications poussées (incrément D1).
 *
 * Le jeton Expo est opaque : le serveur ne l'interprète jamais, il le recopie. Sa forme
 * (`ExponentPushToken[…]`) est vérifiée à l'entrée pour écarter une saisie manifestement fausse,
 * pas pour en tirer une information.
 */
class AppareilPush extends Model
{
    protected $table = 'appareils_push';

    protected $fillable = [
        'user_id',
        'jeton_expo',
        'plateforme',
        'vu_le',
        'revoque_le',
    ];

    protected function casts(): array
    {
        return [
            'vu_le'      => 'datetime',
            'revoque_le' => 'datetime',
        ];
    }

    /** @param Builder<AppareilPush> $query */
    public function scopeActif(Builder $query): void
    {
        $query->whereNull('revoque_le');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
