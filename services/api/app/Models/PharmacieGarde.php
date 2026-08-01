<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Inscription d'une pharmacie de garde pour une date (CdC F3.8). Seedée pour la démo
 * (en production : mise à jour quotidienne par l'Ordre des Pharmaciens CI).
 */
class PharmacieGarde extends Model
{
    protected $table = 'pharmacies_garde';

    protected $fillable = [
        'structure_id',
        'date',
        'periode',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
        ];
    }

    public function structure(): BelongsTo
    {
        return $this->belongsTo(StructureSanitaire::class, 'structure_id');
    }
}
