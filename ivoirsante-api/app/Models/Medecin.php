<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Praticien réservable d'une structure (Module 3 / F3.5, Analyse_Delta_RDV N5). Annuaire
 * professionnel public, non sensible : rattaché à UN service. `tarif_consultation` est indicatif
 * (aucun règlement). Configuration par les gestionnaires : Module 4.
 */
class Medecin extends Model
{
    protected $table = 'medecins';

    protected $fillable = [
        'structure_id',
        'service_id',
        'titre',
        'nom',
        'prenom',
        'specialite',
        'tarif_consultation',
        'actif',
    ];

    protected function casts(): array
    {
        return [
            'tarif_consultation' => 'integer',
            'actif' => 'boolean',
        ];
    }

    public function structure(): BelongsTo
    {
        return $this->belongsTo(StructureSanitaire::class, 'structure_id');
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(ServiceEtablissement::class, 'service_id');
    }
}
