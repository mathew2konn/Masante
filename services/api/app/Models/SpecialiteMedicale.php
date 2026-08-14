<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * P6.8a — Une spécialité médicale ou une activité de service reconnue nationalement (CDC_09 §8).
 *
 * `code` et `pays_code` sont VOLONTAIREMENT ABSENTS de `$fillable` — précédent constant depuis
 * P6.4a : un client ne choisit pas la clé d'un terme de nomenclature nationale. Ils sont posés par
 * le seeder et par le backfill, jamais par un formulaire.
 */
class SpecialiteMedicale extends Model
{
    protected $table = 'specialites_medicales';

    protected $fillable = [
        'libelle',
        'nature',
        'profession',
        'description',
        'ordre',
        'actif',
    ];

    protected function casts(): array
    {
        return [
            'actif' => 'boolean',
            'ordre' => 'integer',
        ];
    }

    /** Les services d'établissement rattachés à ce terme. */
    public function services(): HasMany
    {
        return $this->hasMany(ServiceEtablissement::class, 'specialite_id');
    }

    /** Les praticiens qui l'exercent. */
    public function praticiens(): HasMany
    {
        return $this->hasMany(Medecin::class, 'specialite_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('actif', true);
    }

    /** L'ordre d'affichage métier, puis le libellé — jamais l'identifiant technique. */
    public function scopeOrdonnee(Builder $query): Builder
    {
        return $query->orderBy('ordre')->orderBy('libelle');
    }

    /**
     * Le terme actif portant ce code, ou `null`.
     *
     * C'est le SEUL point d'entrée par code du module : les contrôleurs ne composent jamais la
     * requête eux-mêmes, sans quoi l'un d'eux oublierait un jour le filtre `actif` et laisserait
     * rattacher un service à une spécialité retirée.
     */
    public static function parCode(string $code, ?string $pays = null): ?self
    {
        return self::query()
            ->active()
            ->where('pays_code', $pays ?? config('referentiels.pays_defaut', 'CI'))
            ->where('code', $code)
            ->first();
    }
}
