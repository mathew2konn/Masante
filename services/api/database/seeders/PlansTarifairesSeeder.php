<?php

namespace Database\Seeders;

use App\Models\PlanTarifaire;
use Illuminate\Database\Seeder;

/**
 * Catalogue des plans tarifaires partenaires (facturation partenaire).
 *
 * Idempotent (`updateOrCreate` sur le couple `code` + `categorie_structure`) : un plan « Gestion »
 * existe en QUATRE lignes distinctes, une par catégorie d'établissement, toutes sous le même code
 * `P1_GESTION`. Cloner sur `code` seul aurait écrasé les trois autres montants à chaque rejeu.
 *
 * `P0_VISIBILITE` n'a pas de catégorie (`categorie_structure: null`) : il s'applique à toutes.
 */
class PlansTarifairesSeeder extends Seeder
{
    private const PLANS = [
        ['code' => 'P0_VISIBILITE', 'libelle' => 'Visibilité', 'categorie_structure' => null, 'montant_mensuel' => 0],
        ['code' => 'P1_GESTION', 'libelle' => 'Gestion — cabinet / centre de santé', 'categorie_structure' => 'CABINET', 'montant_mensuel' => 15000],
        ['code' => 'P1_GESTION', 'libelle' => 'Gestion — clinique / laboratoire', 'categorie_structure' => 'CLINIQUE', 'montant_mensuel' => 30000],
        ['code' => 'P1_GESTION', 'libelle' => 'Gestion — hôpital / polyclinique', 'categorie_structure' => 'HOPITAL', 'montant_mensuel' => 50000],
        ['code' => 'P1_GESTION', 'libelle' => 'Gestion — pharmacie', 'categorie_structure' => 'PHARMACIE', 'montant_mensuel' => 15000],
    ];

    public function run(): void
    {
        foreach (self::PLANS as $plan) {
            PlanTarifaire::updateOrCreate(
                ['code' => $plan['code'], 'categorie_structure' => $plan['categorie_structure']],
                [
                    'libelle' => $plan['libelle'],
                    'montant_mensuel' => $plan['montant_mensuel'],
                    'devise' => 'XOF',
                    'commission_incluse' => false,
                    'actif' => true,
                    'date_effet' => now()->toDateString(),
                ]
            );
        }
    }
}
