<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Région sanitaire (CDC_09 §4.2, §8) — donnée de référence, jamais un texte libre.
 *
 * §1.2.4 : « aucune donnée de référence saisie librement dans un module métier ». Une région
 * saisie à la main deviendrait « Abidjan », « ABIDJAN » et « Abidjan 1 » en trois semaines, et
 * aucune statistique nationale ne serait plus possible — or c'est précisément l'usage que §4.4
 * assigne à ce référentiel (planification sanitaire, cartographie de l'offre de soins).
 */
class Region extends Model
{
    protected $table = 'regions';

    protected $fillable = ['pays_code', 'code', 'nom'];

    public function districts(): HasMany
    {
        return $this->hasMany(DistrictSanitaire::class, 'region_id');
    }

    public function etablissements(): HasMany
    {
        return $this->hasMany(StructureSanitaire::class, 'region_id');
    }
}
