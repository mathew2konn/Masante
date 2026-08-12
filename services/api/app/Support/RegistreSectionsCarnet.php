<?php

namespace App\Support;

use App\Http\Controllers\Api\V1\Carnet\AntecedentController;
use App\Http\Controllers\Api\V1\Carnet\CarnetSectionController;
use App\Http\Controllers\Api\V1\Carnet\OrdonnanceController;
use App\Http\Controllers\Api\V1\Carnet\RappelController;
use App\Http\Controllers\Api\V1\Carnet\ResultatAnalyseController;
use App\Http\Controllers\Api\V1\Carnet\VaccinationController;

/**
 * Liste blanche des sections du carnet ouvertes aux CONTRIBUTIONS (incrément C).
 *
 * SOURCE UNIQUE : cette table alimente à la fois les routes du carnet (Module 2 / 2A.4) et le
 * dépôt de contributions. Elle vivait en dur dans `routes/api.php` ; l'y laisser aurait obligé à
 * la recopier ici, avec la divergence pour seule perspective.
 *
 * POURQUOI UNE LISTE FERMÉE : une contribution nomme sa section. Sans liste blanche, ce nom
 * deviendrait un choix libre du client — donc une porte vers n'importe quelle relation Eloquent
 * du modèle. La fermeture n'est pas de la rigueur, c'est la garde.
 *
 * CE QUI N'Y FIGURE PAS, ET POURQUOI :
 *  - `contacts-urgence` : son `store()` porte ses propres règles (plafond de 2, unicité du
 *    téléphone, `est_principal` déduit). Les appliquer depuis un chemin générique les
 *    contournerait.
 *  - `notes-observations`, documents, mesures, grossesse : chacun a sa logique de création.
 * Ces sections restent réservées au propriétaire. Les ouvrir plus tard est purement additif.
 */
final class RegistreSectionsCarnet
{
    /**
     * Sections du carnet servies par le CRUD générique.
     *
     * @var array<string, class-string<CarnetSectionController>>
     */
    public const SECTIONS = [
        'antecedents'        => AntecedentController::class,
        'vaccinations'       => VaccinationController::class,
        'ordonnances'        => OrdonnanceController::class,
        'resultats-analyses' => ResultatAnalyseController::class,
        'rappels'            => RappelController::class,
    ];

    /** Les sections ouvertes aux contributions d'un délégué. */
    public static function ouvertesAuxContributions(): array
    {
        return array_keys(self::SECTIONS);
    }

    public static function existe(string $section): bool
    {
        return isset(self::SECTIONS[$section]);
    }

    /** Le contrôleur de la section — porteur de sa relation et de ses règles de validation. */
    public static function controleur(string $section): CarnetSectionController
    {
        if (! self::existe($section)) {
            throw new \InvalidArgumentException("Section de carnet inconnue : {$section}");
        }

        return app(self::SECTIONS[$section]);
    }
}
