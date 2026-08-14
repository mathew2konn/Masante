<?php

namespace App\Support;

/**
 * Les énumérations du catalogue national des analyses (CDC_09 §7.3) — SOURCE UNIQUE.
 *
 * ═══ POURQUOI « GLYCÉMIE » N'EST PAS UNE ANALYSE ═══
 *
 * Une glycémie **à jeun sur plasma veineux** et une glycémie **capillaire** ne se mesurent pas de la
 * même façon, ne se comparent pas aux mêmes bornes, et ne répondent pas à la même question. Le
 * standard international (LOINC, que CDC_09 §9.1 recommande) décrit d'ailleurs une analyse selon six
 * axes, dont le **milieu prélevé** et la **méthode**.
 *
 * Les fondre en une seule entrée « glycémie » reproduirait exactement l'incohérence que §7.3 dit
 * vouloir supprimer — « les résultats sont interprétés de manière cohérente, quel que soit le
 * laboratoire ». D'où deux axes distincts ici.
 *
 * ═══ CE QUE CETTE CLASSE NE CONTIENT PAS ═══
 *
 * Aucune valeur de référence. Elles vivent en base, **stratifiées** et **sourcées**
 * ({@see App\Models\AnalyseReference}) : une plage biologique dépend du sexe, de l'âge et parfois de
 * l'état physiologique, et la figer dans le code la rendrait invisible et non gouvernable.
 */
final class Analyses
{
    /**
     * Grandes familles d'analyses. Sert à ranger le catalogue, jamais à interpréter un résultat.
     *
     * @var array<string, string>
     */
    public const CATEGORIES = [
        'hematologie'      => 'Hématologie',
        'biochimie'        => 'Biochimie',
        'immunologie'      => 'Immunologie',
        'microbiologie'    => 'Microbiologie',
        'parasitologie'    => 'Parasitologie',
        'virologie'        => 'Virologie',
        'hormonologie'     => 'Hormonologie',
        'genetique'        => 'Génétique',
        'anatomopathologie' => 'Anatomopathologie',
        'toxicologie'      => 'Toxicologie',
    ];

    /**
     * Le milieu prélevé (§7.3 « conditions de prélèvement »).
     *
     * C'est l'un des six axes de LOINC, et il change le résultat : une glycémie sur sang capillaire
     * et sur plasma veineux n'ont pas les mêmes bornes.
     *
     * @var array<string, string>
     */
    public const MILIEUX = [
        'sang_veineux'   => 'Sang veineux',
        'sang_capillaire' => 'Sang capillaire',
        'serum'          => 'Sérum',
        'plasma'         => 'Plasma',
        'urine'          => 'Urines',
        'urine_24h'      => 'Urines de 24 h',
        'selles'         => 'Selles',
        'lcr'            => 'Liquide céphalo-rachidien',
        'expectoration'  => 'Expectoration',
        'prelevement_local' => 'Prélèvement local',
        'tissu'          => 'Tissu',
    ];

    /**
     * Le sexe auquel une strate de référence s'applique.
     *
     * `tous` n'est pas un défaut de saisie : beaucoup d'analyses ont la même référence pour tout le
     * monde, et l'écrire explicitement vaut mieux qu'un NULL qu'on interpréterait.
     *
     * @var array<string, string>
     */
    public const SEXES_STRATE = [
        'tous' => 'Tous',
        'M'    => 'Homme',
        'F'    => 'Femme',
    ];

    /**
     * L'état physiologique d'une strate (§7.3, implicite dans « valeurs de référence »).
     *
     * LA PLATEFORME NE CHOISIT PAS LA STRATE PHYSIOLOGIQUE. Le carnet connaît la grossesse, mais
     * décider pour une patiente laquelle de ces plages la concerne serait un jugement clinique.
     * On affiche celles qui peuvent s'appliquer, et le lecteur voit la sienne.
     *
     * @var array<string, string>
     */
    public const ETATS = [
        'standard'       => 'État standard',
        'grossesse_t1'   => 'Grossesse — 1ᵉʳ trimestre',
        'grossesse_t2'   => 'Grossesse — 2ᵉ trimestre',
        'grossesse_t3'   => 'Grossesse — 3ᵉ trimestre',
        'nouveau_ne'     => 'Nouveau-né',
    ];

