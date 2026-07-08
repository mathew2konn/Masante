<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

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
        'medecin_id',
        'mode_attribution',
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

    /** Praticien choisi (F3.5). NULL si l'établissement attribue (fixé par l'agent au Module 4). */
    public function medecin(): BelongsTo
    {
        return $this->belongsTo(Medecin::class, 'medecin_id');
    }

    /** Reçu de RDV (N2) — au plus un par RDV. */
    public function recu(): HasOne
    {
        return $this->hasOne(RecuRdv::class, 'rendez_vous_id');
    }

    /** Paiements du RDV (N1, simulés). */
    public function paiements(): HasMany
    {
        return $this->hasMany(Paiement::class, 'rendez_vous_id');
    }

    public function triage(): BelongsTo
    {
        return $this->belongsTo(Triage::class, 'triage_id');
    }
}
