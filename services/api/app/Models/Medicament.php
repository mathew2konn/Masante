<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Module 5 / 5.8 — Médicament du catalogue (CdC §8, FN7). Donnée PUBLIQUE et non sensible
 * (un prix n'appartient à personne) : aucun chiffrement, lecture ouverte.
 */
class Medicament extends Model
{
    protected $table = 'medicaments';

    protected $fillable = [
        'nom_generique',
        'nom_commercial',
        'categorie',
        'prix_reference_cfa',
        'ordonnance_requise',
        'disponible_generique',
        'cename_reference',
    ];

    protected $appends = ['libelle'];

    protected function casts(): array
    {
        return [
            'prix_reference_cfa'   => 'integer',
            'ordonnance_requise'   => 'boolean',
            'disponible_generique' => 'boolean',
        ];
    }

    /** « DOLIPRANE (paracétamol) » ou « paracétamol » : ce que le patient reconnaît en rayon. */
    public function getLibelleAttribute(): string
    {
        return $this->nom_commercial
            ? "{$this->nom_commercial} ({$this->nom_generique})"
            : $this->nom_generique;
    }

    /** Relevés de prix et de disponibilité, toutes pharmacies et toutes sources confondues. */
    public function releves(): HasMany
    {
        return $this->hasMany(PrixPharmacie::class, 'medicament_id');
    }
}
