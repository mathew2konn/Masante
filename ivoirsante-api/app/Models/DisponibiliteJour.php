<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Disponibilité quotidienne d'un service (CdC §8.4, F3.3). Alimente la pastille de la carte.
 * Écriture par les agents + synchro Firebase : Module 4. En 3A.1, lignes seedées.
 */
class DisponibiliteJour extends Model
{
    protected $table = 'disponibilites_jour';

    protected $fillable = [
        'service_id',
        'date',
        'statut',
        'nb_places_restantes',
        'heure_debut_dispo',
        'note',
        'updated_by_agent_id',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
        ];
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(ServiceEtablissement::class, 'service_id');
    }
}
