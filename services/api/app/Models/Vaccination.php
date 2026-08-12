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
        // D0 — provenance. Jusqu'ici absentes de cette table : l'incrément C les forçait en vain,
        // Eloquent les écartait sans bruit. `source` et `added_by` sont TOUJOURS réécrits par le
        // serveur (contribution → `patient`, écriture soignant → `medecin`) : les rendre
        // assignables ici n'ouvre donc rien au client.
        'added_by',
        'source',
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
