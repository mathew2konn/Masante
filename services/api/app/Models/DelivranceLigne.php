<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Ce qui a été servi d'une ligne de prescription, lors d'une délivrance (B3-a). */
class DelivranceLigne extends Model
{
    protected $table = 'delivrance_lignes';

    protected $fillable = ['quantite'];

    public function delivrance(): BelongsTo
    {
        return $this->belongsTo(Delivrance::class, 'delivrance_id');
    }

    public function ligne(): BelongsTo
    {
        return $this->belongsTo(OrdonnanceLigne::class, 'ordonnance_ligne_id');
    }
}
