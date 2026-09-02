<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Paliers de commission par volume mensuel (facturation partenaire).
 *
 * Les paliers sont des DONNÉES : changer un taux ne demande pas un déploiement. Un barème ne se
 * modifie jamais en place — on ferme la ligne courante (`date_fin`) et on en insère une nouvelle,
 * sinon une commission passée cesserait d'être reproductible.
 *
 * `taux_bps` est en points de base ENTIERS : 250 = 2,50 %.
 *
 * AUCUNE SÉLECTION DE PALIER ICI. « Quel taux pour ce volume, à cette date ? » est une décision
 * métier : elle vit dans le service de commission, hors de ce lot. Ce modèle ne fait que porter la
 * donnée — et `commissions_transaction` conserve de toute façon le taux appliqué, précisément pour
 * n'avoir jamais à relire cette table pour un calcul passé.
 *
 * Aucune relation : un barème est national, il n'appartient à aucun établissement.
 */
class BaremeCommission extends Model
{
    protected $table = 'baremes_commission';

    protected $fillable = [
        'palier_ordre',
        'volume_mensuel_min',
        'volume_mensuel_max',
        'taux_bps',
        'date_effet',
        'date_fin',
    ];

    protected function casts(): array
    {
        return [
            'palier_ordre' => 'integer',
            'volume_mensuel_min' => 'integer',
            'volume_mensuel_max' => 'integer',
            'taux_bps' => 'integer',
            'date_effet' => 'date',
            'date_fin' => 'date',
        ];
    }
}
