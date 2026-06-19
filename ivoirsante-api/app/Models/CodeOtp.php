<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Code OTP à usage unique (doc Identification §5.2).
 * Le code en clair n'existe jamais en base : seul `code_hash` (bcrypt) est stocké.
 */
class CodeOtp extends Model
{
    protected $table = 'codes_otp';

    protected $fillable = [
        'user_id',
        'telephone',
        'code_hash',
        'but',
        'expires_at',
        'used_at',
        'tentatives',
    ];

    protected $hidden = [
        'code_hash',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'used_at' => 'datetime',
        'tentatives' => 'integer',
    ];

    /** Le code est-il encore exploitable (non utilisé, non expiré, tentatives < max) ? */
    public function estActif(int $maxTentatives = 5): bool
    {
        return $this->used_at === null
            && $this->expires_at->isFuture()
            && $this->tentatives < $maxTentatives;
    }
}
