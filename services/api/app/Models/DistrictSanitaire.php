<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * District sanitaire (CDC_09 §4.2) — l'échelon que le CDC exige nommément pour un établissement.
 *
 * C'est la maille de la planification sanitaire ivoirienne : un district couvre une population et
 * un ensemble d'établissements, et c'est à ce niveau que se lisent les couvertures de soins. Le
 * rattachement à la région passe par la FK, jamais par une convention de nommage.
 */
class DistrictSanitaire extends Model
{
    protected $table = 'districts_sanitaires';

    protected $fillable = ['region_id', 'pays_code', 'code', 'nom'];

    public function region(): BelongsTo
    {
        return $this->belongsTo(Region::class, 'region_id');
    }

    public function etablissements(): HasMany
    {
        return $this->hasMany(StructureSanitaire::class, 'district_id');
    }
}
