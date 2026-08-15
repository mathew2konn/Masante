<?php

namespace App\Support;

use App\Services\Referentiel\SourceAnalyses;
use App\Services\Referentiel\SourceEtablissements;
use App\Services\Referentiel\SourceMaladies;
use App\Services\Referentiel\SourceMedicaments;
use App\Services\Referentiel\SourceProfessionnels;
use App\Services\Referentiel\SourceReferentiel;
use App\Services\Referentiel\SourceSeuilsMesure;
use App\Services\Referentiel\SourceSpecialites;
use App\Services\Referentiel\SourceSymptomesTriage;
use App\Services\Referentiel\SourceVaccins;

/**
 * Liste blanche FERMÉE des référentiels nationaux placés sous gouvernance (CDC_09 §10 ; P6.3).
 *
 * POURQUOI FERMÉE — la même raison qu'en P7-C pour les sections du carnet : le code d'un
 * référentiel arrive par l'URL. Sans liste blanche, ce code deviendrait un choix libre du client,
 * donc une porte vers n'importe quelle table. La fermeture n'est pas de la rigueur, c'est la garde.
 *
 * CE QUI N'Y FIGURE PAS, ET POURQUOI (décision G1 D3-a) :
 *  - laboratoires (le référentiel des établissements les couvre déjà en identité) → P6.7b.
 *    Ce sont des référentiels d'ANNUAIRE ; ils entrent sous gouvernance avec leur incrément dédié,
 *    qui les enrichit (identifiant national, district sanitaire, numéro d'ordre — ADR-024).
 *  - `etapes_prenatales`, `alertes_epidemiques` : même logique, ouverture ultérieure additive.
 *
 * Les deux premiers référentiels retenus étaient les seuls qui portaient des RÈGLES CLINIQUES —
 * donc les seuls dont une décision passée devait pouvoir être rejouée. Les suivants sont entrés
 * avec leur incrément, chacun pour une raison écrite dans sa propre source.
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
        // P6.5a — même nature que les établissements : une **projection d'identité**, pas la table
        // `medecins`. Le tarif de consultation, la biographie et les coordonnées en sont exclus ;
        // le numéro national, l'ordre professionnel et l'autorisation d'exercer y entrent, parce
        // qu'eux seuls engagent une autorité. Voir `SourceProfessionnels` et ADR-031.
        SourceProfessionnels::CODE   => SourceProfessionnels::class,
        // P6.6a — le premier référentiel dont la projection prend la ligne ENTIÈRE. La question a
        // été reposée plutôt que la conclusion de P6.4a recopiée, et la vérification a donné une
        // réponse opposée : rien n'écrit automatiquement dans `medicaments`, les prix relevés par
        // les citoyens vivant dans `prix_pharmacie`. L'instantané porte aussi les INTERACTIONS :
        // les publier séparément laisserait une interaction désigner un produit absent de la
        // version en vigueur. Voir `SourceMedicaments` et ADR-033.
        SourceMedicaments::CODE      => SourceMedicaments::class,
        // P6.7a — le catalogue des analyses ET ses valeurs de référence dans un seul instantané :
        // les publier séparément laisserait une strate désigner une analyse absente de la version
        // en vigueur, donc une référence irrésoluble (même raison que les interactions en P6.6a).
        // La provenance de chaque strate est OBLIGATOIRE : un intervalle biologique sans source est
        // une rumeur, et le contrôle qualité refuse de publier un catalogue qui en contient.
        SourceAnalyses::CODE         => SourceAnalyses::class,
        // P6.8a — le premier référentiel qui n'est ni un annuaire ni un catalogue d'objets, mais un
        // VOCABULAIRE. Il entre sous gouvernance pour une raison qui lui est propre : renommer un
        // terme ou en retirer un change le sens des services et des fiches de praticiens DÉJÀ
        // rattachés. C'est une décision, pas une correction de saisie. Voir `SourceSpecialites`.
        SourceSpecialites::CODE      => SourceSpecialites::class,
        // P6.8b — le premier référentiel qui porte un PLAN plutôt qu'un état : le calendrier dit ce
        // qui sera dû, à quel âge, à qui. Il entre sous gouvernance pour une raison qui lui est
        // propre — avancer une échéance, rendre une dose obligatoire ou ouvrir une fenêtre de
        // rattrapage sont des actes de santé publique, et un parent doit pouvoir savoir sous quelle
        // version du calendrier son enfant a été suivi. Voir `SourceVaccins`.
        SourceVaccins::CODE          => SourceVaccins::class,
        // P6.8c — le premier référentiel dont le contenu N'APPARTIENT À AUCUN PAYS : le paludisme
        // est le paludisme partout, et son code est unique globalement (décision propriétaire E2).
        // Ce qui est national — ce qu'on surveille, ce qu'on doit déclarer — vit dans une table à
        // part, publiée dans le MÊME instantané. Il entre sous gouvernance parce que renommer une
        // maladie ou la retirer change le sens des alertes et des antécédents déjà rattachés.
        // Voir `SourceMaladies` et ADR-037.
        SourceMaladies::CODE         => SourceMaladies::class,
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
