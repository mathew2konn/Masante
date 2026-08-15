<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * P6.8c — Une maladie du référentiel national (CDC_09 §8).
 *
 * `code` est VOLONTAIREMENT ABSENT de `$fillable` — précédent constant depuis P6.4a : un client ne
 * choisit pas un code national, il le reçoit. Seul {@see App\Services\Maladie\AttributeurCodeMaladie}
 * l'écrit. `code_cim10` / `code_cim11` en sont absents pour une autre raison : ce sont des codes de
 * l'OMS, et les laisser remplir par un formulaire reviendrait à laisser inventer une classification
 * internationale.
 *
 * PAS DE `pays_code` (décision propriétaire E2) : le paludisme est le paludisme partout. Ce qui est
 * national, c'est la SURVEILLANCE ({@see SurveillanceMaladie}), pas la maladie.
 */
class Maladie extends Model
{
    protected $table = 'maladies';

    protected $fillable = [
        'libelle',
        'description',
        'source',
        'source_detail',
        'actif',
    ];

    protected function casts(): array
    {
        return [
            'actif' => 'boolean',
        ];
    }

    /** Les libellés ALTERNATIFS : autres langues et synonymes de recherche — jamais l'officiel. */
    public function libelles(): HasMany
    {
        return $this->hasMany(LibelleMaladie::class, 'maladie_id');
    }

    /** Son statut de surveillance, pays par pays. */
    public function surveillances(): HasMany
    {
        return $this->hasMany(SurveillanceMaladie::class, 'maladie_id');
    }

    /** Les vaccins qui en protègent (P6.8b, promesse tenue). */
    public function vaccins(): BelongsToMany
    {
        return $this->belongsToMany(Vaccin::class, 'vaccin_maladies', 'maladie_id', 'vaccin_id')
            ->withTimestamps();
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('actif', true);
    }

    /**
     * La maladie active portant ce code national, ou `null`.
     *
     * SEUL point d'entrée par code du module — précédents `SpecialiteMedicale::parCode` et
     * `Vaccin::parCode` : si chaque contrôleur composait sa requête, l'un d'eux oublierait un jour
     * le filtre `actif` et laisserait rattacher une alerte à une entrée retirée.
     *
     * Aucun argument de pays, à la différence de ses aînés : le code est unique globalement (E2).
     */
    public static function parCode(string $code): ?self
    {
        return self::query()->active()->where('code', $code)->first();
    }

    /** Cette entrée vient-elle du jeu de démonstration ? Le témoin du remplacement (motif P6.7a). */
    public function estDeDemonstration(): bool
    {
        return $this->source === 'demonstration';
    }
}
