<?php

namespace App\Support;

/**
 * Les professions de santé (CDC_09 §5.1) — SOURCE UNIQUE.
 *
 * ═══ POURQUOI CETTE CLASSE NAÎT AVEC LA COLONNE ═══
 *
 * `TypesEtablissement` a dû être créée en RATTRAPAGE (P6.4b, constat G-a) : la liste des catégories
 * avait déjà divergé en quatre endroits, dont un qui répondait 422 sur des valeurs pourtant
 * acceptées par la base, et un autre qui aurait affiché « undefined » côté mobile. Le défaut
 * n'était pas la duplication : c'est qu'elle était devenue invisible.
 *
 * L'énumération `profession` est neuve. On ne recommence pas : la liste naît ici, la migration en
 * dérive, l'API l'expose, et aucun client ne la recopie.
 *
 * ═══ CE QUE `profession` N'EST PAS ═══
 *
 * Ce n'est pas `specialite`, qui reste un libellé libre jusqu'à l'étape 8 du corpus (décision
 * propriétaire P4 du plan G1). Un radiologue et un cardiologue exercent la même PROFESSION —
 * médecin spécialiste — et deux spécialités différentes. Fondre les deux rendrait insoluble la
 * question du §4.4 : « combien de sages-femmes dans ce district ? ».
 */
final class ProfessionsSante
{
    /**
     * Profession => libellé citoyen. L'ordre est celui du §5.1, qui va du médecin aux professions
     * paramédicales — c'est aussi l'ordre d'affichage des filtres.
     *
     * @var array<string, string>
     */
    public const PROFESSIONS = [
        'medecin_generaliste' => 'Médecin généraliste',
        'medecin_specialiste' => 'Médecin spécialiste',
        'chirurgien'          => 'Chirurgien',
        'dentiste'            => 'Dentiste',
        'sage_femme'          => 'Sage-femme',
        'infirmier'           => 'Infirmier',
        'pharmacien'          => 'Pharmacien',
        'biologiste'          => 'Biologiste',
        'radiologue'          => 'Radiologue',
        'psychologue'         => 'Psychologue',
        'kinesitherapeute'    => 'Kinésithérapeute',
    ];

    /**
     * Les états d'une autorisation d'exercer (§5.2, contrôlés par le §5.4).
     *
     * `suspendue` et `retiree` sont distinctes, et la distinction n'est pas décorative : une
     * suspension est temporaire, un retrait est définitif. Les deux interdisent de signer, mais
     * confondre les deux ferait perdre l'information au moment où un ordre professionnel lèverait
     * une suspension.
     *
     * @var array<string, string>
     */
    public const STATUTS_AUTORISATION = [
        'valide'    => "Autorisation d'exercer valide",
        'suspendue' => 'Autorisation suspendue',
        'retiree'   => 'Autorisation retirée',
    ];

    /**
     * Professions habilitées à prescrire.
     *
     * ATTENTION À CE QUE CETTE CONSTANTE EST, ET N'EST PAS. Ce n'est pas une règle médicale — le
     * corpus interdit d'en écrire une (CDC_00 §4) — mais la transcription d'un fait
     * ADMINISTRATIF : un kinésithérapeute n'est pas prescripteur. Elle ne sert donc jamais à
     * décider d'un soin, seulement à savoir qui peut signer une ordonnance (P6.5b).
     *
     * Elle est ici plutôt que dans le service qui l'utilisera, pour la même raison que le reste de
     * cette classe : ce sont des DONNÉES, et elles n'ont qu'un domicile.
     *
     * @var array<int, string>
     */
    public const PRESCRIPTEURS = [
        'medecin_generaliste', 'medecin_specialiste', 'chirurgien', 'dentiste', 'sage_femme',
    ];

    /** @return array<int, string> */
    public static function codes(): array
    {
        return array_keys(self::PROFESSIONS);
    }

    /** La règle de validation `in:` — plus jamais recopiée à la main. */
    public static function regleIn(): string
    {
        return 'in:'.implode(',', self::codes());
    }

    public static function libelle(string $code): string
    {
        // Repli sur le code brut plutôt qu'un trou à l'écran, comme `TypesEtablissement`.
        return self::PROFESSIONS[$code] ?? $code;
    }

    public static function peutPrescrire(?string $profession): bool
    {
        return $profession !== null && in_array($profession, self::PRESCRIPTEURS, true);
    }

    /** @return array<int, array{code: string, libelle: string}> */
    public static function pourApi(): array
    {
        return array_map(
            static fn (string $code): array => ['code' => $code, 'libelle' => self::PROFESSIONS[$code]],
            self::codes(),
        );
    }
}
