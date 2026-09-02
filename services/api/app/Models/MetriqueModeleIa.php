<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * P10c-3-i (F17) — Une métrique d'une version de modèle IA, clé/valeur (CDC_05 §8), nom ADOPTÉ du
 * §123 (`metriques_modeles`). Table dédiée plutôt que des colonnes larges sur `versions_modeles` :
 * une métrique suivie de plus (demain, une évaluation d'équité) n'est jamais une migration.
 */
class MetriqueModeleIa extends Model
{
    protected $table = 'metriques_modeles';

    public $timestamps = false;

    protected $fillable = [
        'version_id',
        'cle',
        'valeur',
        'mesure_le',
    ];

    protected function casts(): array
    {
        return [
            'valeur' => 'float',
            'mesure_le' => 'datetime',
        ];
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(VersionModeleIa::class, 'version_id');
    }
}
