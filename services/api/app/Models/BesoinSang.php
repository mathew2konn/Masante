<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Module 5 / 5.7 — Besoin en sang publié par un établissement (CdC FN6).
 *
 * `structure_id` et `publie_par_user_id` ne sont pas `fillable` : ils viennent du compte connecté,
 * jamais du formulaire — un gestionnaire ne publie que pour SON établissement.
 */
class BesoinSang extends Model
{
    protected $table = 'besoins_sang';

    protected $fillable = [
        'groupe_sanguin',
        'niveau',
        'message',
        'date_debut',
        'date_fin',
        'actif',
    ];

    protected $attributes = ['actif' => true];

    protected function casts(): array
    {
        return [
            'date_debut' => 'date',
            'date_fin'   => 'date',
            'actif'      => 'boolean',
        ];
    }

    /**
     * Besoins réellement en cours : actifs ET dans leur fenêtre de dates. Un besoin dont la date de
     * fin est passée ne doit plus mobiliser personne, même si personne n'a pensé à le désactiver.
     */
    public function scopeEnCours(Builder $query): Builder
    {
        return $query->where('actif', true)
            ->whereDate('date_debut', '<=', now())
            ->where(fn ($q) => $q->whereNull('date_fin')->orWhereDate('date_fin', '>=', now()));
    }

    public function structure(): BelongsTo
    {
        return $this->belongsTo(StructureSanitaire::class, 'structure_id');
    }

    public function publiePar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'publie_par_user_id');
    }
}
