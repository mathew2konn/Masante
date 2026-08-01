<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Délégation d'accès (voie 3, Note_Continuite chap. 4) : droit accordé à un tiers de GÉNÉRER le QR
 * d'un membre du carnet. Active = acceptée par le délégué ET non révoquée par le titulaire.
 */
class Delegation extends Model
{
    protected $fillable = [
        'titulaire_user_id',
        'delegue_user_id',
        'membre_id',
        'droits',
        'invitee_at',
        'acceptee_at',
        'revoquee_at',
    ];

    protected function casts(): array
    {
        return [
            'invitee_at' => 'datetime',
            'acceptee_at' => 'datetime',
            'revoquee_at' => 'datetime',
        ];
    }

    public function titulaire(): BelongsTo
    {
        return $this->belongsTo(User::class, 'titulaire_user_id');
    }

    public function delegue(): BelongsTo
    {
        return $this->belongsTo(User::class, 'delegue_user_id');
    }

    public function membre(): BelongsTo
    {
        return $this->belongsTo(MembreFamille::class, 'membre_id');
    }

    /** Acceptée et non révoquée. */
    public function estActive(): bool
    {
        return $this->acceptee_at !== null && $this->revoquee_at === null;
    }

    /** @param Builder<Delegation> $query */
    public function scopeActive(Builder $query): void
    {
        $query->whereNotNull('acceptee_at')->whereNull('revoquee_at');
    }

    /** Le délégué a-t-il une délégation ACTIVE sur ce membre ? (contrôle d'autorisation QR). */
    public static function actifPour(int $delegueUserId, int $membreId): bool
    {
        return static::query()
            ->where('delegue_user_id', $delegueUserId)
            ->where('membre_id', $membreId)
            ->active()
            ->exists();
    }
}
