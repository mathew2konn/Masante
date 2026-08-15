<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * P6.8b — Un vaccin du référentiel national (CDC_09 §8).
 *
 * L'ANTIGÈNE, pas la présentation commerciale : voir la migration pour la raison — le calendrier
 * national porte sur « Pentavalent », et deux lots de deux fabricants ne créent pas deux échéances
 * à six semaines.
 *
 * `code` et `pays_code` sont VOLONTAIREMENT ABSENTS de `$fillable` — précédent constant depuis
 * P6.4a : un client ne choisit pas l'identifiant national d'une entrée de référentiel, il le
 * reçoit. Seul {@see App\Services\Vaccin\AttributeurCodeVaccin} les écrit.
 */
class Vaccin extends Model
{
    protected $table = 'vaccins';

    protected $fillable = [
        'libelle',
        'abreviation',
        'maladies_evitees',
        'voie_administration',
        'nb_doses',
        'statut_marche',
        'description',
        'actif',
    ];

    protected function casts(): array
    {
        return [
            'actif'    => 'boolean',
            'nb_doses' => 'integer',
        ];
    }

    /** Les échéances du calendrier national pour ce vaccin, dans l'ordre des doses. */
    public function echeances(): HasMany
    {
        return $this->hasMany(EcheanceVaccinale::class, 'vaccin_id')->orderBy('numero_dose');
    }

    /** Les lignes de carnet rattachées à ce vaccin. */
    public function vaccinations(): HasMany
    {
        return $this->hasMany(Vaccination::class, 'vaccin_id');
    }

    /**
     * Les maladies dont ce vaccin protège (P6.8c — la promesse écrite dans la migration de P6.8b).
     *
     * `maladies_evitees` reste à côté et n'est pas supprimée (ADR-024) : elle porte des formulations
     * que le lien ne rend pas (« formes graves de… »).
     */
    public function maladies(): BelongsToMany
    {
        return $this->belongsToMany(Maladie::class, 'vaccin_maladies', 'vaccin_id', 'maladie_id')
            ->withTimestamps();
    }

    public function scopeActif(Builder $query): Builder
    {
        return $query->where('actif', true);
    }

    /**
     * Un vaccin RETIRÉ reste consultable et reste inscriptible au carnet.
     *
     * Refuser d'inscrire une dose réellement administrée parce que le produit a été retiré depuis
     * effacerait un fait médical (CDC_00 §4). Le retrait est SIGNALÉ, jamais bloquant — même
     * décision qu'en P6.6a pour les médicaments.
     */
    public function estRetire(): bool
    {
        return $this->statut_marche === 'retire';
    }

    /**
     * Le vaccin actif portant ce code national, ou `null`.
     *
     * Seul point d'entrée par code du module — précédent `SpecialiteMedicale::parCode` : si chaque
     * contrôleur composait sa requête, l'un d'eux oublierait un jour le filtre `actif`.
     */
    public static function parCode(string $code, ?string $pays = null): ?self
    {
        return self::query()
            ->actif()
            ->where('pays_code', $pays ?? config('referentiels.pays_defaut', 'CI'))
            ->where('code', $code)
            ->first();
    }
}
