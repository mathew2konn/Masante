<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Symptôme du référentiel de triage (Module 1).
 * $fillable explicite (§8.2 Sécurité — anti mass-assignment).
 */
class Symptome extends Model
{
    protected $table = 'symptomes';

    protected $fillable = [
        'nom_fr',
        'categorie',
        'poids_severite',
        'specialite_hint',
        'drapeau_rouge',
        'questions_complementaires_json',
        'maladies_probables_json',
        'actif',
    ];

    protected $casts = [
        'poids_severite' => 'integer',
        'drapeau_rouge' => 'boolean',
        'actif' => 'boolean',
        'questions_complementaires_json' => 'array',
        'maladies_probables_json' => 'array',
    ];

    /**
     * ═══ P10a — POURQUOI IL N'Y A DÉLIBÉRÉMENT AUCUNE RELATION `orientations()` ICI ═══
     *
     * Elle serait naturelle à écrire, et c'est précisément le problème : `$symptome->orientations`
     * lirait la TABLE `symptome_specialites`, alors que le triage doit lire la **version publiée**
     * du référentiel (§10). Les deux répondraient différemment entre deux publications, et la
     * seconde porte finirait par servir.
     *
     * Ce n'est pas une crainte théorique : L1+L2 a été un incrément entier consacré à refermer
     * exactement ce défaut pour `seuils_mesure` — son constat C1 était que *deux lectures
     * contournaient le service*. Ne pas ouvrir la porte coûte moins cher que de la garder.
     *
     * Le seul chemin de lecture est {@see \App\Services\Triage\ServiceSymptomesTriage} ; le seul
     * chemin d'extraction (pour publier) est {@see \App\Services\Referentiel\SourceSymptomesTriage}.
     */

    /** Limiter aux symptômes actifs. */
    public function scopeActif($query)
    {
        return $query->where('actif', true);
    }
}
