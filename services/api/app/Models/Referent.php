<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Module 5 / 5.6 — Désignation d'un médecin référent sur un membre (voie 2, Sécurité §4.4).
 *
 * Cycle de vie identique à celui d'une délégation (voie 3) : désignée par le titulaire, active
 * jusqu'à révocation, jamais supprimée (l'historique est la trace exigée par la loi n°2013-450).
 * Aucun champ `fillable` : les FK et les horodatages sont posés par {@see App\Services\ReferentService}.
 */
class Referent extends Model
{
    protected $table = 'referents';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'designe_at'  => 'datetime',
            'revoquee_at' => 'datetime',
        ];
    }

    /** Désignations encore actives (non révoquées). */
    public function scopeActif(Builder $query): Builder
    {
        return $query->whereNull('revoquee_at');
    }

    public function membre(): BelongsTo
    {
        return $this->belongsTo(MembreFamille::class, 'membre_id');
    }

    /** Fiche de l'annuaire public désignée par le patient (Module 3 / F3.5). */
    public function medecin(): BelongsTo
    {
        return $this->belongsTo(Medecin::class, 'medecin_id');
    }

    /** Titulaire du carnet qui a désigné (jamais le membre : il n'a pas de compte). */
    public function designePar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'designe_par_user_id');
    }

    public function estActif(): bool
    {
        return $this->revoquee_at === null;
    }
}
