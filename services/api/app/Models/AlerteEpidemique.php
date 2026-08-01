<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Alerte épidémique locale (CdC FN3, Module 5.4).
 *
 * Publiée par l'admin IVOIRSANTÉ. Le mobile ne voit que les alertes ACTIVES (drapeau + fenêtre de
 * dates) de la commune de l'utilisateur, plus les alertes nationales.
 */
class AlerteEpidemique extends Model
{
    // Laravel ne pluralise que le dernier mot (`alerte_epidemiques`) : on fixe le nom réel.
    protected $table = 'alertes_epidemiques';

    /** Valeur de `commune` désignant une alerte nationale (CdC §8). */
    public const NATIONALE = 'toutes_communes';

    protected $fillable = [
        'commune',
        'titre',
        'description',
        'maladie',
        'niveau_alerte',
        'source',
        'date_debut',
        'date_fin',
        'actif',
        'publie_par_user_id',
    ];

    protected function casts(): array
    {
        return [
            'date_debut' => 'date',
            'date_fin'   => 'date',
            'actif'      => 'boolean',
        ];
    }

    public function publiePar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'publie_par_user_id');
    }

    /**
     * Alertes réellement en vigueur : drapeau `actif`, date de début passée, et date de fin
     * absente ou future. Le drapeau ne suffit pas — une alerte peut être `actif` mais dater du
     * mois prochain, ou avoir expiré sans qu'on l'ait désactivée.
     */
    public function scopeEnVigueur(Builder $query): Builder
    {
        $aujourdhui = Carbon::today();

        return $query->where('actif', true)
            ->whereDate('date_debut', '<=', $aujourdhui)
            ->where(fn (Builder $q) => $q->whereNull('date_fin')->orWhereDate('date_fin', '>=', $aujourdhui));
    }

    /**
     * Alertes visibles pour une commune donnée : celles de cette commune + les alertes nationales.
     * Une commune vide (compte sans commune renseignée) ne voit que les alertes nationales.
     */
    public function scopePourCommune(Builder $query, ?string $commune): Builder
    {
        return $query->where(function (Builder $q) use ($commune) {
            $q->where('commune', self::NATIONALE);

            if ($commune !== null && $commune !== '') {
                $q->orWhere('commune', $commune);
            }
        });
    }
}
