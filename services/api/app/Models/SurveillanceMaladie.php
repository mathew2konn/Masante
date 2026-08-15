<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * P6.8c — Le statut de surveillance d'une maladie DANS UN PAYS (CDC_09 §8).
 *
 * ═══ POURQUOI CETTE TABLE EXISTE ═══
 *
 * Parce que la maladie, elle, n'appartient à aucun pays (décision propriétaire E2). Le paludisme est
 * le paludisme partout ; ce qui change d'un pays à l'autre, c'est **ce qu'on surveille et ce qu'on
 * doit déclarer**. Porter ces deux faits sur la ligne `maladies` aurait rendu le référentiel
 * mono-pays en silence.
 *
 * `declaration_obligatoire` et `surveillance_prioritaire` sont DEUX faits distincts : une maladie
 * peut être suivie de près sans être à déclaration obligatoire, et l'inverse existe aussi. Les
 * confondre en une seule colonne aurait fait dire au référentiel quelque chose de faux.
 */
class SurveillanceMaladie extends Model
{
    protected $table = 'maladie_surveillance';

    protected $fillable = [
        'pays_code',
        'declaration_obligatoire',
        'surveillance_prioritaire',
        'source',
        'source_detail',
    ];

    protected function casts(): array
    {
        return [
            'declaration_obligatoire'  => 'boolean',
            'surveillance_prioritaire' => 'boolean',
        ];
    }

    public function maladie(): BelongsTo
    {
        return $this->belongsTo(Maladie::class, 'maladie_id');
    }

    public function scopePays(Builder $query, ?string $pays = null): Builder
    {
        return $query->where('pays_code', strtoupper($pays ?? config('referentiels.pays_defaut', 'CI')));
    }
}
