<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Module 5 / 5.7 — Inscription d'un membre comme donneur volontaire (CdC FN6).
 *
 * `membre_id` et `inscrit_at` sont posés par {@see App\Services\DonSangService} : on ne s'inscrit
 * pas donneur par mass-assignment.
 */
class DonneurSang extends Model
{
    protected $table = 'donneurs_sang';

    protected $fillable = ['disponible', 'dernier_don_at'];

    protected function casts(): array
    {
        return [
            'inscrit_at'     => 'datetime',
            'dernier_don_at' => 'date',
            'disponible'     => 'boolean',
        ];
    }

    /** Donneurs ayant encore leur consentement actif. */
    public function scopeDisponible(Builder $query): Builder
    {
        return $query->where('disponible', true);
    }

    public function membre(): BelongsTo
    {
        return $this->belongsTo(MembreFamille::class, 'membre_id');
    }
}
