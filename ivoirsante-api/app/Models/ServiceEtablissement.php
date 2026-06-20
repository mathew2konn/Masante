<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Service médical d'une structure (CdC §8.4). `specialite` permet le matching avec la
 * spécialité déduite par le triage (F1.5). Configuration par les gestionnaires : Module 4.
 */
class ServiceEtablissement extends Model
{
    protected $table = 'services_etablissement';

    protected $fillable = [
        'structure_id',
        'nom_service',
        'specialite',
        'actif',
    ];

    protected function casts(): array
    {
        return [
            'actif' => 'boolean',
        ];
    }

    public function structure(): BelongsTo
    {
        return $this->belongsTo(StructureSanitaire::class, 'structure_id');
    }

    public function disponibilites(): HasMany
    {
        return $this->hasMany(DisponibiliteJour::class, 'service_id');
    }
}
