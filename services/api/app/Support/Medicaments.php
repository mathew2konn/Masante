<?php

namespace App\Support;

/**
 * Les énumérations du référentiel national des médicaments (CDC_09 §6.2) — SOURCE UNIQUE.
 *
 * ═══ POURQUOI ELLE NAÎT AVEC LES COLONNES ═══
 *
 * Même raison qu'en P6.5a : `TypesEtablissement` avait dû être créée en rattrapage (P6.4b), une fois
 * la liste déjà divergée en quatre endroits — dont un qui répondait 422 sur des valeurs pourtant
 * acceptées par la base. Ces énumérations sont neuves : la liste naît ici, la migration en dérive,
 * l'API l'expose, et aucun client ne la recopie.
 *
 * ═══ TROIS AXES QU'IL NE FAUT PAS CONFONDRE ═══
 *
 * · La FORME est ce que le patient tient dans la main (comprimé, sirop, injectable).
 * · La VOIE est par où le produit entre (orale, injectable, cutanée). Un même principe actif existe
 *   en comprimé oral et en solution injectable : ce sont deux médicaments distincts au référentiel.
 * · Le STATUT DE MARCHÉ dit si le produit peut être délivré aujourd'hui, et le STATUT GÉNÉRIQUE dit
 *   sa position vis-à-vis du princeps. Les fondre rendrait insoluble « combien de génériques retirés
 *   du marché cette année ? », que le §6.5 suppose traçable.
 */
final class Medicaments
{
    /**
     * Formes pharmaceutiques (§6.2). L'exemple imposé du §6.3 est un comprimé de 500 mg.
     *
     * @var array<string, string>
     */
    public const FORMES = [
        'comprime'      => 'Comprimé',
        'gelule'        => 'Gélule',
        'sirop'         => 'Sirop',
        'suspension'    => 'Suspension buvable',
        'solution_inj'  => 'Solution injectable',
        'poudre_inj'    => 'Poudre pour injection',
        'suppositoire'  => 'Suppositoire',
        'creme'         => 'Crème',
        'pommade'       => 'Pommade',
        'gel'           => 'Gel',
        'collyre'       => 'Collyre',
        'goutte'        => 'Gouttes',
        'patch'         => 'Dispositif transdermique',
        'aerosol'       => 'Aérosol / inhalateur',
        'ovule'         => 'Ovule',
        'sachet'        => 'Sachet / poudre orale',
    ];

    /**
     * Voies d'administration (§6.2). L'exemple imposé du §6.3 est la voie orale.
     *
     * @var array<string, string>
     */
    public const VOIES = [
        'orale'          => 'Orale',
        'injectable'     => 'Injectable',
        'cutanee'        => 'Cutanée',
        'oculaire'       => 'Oculaire',
        'auriculaire'    => 'Auriculaire',
        'nasale'         => 'Nasale',
        'inhalee'        => 'Inhalée',
        'rectale'        => 'Rectale',
        'vaginale'       => 'Vaginale',
        'sublinguale'    => 'Sublinguale',
    ];

    /**
     * Statut de commercialisation (§6.5).
     *
     * `retire` existe DÈS MAINTENANT bien que la propagation d'un retrait soit un incrément séparé
     * (décision propriétaire) : sans lui, le référentiel ne saurait pas dire qu'un produit ne doit
     * plus être délivré, et le jour de la propagation il faudrait migrer les données au lieu de les
     * lire. Poser la donnée coûte une ligne ; la rattraper coûte une migration.
     *
     * @var array<string, string>
     */
    public const STATUTS_MARCHE = [
        'autorise' => 'Autorisé',
        'suspendu' => 'Suspendu',
        'retire'   => 'Retiré du marché',
    ];

    /**
     * Position vis-à-vis du princeps (§6.2 « statut (générique/princeps) »).
     *
     * À NE PAS CONFONDRE avec `disponible_generique`, la colonne héritée du Module 5 : celle-ci dit
     * « un générique de ce produit existe », celle-là dit « ce produit EST un générique ». Deux
     * affirmations différentes, toutes deux utiles, et c'est pourquoi l'ancienne n'est pas remplacée.
     *
     * @var array<string, string>
     */
    public const STATUTS_GENERIQUE = [
        'princeps'   => 'Princeps',
        'generique'  => 'Générique',
        'biosimilaire' => 'Biosimilaire',
    ];

    /**
     * Niveaux d'interaction (§6.2 « interactions »).
     *
     * CE SONT DES CONSTATS DU RÉFÉRENTIEL, PAS DES DÉCISIONS. Même `contre_indication` ne bloque
     * rien : le serveur rapporte ce que le référentiel déclare, il ne refuse pas une prescription —
     * ce serait une décision médicale prise par une machine (CDC_00 §4). L'analyse, les alternatives
     * et l'adaptation de doses appartiennent au `interaction-service` de CDC_05 §2.
     *
     * @var array<string, string>
     */
    public const NIVEAUX_INTERACTION = [
        'precaution'                => 'Précaution d\'emploi',
        'association_deconseillee'  => 'Association déconseillée',
        'contre_indication'         => 'Contre-indication',
    ];

    /** Le niveau le plus sévère en dernier — l'ordre sert au tri d'affichage, jamais à décider. */
    public const ORDRE_GRAVITE = ['precaution', 'association_deconseillee', 'contre_indication'];

    /** @return array<int, string> */
    public static function formes(): array
    {
        return array_keys(self::FORMES);
    }

    /** @return array<int, string> */
    public static function voies(): array
    {
        return array_keys(self::VOIES);
    }

    /** @return array<int, string> */
    public static function statutsMarche(): array
    {
        return array_keys(self::STATUTS_MARCHE);
    }

    /** @return array<int, string> */
    public static function statutsGenerique(): array
    {
        return array_keys(self::STATUTS_GENERIQUE);
    }

    /** @return array<int, string> */
    public static function niveauxInteraction(): array
    {
        return array_keys(self::NIVEAUX_INTERACTION);
    }

    public static function libelleForme(?string $cle): ?string
    {
        return $cle === null ? null : (self::FORMES[$cle] ?? $cle);
    }

    public static function libelleVoie(?string $cle): ?string
    {
        return $cle === null ? null : (self::VOIES[$cle] ?? $cle);
    }

    public static function libelleNiveau(string $cle): string
    {
        return self::NIVEAUX_INTERACTION[$cle] ?? $cle;
    }

    /** Un produit qui ne doit plus être délivré — signalé au prescripteur, jamais bloqué (M9). */
    public static function estRetireDuMarche(?string $statut): bool
    {
        return $statut === 'retire';
    }

    /**
     * Les énumérations, telles que les clients doivent les recevoir — jamais recopiées ailleurs.
     *
     * @return array<string, array<int, array{valeur: string, libelle: string}>>
     */
    public static function pourApi(): array
    {
        $paires = static fn (array $table): array => array_map(
            static fn (string $valeur, string $libelle): array => ['valeur' => $valeur, 'libelle' => $libelle],
            array_keys($table),
            array_values($table),
        );

        return [
            'formes'             => $paires(self::FORMES),
            'voies'              => $paires(self::VOIES),
            'statuts_marche'     => $paires(self::STATUTS_MARCHE),
            'statuts_generique'  => $paires(self::STATUTS_GENERIQUE),
            'niveaux_interaction' => $paires(self::NIVEAUX_INTERACTION),
        ];
    }
}