    /** Les états qui ne concernent qu'une partie des patients — affichés en plus, jamais choisis. */
    public const ETATS_CONDITIONNELS = ['grossesse_t1', 'grossesse_t2', 'grossesse_t3'];

    /**
     * Provenance d'une strate de référence — OBLIGATOIRE en base.
     *
     * ═══ LA VALEUR QUI DIT LA VÉRITÉ SUR CE QU'ON LIVRE ═══
     *
     * `demonstration` étiquette le jeu fourni avec le projet. Ces plages ne sont **ni validées
     * cliniquement, ni attribuées à une autorité sanitaire** — et c'est délibéré : je ne peux pas
     * mettre le nom d'une autorité que je n'ai pas consultée sur des valeurs que je n'ai pas
     * vérifiées. *Un intervalle inventé qui porte un nom d'autorité est pire qu'un intervalle
     * inventé qui l'avoue.*
     *
     * Le jour du déploiement, ces lignes sont remplacées par un référentiel biologique réel — et
     * idéalement par des intervalles établis **localement** : plusieurs paramètres hématologiques
     * ont des valeurs usuelles différentes en Afrique subsaharienne, au point qu'un intervalle
     * établi ailleurs classerait « anormaux » des sujets parfaitement sains. C'est **de la donnée,
     * zéro code** : la structure stratifiée existe précisément pour ça.
     *
     * @var array<string, string>
     */
    public const SOURCES_REFERENCE = [
        'demonstration' => 'Valeurs usuelles de démonstration — NON validées cliniquement',
        'autorite_nationale' => 'Autorité sanitaire nationale',
        'societe_savante'    => 'Société savante',
        'laboratoire'        => 'Laboratoire (méthode propre)',
        'publication'        => 'Publication scientifique',
    ];

    /** @return array<int, string> */
    public static function categories(): array
    {
        return array_keys(self::CATEGORIES);
    }

    /** @return array<int, string> */
    public static function milieux(): array
    {
        return array_keys(self::MILIEUX);
    }

    /** @return array<int, string> */
    public static function sexesStrate(): array
    {
        return array_keys(self::SEXES_STRATE);
    }

    /** @return array<int, string> */
    public static function etats(): array
    {
        return array_keys(self::ETATS);
    }

    /** @return array<int, string> */
    public static function sourcesReference(): array
    {
        return array_keys(self::SOURCES_REFERENCE);
    }

    public static function libelleMilieu(?string $cle): ?string
    {
        return $cle === null ? null : (self::MILIEUX[$cle] ?? $cle);
    }

    public static function libelleEtat(string $cle): string
    {
        return self::ETATS[$cle] ?? $cle;
    }

    public static function libelleSource(string $cle): string
    {
        return self::SOURCES_REFERENCE[$cle] ?? $cle;
    }

    /** Une strate qui ne concerne qu'une partie des patients : affichée en plus, jamais choisie. */
    public static function estConditionnel(string $etat): bool
    {
        return in_array($etat, self::ETATS_CONDITIONNELS, true);
    }

    /**
     * Les énumérations telles que les clients doivent les recevoir — jamais recopiées ailleurs
     * (défaut de P6.4b, où sept libellés vivaient en dur côté mobile).
     *
     * @return array<string, array<int, array{valeur: string, libelle: string}>>
     */
    public static function pourApi(): array
    {
        $paires = static fn (array $table): array => array_map(
            static fn (string $v, string $l): array => ['valeur' => $v, 'libelle' => $l],
            array_keys($table),
            array_values($table),
        );

        return [
            'categories'        => $paires(self::CATEGORIES),
            'milieux'           => $paires(self::MILIEUX),
            'sexes_strate'      => $paires(self::SEXES_STRATE),
            'etats'             => $paires(self::ETATS),
            'sources_reference' => $paires(self::SOURCES_REFERENCE),
        ];
    }
}
