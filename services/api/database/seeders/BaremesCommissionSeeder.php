<?php

namespace Database\Seeders;

use App\Models\BaremeCommission;
use Illuminate\Database\Seeder;

/**
 * Paliers de commission par volume mensuel (facturation partenaire).
 *
 * Idempotent (`updateOrCreate` sur `palier_ordre`, en vigueur — `date_fin` nulle). Un barème ne se
 * modifie jamais en place UNE FOIS PUBLIÉ (voir la migration) : ce seeder n'est prévu que pour
 * poser le barème initial, jamais pour corriger un taux déjà en vigueur — cela relève du service
 * de commission, qui fermera la ligne courante avant d'en ouvrir une nouvelle.
 */
class BaremesCommissionSeeder extends Seeder
{
    private const PALIERS = [
        ['palier_ordre' => 1, 'volume_mensuel_min' => 0, 'volume_mensuel_max' => 250000, 'taux_bps' => 250],
        ['palier_ordre' => 2, 'volume_mensuel_min' => 250001, 'volume_mensuel_max' => 1000000, 'taux_bps' => 200],
        ['palier_ordre' => 3, 'volume_mensuel_min' => 1000001, 'volume_mensuel_max' => 3000000, 'taux_bps' => 150],
        ['palier_ordre' => 4, 'volume_mensuel_min' => 3000001, 'volume_mensuel_max' => null, 'taux_bps' => 100],
    ];

    public function run(): void
    {
        foreach (self::PALIERS as $palier) {
            BaremeCommission::updateOrCreate(
                ['palier_ordre' => $palier['palier_ordre'], 'date_fin' => null],
                [
                    'volume_mensuel_min' => $palier['volume_mensuel_min'],
                    'volume_mensuel_max' => $palier['volume_mensuel_max'],
                    'taux_bps' => $palier['taux_bps'],
                    'date_effet' => now()->toDateString(),
                ]
            );
        }
    }
}
