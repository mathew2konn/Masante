<?php

namespace App\Models;

use App\Support\StatutVersionModeleIa;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * P10c-3-i (F17) — Le registre de gouvernance d'un modèle IA (CDC_05 §8), nom ADOPTÉ du §123.
 *
 * `statut` : voir {@see StatutVersionModeleIa}. `actif`/`archive` existent dans l'ENUM
 * mais sont inatteignables dans cet incrément (P10c-3-ii) — rien n'est branché sur le flux vivant.
 */
class VersionModeleIa extends Model
{
    protected $table = 'versions_modeles';

    public $timestamps = false;

    protected $fillable = [
        'pays_code',
        'numero_version',
        'export_id',
        'statut',
        'mlflow_run_id',
        'entraine_par',
        'valide_par',
        'date_validation_clinique',
        // P10c-3-ii (F24) — l'activation est un acte distinct de la validation clinique : c'est
        // elle qui fait qu'un modèle répond à de vrais triages.
        'activee_par',
        'activee_le',
        'cree_le',
    ];

    protected function casts(): array
    {
        return [
            'date_validation_clinique' => 'datetime',
            'activee_le' => 'datetime',
            'cree_le' => 'datetime',
        ];
    }

    public function export(): BelongsTo
    {
        return $this->belongsTo(ExportJeuEntrainement::class, 'export_id');
    }

    public function metriques(): HasMany
    {
        return $this->hasMany(MetriqueModeleIa::class, 'version_id');
    }
}
