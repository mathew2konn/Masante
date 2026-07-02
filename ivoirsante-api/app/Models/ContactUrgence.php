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

    /**
     * Invariant métier : au plus un contact `est_principal` par membre. Dès qu'un contact est
     * marqué principal, les autres du même membre repassent à `false`. La mise à jour de masse
     * ne déclenche pas d'événement Eloquent → aucune récursion.
     */
    protected static function booted(): void
    {
        static::saved(function (ContactUrgence $contact): void {
            if ($contact->est_principal) {
                static::query()
                    ->where('membre_id', $contact->membre_id)
                    ->whereKeyNot($contact->getKey())
                    ->update(['est_principal' => false]);
            }
        });
    }

    public function membre(): BelongsTo
    {
        return $this->belongsTo(MembreFamille::class, 'membre_id');
    }
}
