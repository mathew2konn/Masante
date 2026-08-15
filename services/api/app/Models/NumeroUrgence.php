<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * P6.8e — Un numéro d'urgence national (CDC_09 §8).
 *
 * `code` et `pays_code` sont VOLONTAIREMENT ABSENTS de `$fillable` — précédent constant depuis
 * P6.4a : un client ne choisit pas la clé d'un terme de nomenclature nationale.
 *
 * `numero`, lui, EST `$fillable` : c'est la valeur que l'autorité fait évoluer, et c'est tout
 * l'objet de ce référentiel qu'elle puisse changer sans republier l'application.
 */
class NumeroUrgence extends Model
{
    protected $table = 'numeros_urgence';

    protected $fillable = [
        'numero',
        'libelle',
        'description',
        'ordre',
        'actif',
        'source',
        'source_detail',
    ];

    protected function casts(): array
    {
        return [
            'actif' => 'boolean',
            'ordre' => 'integer',
        ];
    }

    public function scopeActif(Builder $query): Builder
    {
        return $query->where('actif', true);
    }

    /**
     * L'ordre d'affichage métier, puis le code — jamais l'identifiant technique.
     *
     * Le second critère existe pour que l'ordre soit TOTAL : deux numéros au même rang se
     * présenteraient sinon dans l'ordre du moteur, et l'instantané publié divergerait d'une base à
     * l'autre sans qu'aucune donnée n'ait changé.
     */
    public function scopeOrdonne(Builder $query): Builder
    {
        return $query->orderBy('ordre')->orderBy('code');
    }
}
