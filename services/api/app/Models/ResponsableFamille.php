<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Responsable désigné d'une famille (incrément C) — qui peut valider une contribution.
 *
 * Le propriétaire d'un carnet est responsable de droit : aucune ligne ne l'exprime, et il ne peut
 * pas se la retirer. Cette table ne porte que les responsables SUPPLÉMENTAIRES qu'il désigne.
 *
 * Toutes les familles n'ont pas un père et une mère. La règle est « le propriétaire décide » —
 * il peut désigner quelqu'un, ou rester seul à décider.
 */
class ResponsableFamille extends Model
{
    protected $table = 'responsables_famille';

    protected $fillable = [
        'titulaire_user_id',
        'responsable_user_id',
        'designe_le',
        'revoque_le',
    ];

    protected function casts(): array
    {
        return [
            'designe_le' => 'datetime',
            'revoque_le' => 'datetime',
        ];
    }

    /** @param Builder<ResponsableFamille> $query */
    public function scopeActif(Builder $query): void
    {
        $query->whereNull('revoque_le');
    }

    public function titulaire(): BelongsTo
    {
        return $this->belongsTo(User::class, 'titulaire_user_id');
    }

    public function responsable(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsable_user_id');
    }

    /**
     * Les comptes habilités à décider pour ce propriétaire : lui-même, plus ses désignés.
     *
     * @return array<int, int>
     */
    public static function decideursPour(int $titulaireUserId): array
    {
        return array_values(array_unique(array_merge(
            [$titulaireUserId],
            static::query()
                ->where('titulaire_user_id', $titulaireUserId)
                ->actif()
                ->pluck('responsable_user_id')
                ->all(),
        )));
    }
}
