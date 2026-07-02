<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Contact d'urgence (personne à prévenir) rattaché à un membre (F2.11). Alimente la carte vitale
 * d'urgence (Module 5). `est_principal` : contact prioritaire (au plus un par membre, règle applicative).
 */
class ContactUrgence extends Model
{
    protected $table = 'contacts_urgence';

    protected $fillable = [
        'nom',
        'lien_parente',
        'telephone',
        'telephone_secondaire',
        'email',
        'est_principal',
    ];

    protected function casts(): array
    {
        return [
            'est_principal' => 'boolean',
        ];
    }

    public function membre(): BelongsTo
    {
        return $this->belongsTo(MembreFamille::class, 'membre_id');
    }
}
