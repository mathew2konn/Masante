<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Module 5 / 5.8 — Un relevé de prix / de disponibilité (CdC §8, FN7 + FN8).
 *
 * Un relevé est un FAIT DATÉ : « le 14/07, dans cette pharmacie, ce médicament coûtait 500 F »
 * (ou « était en rupture »). On n'écrase donc jamais un relevé — on en ajoute un nouveau, et
 * l'affichage ne retient que les récents. C'est ce qui permet de dire au patient depuis QUAND on
 * sait, plutôt que de lui servir un prix d'âge inconnu.
 */
class PrixPharmacie extends Model
{
    protected $table = 'prix_pharmacie';

    protected $fillable = [
        'prix_cfa',
        'disponible',
        'source',
        'date_mise_a_jour',
    ];

    protected function casts(): array
    {
        return [
            'prix_cfa'         => 'integer',
            'disponible'       => 'boolean',
            'date_mise_a_jour' => 'datetime',
        ];
    }

    /** Relevés encore dignes de foi (fenêtre de fraîcheur, cf. config `masante.prix.fraicheur_jours`). */
    public function scopeRecent(Builder $query): Builder
    {
        return $query->where('date_mise_a_jour', '>=', now()->subDays((int) config('masante.prix.fraicheur_jours')));
    }

    public function medicament(): BelongsTo
    {
        return $this->belongsTo(Medicament::class, 'medicament_id');
    }

    /** La pharmacie (structure de type `pharmacie`, annuaire du Module 3). */
    public function structure(): BelongsTo
    {
        return $this->belongsTo(StructureSanitaire::class, 'structure_id');
    }

    public function signalePar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'signale_par_user_id');
    }
}
