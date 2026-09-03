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

    /**
     * Sections où CELUI QUI ÉCRIT EST LE PRESCRIPTEUR (B2-c).
     *
     * LA DISTINCTION N'EST PAS INVENTÉE ICI, ELLE EST DÉCLARÉE. P6.7b l'avait établie en corrigeant
     * P6.7a : « pour une ordonnance, celui qui écrit EST le prescripteur — rédiger l'ordonnance est
     * l'acte de prescrire. Pour un résultat d'analyse, celui qui consigne est souvent QUELQU'UN
     * D'AUTRE ». Elle vivait dans un commentaire ; elle vit désormais dans une liste que le code
     * peut lire, plutôt que dans un `if section === 'ordonnances'` disséminé.
     *
     * C'est ce qui autorise `EcritureSoignantService` à poser `medecin_id`/`structure_id` SANS
     * demander au soignant de les déclarer — et à ne surtout pas le faire sur les résultats
     * d'analyse, où ce serait inscrire le nom du mauvais médecin.
     *
     * @var array<int, string>
     */
    public const SECTIONS_AUTEUR_EST_PRESCRIPTEUR = [
        'ordonnances',
    ];

    /** L'auteur d'une écriture dans cette section en est-il le prescripteur ? */
    public static function auteurEstPrescripteur(string $section): bool
    {
        return in_array($section, self::SECTIONS_AUTEUR_EST_PRESCRIPTEUR, true);
    }

    /**
     * Sections qu'un SOIGNANT peut remplir depuis le portail (incrément D0).
     *
     * Sous-ensemble strict, et la différence est intentionnelle : `rappels` en est exclu — un
     * rappel est un outil d'organisation du patient, pas un acte médical. Les quatre autres
     * portent toutes `source`/`added_by`, condition sans laquelle la fiche de parcours (D2) ne
     * pourrait pas dire d'où vient la ligne.
     *
     * @var array<int, string>
     */
    public const SECTIONS_SOIGNANT = [
        'antecedents',
        'vaccinations',
        'ordonnances',
        'resultats-analyses',
    ];

    /** Les sections ouvertes aux contributions d'un délégué. */
    public static function ouvertesAuxContributions(): array
    {
        return array_keys(self::SECTIONS);
    }

    /** Un soignant peut-il écrire dans cette section ? */
    public static function ouverteAuSoignant(string $section): bool
    {
        return in_array($section, self::SECTIONS_SOIGNANT, true);
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
