<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Catalogue des plans tarifaires vendus aux établissements (facturation partenaire).
 *
 * Le plan décrit CE QUI EST VENDU ; l'abonnement décrit CE QU'UN ÉTABLISSEMENT A SOUSCRIT. Les
 * séparer est ce qui permet de corriger un tarif sans réécrire ce qu'un partenaire a signé.
 *
 * `$table` est déclarée explicitement : Eloquent déduirait `plan_tarifaires` de la classe, alors
 * que la table porte le pluriel français `plans_tarifaires`. Les huit modèles de ce lot sont dans
 * ce cas — c'est la convention de nommage du projet, pas une exception.
 *
 * AUCUN CALCUL ICI. Choisir un plan selon la catégorie d'un établissement, proratiser un mois
 * entamé, décider d'une bascule : tout cela vit dans un service, hors de ce lot.
 */
class PlanTarifaire extends Model
{
    protected $table = 'plans_tarifaires';

    protected $fillable = [
        'code',
        'libelle',
        'categorie_structure',
        'montant_mensuel',
        'devise',
        'commission_incluse',
        'actif',
        'date_effet',
        'date_fin',
    ];

    protected function casts(): array
    {
        return [
            // FCFA entiers : le cast `integer` est ce qui garantit qu'aucun flottant ne rentre
            // par la porte applicative, la colonne le garantissant du côté du moteur.
            'montant_mensuel' => 'integer',
            'commission_incluse' => 'boolean',
            'actif' => 'boolean',
            'date_effet' => 'date',
            'date_fin' => 'date',
        ];
    }

    /** @return HasMany<AbonnementStructure, $this> */
    public function abonnements(): HasMany
    {
        return $this->hasMany(AbonnementStructure::class);
    }
}
