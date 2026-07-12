<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Référentiel des 8 contacts prénatals OMS/PSN-CI (FN4) — contenu seedé, modifiable en base
 * sans redéploiement (même principe que la table `symptomes` du triage, F1.3).
 * Lecture seule côté API : aucun endpoint d'écriture.
 */
class EtapePrenatale extends Model
{
    protected $table = 'etapes_prenatales';

    protected $fillable = [
        'numero',
        'semaine_recommandee',
        'libelle',
        'description',
        'conseils_nutrition',
    ];
}
