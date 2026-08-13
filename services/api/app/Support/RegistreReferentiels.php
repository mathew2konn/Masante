<?php

namespace App\Support;

use App\Services\Referentiel\SourceEtablissements;
use App\Services\Referentiel\SourceReferentiel;
use App\Services\Referentiel\SourceSeuilsMesure;
use App\Services\Referentiel\SourceSymptomesTriage;

/**
 * Liste blanche FERMÉE des référentiels nationaux placés sous gouvernance (CDC_09 §10 ; P6.3).
 *
 * POURQUOI FERMÉE — la même raison qu'en P7-C pour les sections du carnet : le code d'un
 * référentiel arrive par l'URL. Sans liste blanche, ce code deviendrait un choix libre du client,
 * donc une porte vers n'importe quelle table. La fermeture n'est pas de la rigueur, c'est la garde.
 *
 * CE QUI N'Y FIGURE PAS, ET POURQUOI (décision G1 D3-a) :
 *  - `structures_sanitaires` → P6.4, `medecins` → P6.5, `medicaments` → P6.6, laboratoires → P6.7.
 *    Ce sont des référentiels d'ANNUAIRE ; ils entrent sous gouvernance avec leur incrément dédié,
 *    qui les enrichit (identifiant national, district sanitaire, numéro d'ordre — ADR-024).
 *  - `etapes_prenatales`, `alertes_epidemiques` : même logique, ouverture ultérieure additive.
 *
 * Les deux référentiels retenus ici sont les seuls qui portent des RÈGLES CLINIQUES — donc les
 * seuls dont une décision passée doit pouvoir être rejouée.
 *
 * AJOUTER UN RÉFÉRENTIEL = ajouter une classe et une ligne ici. Le moteur de gouvernance
 * (proposition, quatre-yeux, contrôles, publication, audit, diffusion) ne change pas.
 */
final class RegistreReferentiels
{
    /** @var array<string, class-string<SourceReferentiel>> */
    public const SOURCES = [
        SourceSeuilsMesure::CODE     => SourceSeuilsMesure::class,
        SourceSymptomesTriage::CODE  => SourceSymptomesTriage::class,
        // P6.4 — première entrée d'un référentiel d'ANNUAIRE. Elle ne gouverne pas la table
        // `structures_sanitaires` entière mais une **projection d'identité administrative** :
        // la note d'avis et les horaires y sont exclus, sans quoi l'instantané divergerait à
        // chaque avis déposé. Voir `SourceEtablissements` et ADR-026.
        SourceEtablissements::CODE   => SourceEtablissements::class,
    ];

    /** @return array<int, string> */
    public static function codes(): array
    {
        return array_keys(self::SOURCES);
    }

    public static function existe(string $code): bool
    {
        return isset(self::SOURCES[$code]);
    }

    public static function source(string $code): SourceReferentiel
    {
        if (! self::existe($code)) {
            throw new \InvalidArgumentException("Référentiel inconnu : {$code}");
        }

        return app(self::SOURCES[$code]);
    }

    /** @return array<int, SourceReferentiel> */
    public static function toutes(): array
    {
        return array_map(static fn (string $code): SourceReferentiel => self::source($code), self::codes());
    }
}
