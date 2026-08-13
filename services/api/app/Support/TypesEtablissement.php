<?php

namespace App\Support;

/**
 * Les catégories d'établissement (CDC_09 §4.1) — SOURCE UNIQUE.
 *
 * ═══ POURQUOI CETTE CLASSE EXISTE ═══
 *
 * La liste vivait en QUATRE endroits, et elle avait déjà commencé à diverger :
 *
 *   1. l'énumération `type` de la migration — 13 valeurs depuis P6.4a ;
 *   2. `Portail\EtablissementController::TYPES` — 7 valeurs, avec les libellés du formulaire ;
 *   3. la règle `in:` de `StructureController::index` — 7 valeurs, qui **refusait en 422** tout
 *      filtre sur les six catégories ajoutées par P6.4a ;
 *   4. `LIBELLE_TYPE` dans `apps/mobile/src/types/structure.ts` — 7 valeurs, qui aurait affiché
 *      **« undefined »** devant toute structure d'une catégorie neuve.
 *
 * C'est exactement le défaut constaté au G0 de P7-D2 : « les libellés vivaient en dur côté mobile
 * et avaient divergé de la base ». Il se reproduisait ici, et P6.4a venait de l'amorcer en
 * élargissant l'énumération sans toucher aux trois copies.
 *
 * Une seule liste, donc, exposée par l'API pour que le mobile la consomme au lieu de la recopier.
 * Ajouter une catégorie demain = **une ligne ici et une valeur dans une migration**, plus aucune
 * chasse aux copies.
 */
final class TypesEtablissement
{
    /**
     * Catégorie => libellé citoyen. L'ordre est celui de l'affichage des filtres : du plus
     * structurant (CHU) au plus spécialisé.
     *
     * @var array<string, string>
     */
    public const TYPES = [
        // Les sept catégories historiques — valeurs inchangées depuis le Module 3.
        'chu'                 => 'CHU',
        'chr'                 => 'CHR',
        'hopital_general'     => 'Hôpital général',
        'clinique_privee'     => 'Clinique',
        'centre_sante'        => 'Centre de santé',
        'centre_sante_urbain' => 'Centre de santé urbain',
        'centre_sante_rural'  => 'Centre de santé rural',
        'cabinet'             => 'Cabinet',
        'pharmacie'           => 'Pharmacie',
        'laboratoire'         => 'Laboratoire',
        // Nommées explicitement par le §4.1, absentes jusqu'à P6.4a.
        'centre_imagerie'     => "Centre d'imagerie",
        'centre_dialyse'      => 'Centre de dialyse',
        'centre_vaccination'  => 'Centre de vaccination',
    ];

    /** Catégories pour lesquelles l'absence de niveau de soins est une anomalie (§4.2). */
    public const HOSPITALIERES = ['chu', 'chr', 'hopital_general'];

    /** @return array<int, string> */
    public static function codes(): array
    {
        return array_keys(self::TYPES);
    }

    /** La règle de validation `in:` — plus jamais recopiée à la main. */
    public static function regleIn(): string
    {
        return 'in:'.implode(',', self::codes());
    }

    public static function libelle(string $code): string
    {
        // Repli sur le code brut plutôt que « undefined » : si une valeur inconnue traverse un
        // jour, l'écran affichera quelque chose de lisible plutôt qu'un trou.
        return self::TYPES[$code] ?? $code;
    }

    /** @return array<int, array{code: string, libelle: string}> */
    public static function pourApi(): array
    {
        return array_map(
            static fn (string $code): array => ['code' => $code, 'libelle' => self::TYPES[$code]],
            self::codes(),
        );
    }
}
