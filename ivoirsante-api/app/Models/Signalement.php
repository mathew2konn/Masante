<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Signalement citoyen sur une structure (CdC §8.4, F3.10). Anonyme possible (`user_id` nullable
 * et masqué). Validation/modération par l'admin (Module 4) ; seuls les signalements
 * `visible_publiquement` apparaissent dans l'historique public.
 */
class Signalement extends Model
{
    protected $fillable = [
        'type',
        'structure_id',
        'user_id',
        'description',
        'statut',
        'visible_publiquement',
    ];

    protected $hidden = [
        'user_id',
    ];

    protected function casts(): array
    {
        return [
            'visible_publiquement' => 'boolean',
        ];
    }

    public function structure(): BelongsTo
    {
        return $this->belongsTo(StructureSanitaire::class, 'structure_id');
    }
}
