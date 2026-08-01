<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Token QR dynamique (CdC §8.1 ; Sécurité §5). Porte le HASH du token (jamais le clair),
 * son expiration (10 min) et son état de consommation (usage unique).
 *
 * Pas de `updated_at` : un token est créé puis consommé une seule fois via un update ciblé
 * (used_at / used_by_*) ; on gère donc `created_at` seul (défaut SQL useCurrent).
 */
class TokenQr extends Model
{
    protected $table = 'tokens_qr';

    public $timestamps = false;

    protected $fillable = [
        'membre_id',
        'genere_par_delegue_id',
        'token_hash',
        'expires_at',
        'used_at',
        'used_by_agent_id',
        'used_by_etablissement',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'used_at'    => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    public function membre(): BelongsTo
    {
        return $this->belongsTo(MembreFamille::class, 'membre_id');
    }

    /** Le token a-t-il déjà été consommé ? */
    public function estConsomme(): bool
    {
        return $this->used_at !== null;
    }

    /** Le token est-il expiré ? */
    public function estExpire(): bool
    {
        return $this->expires_at->isPast();
    }
}
