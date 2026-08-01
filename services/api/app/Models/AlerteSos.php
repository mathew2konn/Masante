<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Alerte SOS déclenchée par un patient (CdC FN1, Module 5.2).
 *
 * Journal en ajout seul, comme `acces_dossier` : une alerte s'est produite ou non, elle ne se
 * corrige pas après coup. Le contact prévenu est dénormalisé (nom + téléphone copiés) pour que la
 * trace reste exacte même si le contact d'urgence est modifié ou supprimé par la suite.
 */
class AlerteSos extends Model
{
    protected $table = 'alertes_sos';

    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'membre_id',
        'latitude',
        'longitude',
        'precision_metres',
        'canal',
        'contact_prevenu_nom',
        'contact_prevenu_tel',
    ];

    protected function casts(): array
    {
        return [
            'latitude'         => 'float',
            'longitude'        => 'float',
            'precision_metres' => 'integer',
            'declenchee_le'    => 'datetime',
        ];
    }

    /** L'alerte portait-elle une position exploitable par les secours ? */
    public function aUnePosition(): bool
    {
        return $this->latitude !== null && $this->longitude !== null;
    }

    public function membre(): BelongsTo
    {
        return $this->belongsTo(MembreFamille::class, 'membre_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
