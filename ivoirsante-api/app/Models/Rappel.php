<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Rappel d'un membre (CdC §8.3, F2.7) — médicament, rendez-vous, vaccin, contrôle.
 * CRUD seul ici ; l'envoi push (Firebase) est différé (cf. plan Module 2).
 */
class Rappel extends Model
{
    protected $fillable = [
        'type',
        'titre',
        'contenu',
        'frequence',
        'heure',
        'date_debut',
        'date_fin',
        'actif',
    ];

    protected function casts(): array
    {
        return [
            'date_debut'   => 'date',
            'date_fin'     => 'date',
            'actif'        => 'boolean',
            'last_sent_at' => 'datetime',
        ];
    }

    public function membre(): BelongsTo
    {
        return $this->belongsTo(MembreFamille::class, 'membre_id');
    }
}
