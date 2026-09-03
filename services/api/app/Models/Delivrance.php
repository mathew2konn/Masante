<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Un acte de délivrance en officine (B3-a, CDC_11 §7.1, CDC_04 §105).
 *
 * AUCUNE COLONNE « statut ». « Cette ordonnance est-elle entièrement servie ? » se DÉDUIT des
 * lignes : une valeur stockée recalculable finit par diverger de ce qu'elle résume — leçon du
 * wallet (P5.3a), où le solde est une somme et jamais une colonne.
 *
 * `pharmacien_nom` est FIGÉ, et `pharmacien_user_id` est un identifiant sans clé étrangère
 * (ADR-042 D1) : l'acte doit continuer de nommer son auteur même si le compte disparaît.
 */
class Delivrance extends Model
{
    protected $fillable = [];

    protected function casts(): array
    {
        return ['delivree_le' => 'datetime'];
    }

    public function ordonnance(): BelongsTo
    {
        return $this->belongsTo(Ordonnance::class, 'ordonnance_id');
    }

    public function structure(): BelongsTo
    {
        return $this->belongsTo(StructureSanitaire::class, 'structure_id');
    }

    public function lignes(): HasMany
    {
        return $this->hasMany(DelivranceLigne::class, 'delivrance_id');
    }
}
