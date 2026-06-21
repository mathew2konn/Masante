<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Avis patient sur une structure (CdC §8.4, F3.9). `user_id` est masqué (confidentialité) ;
 * on n'expose qu'un libellé d'auteur (prénom). `note_moyenne`/`nb_avis` de la structure sont
 * recalculés à chaque écriture (AvisController).
 */
class Avis extends Model
{
    protected $table = 'avis';

    protected $fillable = [
        'structure_id',
        'user_id',
        'note',
        'commentaire',
        'consultation_verifiee',
        'signale',
        'visible',
    ];

    protected $hidden = [
        'user_id',
        'user',
    ];

    protected $appends = ['auteur'];

    protected function casts(): array
    {
        return [
            'note' => 'integer',
            'consultation_verifiee' => 'boolean',
            'signale' => 'boolean',
            'visible' => 'boolean',
        ];
    }

    public function structure(): BelongsTo
    {
        return $this->belongsTo(StructureSanitaire::class, 'structure_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Libellé d'auteur exposé publiquement : prénom uniquement (anonymisation partielle). */
    public function getAuteurAttribute(): string
    {
        return $this->relationLoaded('user') && $this->user
            ? ($this->user->prenom ?? 'Patient')
            : 'Patient';
    }
}
