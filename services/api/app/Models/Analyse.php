<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Une entrée du catalogue national des analyses (CDC_09 §7.3).
 *
 * `code` et `pays_code` sont HORS `$fillable` : un client ne choisit pas un identifiant national,
 * il le reçoit ({@see App\Services\Analyse\AttributeurCodeAnalyse}).
 *
 * L'IDENTITÉ D'UNE ANALYSE INCLUT SON MILIEU. « Glycémie » n'est pas une analyse : glycémie à jeun
 * sur plasma veineux et glycémie capillaire sont deux entrées, avec des références différentes.
 */
class Analyse extends Model
{
    protected $table = 'analyses';

    protected $fillable = [
        'loinc',
        'libelle',
        'description',
        'categorie',
        'milieu_preleve',
        'unite',
        'methode',
        'conditions_prelevement',
        'conservation',
        'delai_rendu_heures',
        'actif',
    ];

    protected $appends = ['designation'];

    protected function casts(): array
    {
        return [
            'delai_rendu_heures' => 'integer',
            'actif'              => 'boolean',
        ];
    }

    /** « Hémoglobine (sang veineux) » — ce qui distingue deux entrées portant le même nom usuel. */
    public function getDesignationAttribute(): string
    {
        $milieu = \App\Support\Analyses::libelleMilieu($this->milieu_preleve);

        return $milieu === null ? $this->libelle : "{$this->libelle} ({$milieu})";
    }

    /** Les strates de référence, toutes populations confondues. */
    public function references(): HasMany
    {
        return $this->hasMany(AnalyseReference::class, 'analyse_id');
    }
}
