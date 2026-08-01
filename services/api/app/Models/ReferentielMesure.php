<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Module 5 / 5.6 — Seuils d'un type de mesure (FN5). Référentiel médical en base (pattern F1.3) :
 * modifiable par un UPDATE, sans redéploiement. Contenu public non sensible (aucun chiffrement).
 */
class ReferentielMesure extends Model
{
    protected $table = 'referentiels_mesure';

    protected $fillable = [
        'type_mesure',
        'libelle',
        'unite',
        'valeur_min',
        'valeur_max',
        'normal_min',
        'normal_max',
        'critique_bas',
        'critique_haut',
        'decimales',
        'ordre',
        'conseil_anormal',
    ];

    protected function casts(): array
    {
        return [
            'valeur_min'    => 'float',
            'valeur_max'    => 'float',
            'normal_min'    => 'float',
            'normal_max'    => 'float',
            'critique_bas'  => 'float',
            'critique_haut' => 'float',
            'decimales'     => 'integer',
            'ordre'         => 'integer',
        ];
    }

    /**
     * Situe une valeur par rapport aux seuils : `critique` (urgence), `bas`, `eleve` ou `normal`.
     *
     * Le critique l'emporte sur tout : une valeur sous `critique_bas` n'est pas seulement « basse »,
     * elle appelle un avis médical immédiat. Un seuil critique NULL signifie qu'il n'y en a pas de
     * ce côté (une saturation ne peut pas être « critiquement haute »).
     */
    public function statutPour(float $valeur): string
    {
        if ($this->critique_bas !== null && $valeur <= $this->critique_bas) {
            return 'critique';
        }

        if ($this->critique_haut !== null && $valeur >= $this->critique_haut) {
            return 'critique';
        }

        if ($valeur < $this->normal_min) {
            return 'bas';
        }

        if ($valeur > $this->normal_max) {
            return 'eleve';
        }

        return 'normal';
    }
}
