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

    /** Limiter aux symptômes actifs. */
    public function scopeActif($query)
    {
        return $query->where('actif', true);
    }
}
