<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Vaccination d'un membre (CdC §8.3, F2.7) — calendrier vaccinal ivoirien / OMS.
 */
class Vaccination extends Model
{
    protected $fillable = [
        'vaccin_nom',
        'obligatoire',
        'date_administration',
        'date_rappel',
        'statut',
        'centre_vaccination',
        'numero_lot',
        'medecin_nom',
    ];

    protected function casts(): array
    {
        return [
            'obligatoire'         => 'boolean',
            'date_administration' => 'date',
            'date_rappel'         => 'date',
        ];
    }

    public function membre(): BelongsTo
    {
        return $this->belongsTo(MembreFamille::class, 'membre_id');
    }
}
