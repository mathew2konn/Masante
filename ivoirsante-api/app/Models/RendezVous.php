<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Demande de rendez-vous (CdC §8.4, F3.6). Côté patient en Module 3 (création/suivi/annulation) ;
 * la validation par l'agent (date_confirmee, message, confirme/refuse) relève du Module 4.
 */
class RendezVous extends Model
{
    protected $table = 'rendez_vous';

    protected $fillable = [
        'membre_id',
        'structure_id',
        'service_id',
        'triage_id',
        'motif',
        'date_souhaitee',
        'date_confirmee',
        'statut',
        'message_agent',
    ];

    protected function casts(): array
    {
        return [
            'date_souhaitee' => 'date',
            'date_confirmee' => 'datetime',
        ];
    }

    public function membre(): BelongsTo
    {
        return $this->belongsTo(MembreFamille::class, 'membre_id');
    }

    public function structure(): BelongsTo
    {
        return $this->belongsTo(StructureSanitaire::class, 'structure_id');
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(ServiceEtablissement::class, 'service_id');
    }

    public function triage(): BelongsTo
    {
        return $this->belongsTo(Triage::class, 'triage_id');
    }
}
