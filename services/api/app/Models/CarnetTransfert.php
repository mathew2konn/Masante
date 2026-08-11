<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Trace d'un changement de propriétaire d'un carnet (incrément B).
 *
 * IMMUABLE, comme `acces_dossier` : un dossier médical qui change de mains doit rester
 * explicable après coup (loi 2013-450, Sécurité §10.2). Une trace modifiable ne prouve rien.
 */
class CarnetTransfert extends Model
{
    protected $table = 'carnet_transferts';

    public const UPDATED_AT = null;

    public const MOTIF_REVENDICATION = 'revendication';

    protected $fillable = [
        'membre_id',
        'ancien_user_id',
        'nouveau_user_id',
        'delegation_id',
        'motif',
    ];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => abort(403, 'Trace de transfert immuable.'));
        static::deleting(fn () => abort(403, 'Trace de transfert immuable.'));
    }

    public function membre(): BelongsTo
    {
        return $this->belongsTo(MembreFamille::class, 'membre_id');
    }

    public function ancienProprietaire(): BelongsTo
    {
        return $this->belongsTo(User::class, 'ancien_user_id');
    }

    public function nouveauProprietaire(): BelongsTo
    {
        return $this->belongsTo(User::class, 'nouveau_user_id');
    }
}
