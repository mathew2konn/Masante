<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * P6.8b — Une échéance du calendrier vaccinal national : un vaccin, une dose, un âge (CDC_09 §8).
 *
 * C'est la ligne qui porte les seuils, et c'est délibéré : l'âge dû, le délai de grâce et la borne
 * de rattrapage sont des DONNÉES publiées sous gouvernance §10, jamais des nombres dans le code
 * (CDC_04 §20). {@see App\Support\ReglesCalendrierVaccinal} les applique sans en connaître aucun.
 *
 * `source` est NON NULLE en base : une échéance vaccinale sans provenance est une rumeur, et le
 * contrôle qualité refuse de publier un calendrier qui en contient (motif `analyse_references`,
 * P6.7a).
 */
class EcheanceVaccinale extends Model
{
    protected $table = 'calendrier_vaccinal';

    protected $fillable = [
        'numero_dose',
        'age_jours_du',
        'tolerance_jours',
        'age_jours_limite',
        'obligatoire',
        'libelle_echeance',
        'source',
        'source_detail',
    ];

    protected function casts(): array
    {
        return [
            'numero_dose'      => 'integer',
            'age_jours_du'     => 'integer',
            'tolerance_jours'  => 'integer',
            'age_jours_limite' => 'integer',
            'obligatoire'      => 'boolean',
        ];
    }

    public function vaccin(): BelongsTo
    {
        return $this->belongsTo(Vaccin::class, 'vaccin_id');
    }

    /**
     * Cette échéance vient-elle du jeu de démonstration ?
     *
     * Exposé pour que l'écran puisse en afficher le COMPTE EXACT — le témoin visible du remplacement
     * par un calendrier officiel (motif P6.7a). Une donnée de démonstration qui ne se signale pas
     * finit par être prise pour une donnée de référence.
     */
    public function estDeDemonstration(): bool
    {
        return $this->source === 'demonstration';
    }
}
