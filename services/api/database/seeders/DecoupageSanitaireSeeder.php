<?php

namespace Database\Seeders;

use App\Models\DistrictSanitaire;
use App\Models\Region;
use App\Models\StructureSanitaire;
use Illuminate\Database\Seeder;

/**
 * P6.4 — Découpage sanitaire (CDC_09 §4.2, §8).
 *
 * ╔══════════════════════════════════════════════════════════════════════════════════════════╗
 * ║  JEU PARTIEL DE DÉMONSTRATION — CE N'EST PAS LE DÉCOUPAGE SANITAIRE NATIONAL.            ║
 * ╚══════════════════════════════════════════════════════════════════════════════════════════╝
 *
 * La Côte d'Ivoire compte **33 régions sanitaires et 113 districts sanitaires**. Ce seeder n'en
 * pose qu'une poignée : ceux qui couvrent les 12 structures seedées, toutes abidjanaises.
 *
 * CE QU'IL NE FAIT DÉLIBÉRÉMENT PAS. Abidjan est réellement couverte par **deux** régions
 * sanitaires (« Abidjan 1 – Grands Ponts » et « Abidjan 2 »), et la répartition exacte des
 * districts entre elles relève d'un arrêté du ministère. **Faute de disposer de cette source, ce
 * seeder n'essaie pas de la reproduire** : il pose une région unique « Abidjan » plutôt qu'une
 * répartition vraisemblable mais devinée. Une liste inventée qui a l'air juste est plus dangereuse
 * qu'une liste manifestement incomplète — elle ne se fait pas corriger.
 *
 * REMPLACEMENT SANS CODE. Charger le découpage officiel est un chargement de DONNÉES : deux
 * tables, aucune ligne de code à modifier. C'est exactement le principe §1.2.5 (« ajouter un pays
 * n'implique aucune modification de code »), appliqué ici à l'intérieur d'un pays.
 *
 * IDEMPOTENT : rejouer ne crée aucun doublon et ne réécrit aucun rattachement déjà posé à la main.
 */
class DecoupageSanitaireSeeder extends Seeder
{
    private const PAYS = 'CI';

    /** Districts posés, et les communes qu'ils couvrent parmi les structures seedées. */
    private const DISTRICTS = [
        'ABJ-CB' => ['nom' => 'Cocody-Bingerville', 'communes' => ['Cocody']],
        'ABJ-TM' => ['nom' => 'Treichville-Marcory', 'communes' => ['Treichville', 'Marcory']],
        'ABJ-YO' => ['nom' => 'Yopougon', 'communes' => ['Yopougon']],
        'ABJ-AB' => ['nom' => 'Abobo', 'communes' => ['Abobo']],
        'ABJ-APA' => ['nom' => 'Adjamé-Plateau-Attécoubé', 'communes' => ['Adjamé', 'Plateau', 'Attécoubé']],
    ];

    public function run(): void
    {
        $region = Region::firstOrCreate(
            ['pays_code' => self::PAYS, 'code' => 'ABJ'],
            ['nom' => 'Abidjan'],
        );

        $parCommune = [];

        foreach (self::DISTRICTS as $code => $definition) {
            $district = DistrictSanitaire::firstOrCreate(
                ['pays_code' => self::PAYS, 'code' => $code],
                ['region_id' => $region->id, 'nom' => $definition['nom']],
            );

            foreach ($definition['communes'] as $commune) {
                $parCommune[$commune] = $district->id;
            }
        }

        // Rattachement des structures existantes, par commune. `whereNull` : on ne réécrit
        // jamais un rattachement déjà posé — un correctif manuel du ministère primerait sur
        // cette table de correspondance approximative.
        $rattachees = 0;

        foreach ($parCommune as $commune => $districtId) {
            $rattachees += StructureSanitaire::query()
                ->where('commune', $commune)
                ->whereNull('district_id')
                ->update(['district_id' => $districtId, 'region_id' => $region->id]);
        }

        $orphelines = StructureSanitaire::whereNull('district_id')->count();

        $this->command?->info(sprintf(
            'Découpage sanitaire (JEU PARTIEL) : 1 région, %d districts, %d structure(s) rattachée(s).',
            count(self::DISTRICTS),
            $rattachees,
        ));

        if ($orphelines > 0) {
            // Dit plutôt que masqué : une structure sans district sera signalée par les contrôles
            // qualité du référentiel, et c'est le comportement voulu.
            $this->command?->warn(
                "{$orphelines} structure(s) sans district — commune hors du jeu partiel."
            );
        }
    }
}
