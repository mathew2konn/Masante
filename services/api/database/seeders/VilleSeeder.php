<?php

namespace Database\Seeders;

use App\Models\StructureSanitaire;
use App\Models\Ville;
use Illuminate\Database\Seeder;

/**
 * P6.4b — Les trois villes couvertes (décision propriétaire du 2026-08-13).
 *
 * `affiche_communes` est la traduction en donnée de la règle énoncée : « s'il est à Abidjan on
 * affichera les communes ; en dehors d'Abidjan on affichera uniquement les structures, vu qu'il
 * n'y a pas de communes ». Abidjan est le seul District Autonome subdivisé en communes dans le
 * catalogue ; Yamoussoukro et Bouaké sont servies telles quelles.
 *
 * LES RAYONS SONT DES ORDRES DE GRANDEUR, PAS DES LIMITES ADMINISTRATIVES. Ils couvrent
 * l'agglomération et ses abords, de façon à ce qu'un utilisateur en périphérie soit rattaché à la
 * ville qu'il fréquente. Ce ne sont pas les frontières officielles des communes ou des
 * départements — et c'est justement pour cela qu'ils sont en base : ils s'ajustent par un `UPDATE`,
 * au vu des retours d'usage, sans redéploiement.
 *
 * IDEMPOTENT : rejouer ne crée aucun doublon et ne réécrit aucun rattachement déjà posé.
 */
class VilleSeeder extends Seeder
{
    private const PAYS = 'CI';

    /**
     * Les villes, dans l'ordre d'affichage du sélecteur de repli.
     *
     * Coordonnées : centres des agglomérations. Rayons : Abidjan est de loin la plus étendue
     * (District Autonome), Yamoussoukro et Bouaké sont plus compactes.
     */
    private const VILLES = [
        [
            'code' => 'ABJ', 'nom' => 'Abidjan',
            'latitude' => 5.3600, 'longitude' => -4.0083,
            'rayon_km' => 35, 'affiche_communes' => true, 'ordre' => 1,
        ],
        [
            'code' => 'YAM', 'nom' => 'Yamoussoukro',
            'latitude' => 6.8276, 'longitude' => -5.2893,
            'rayon_km' => 25, 'affiche_communes' => false, 'ordre' => 2,
        ],
        [
            'code' => 'BKE', 'nom' => 'Bouaké',
            'latitude' => 7.6906, 'longitude' => -5.0300,
            'rayon_km' => 25, 'affiche_communes' => false, 'ordre' => 3,
        ],
    ];

    public function run(): void
    {
        foreach (self::VILLES as $definition) {
            Ville::firstOrCreate(
                ['pays_code' => self::PAYS, 'code' => $definition['code']],
                array_diff_key($definition, ['code' => null]),
            );
        }

        // Les 12 structures du catalogue sont toutes abidjanaises (seeder 3A.1). On les rattache,
        // sans jamais réécrire un rattachement déjà posé : un correctif manuel prime.
        $abidjan = Ville::where('pays_code', self::PAYS)->where('code', 'ABJ')->first();

        $rattachees = StructureSanitaire::query()
            ->whereNull('ville_id')
            ->whereIn('commune', ['Abobo', 'Adjamé', 'Attécoubé', 'Cocody', 'Marcory',
                'Plateau', 'Treichville', 'Yopougon', 'Koumassi', 'Port-Bouët', 'Bingerville'])
            ->update(['ville_id' => $abidjan->id]);

        $orphelines = StructureSanitaire::whereNull('ville_id')->count();

        $this->command?->info(sprintf(
            '%d ville(s) couverte(s) ; %d structure(s) rattachée(s) à Abidjan.',
            count(self::VILLES),
            $rattachees,
        ));

        if ($orphelines > 0) {
            // Dit plutôt que masqué : une structure sans ville reste enregistrable et visible,
            // le référentiel ne lui invente pas une appartenance.
            $this->command?->warn("{$orphelines} structure(s) sans ville — commune hors des villes couvertes.");
        }
    }
}
