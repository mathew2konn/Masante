<?php

namespace App\Support;

/**
 * Les six familles de tiers payant du §8.2 du CDC_06 — SOURCE UNIQUE (P6.8d).
 *
 * ═══ ADOPTÉES, JAMAIS INVENTÉES ═══
 *
 * Ces six valeurs sont la transcription exacte de `TypePriseEnCharge`, l'énumération du service de
 * paiement Java qui pilote déjà le calcul de prise en charge (CDC_06 §8). **Il y avait un vocabulaire
 * à adopter, pas à inventer** — précédent P6.8a, où `orl` et `cardiologie` ont été promus plutôt que
 * réinventés. En forger d'autres ici ferait diverger les deux moitiés de la plateforme sur la nature
 * d'un tiers payant, alors même que l'une facture ce que l'autre référence.
 *
 * ═══ POURQUOI CETTE CLASSE EXISTE ═══
 *
 * Quatrième récidive évitée du constat G-a de P6.4b : les communes d'Abidjan, les libellés de
 * catégorie d'établissement, puis les libellés de statut vaccinal vivaient EN DUR côté mobile et
 * avaient divergé de la base. Les libellés sont donc **servis par l'API** (`GET /v1/assurances`), et
 * le mobile les consomme au lieu de les recopier.
 */
final class TypesOrganismeAssurance
{
    /**
     * Type => libellé citoyen. L'ordre est celui de l'affichage : le régime national d'abord, parce
     * que c'est celui que la plupart des assurés cherchent.
     *
     * @var array<string, string>
     */
    public const TYPES = [
        'cnam'                     => 'Régime national (CNAM / CMU)',
        'assurance'                => 'Assurance privée',
        'mutuelle'                 => 'Mutuelle',
        'entreprise'               => 'Couverture d\'entreprise',
        'ong'                      => 'ONG ou organisme international',
        'programme_gouvernemental' => 'Programme gouvernemental',
    ];

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
        // Repli sur le code brut plutôt que « undefined » : si une valeur inconnue traverse un jour,
        // l'écran affichera quelque chose de lisible plutôt qu'un trou.
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
