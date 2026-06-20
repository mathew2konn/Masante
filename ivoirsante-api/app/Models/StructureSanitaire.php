<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

/**
 * Structure sanitaire géolocalisée (CdC §8.4, F3.1/F3.5). Annuaire public (données non
 * sensibles) : aucun chiffrement. Les coordonnées GPS servent la carte et le calcul de
 * proximité. `note_moyenne`/`nb_avis` sont dénormalisés (mis à jour par les avis, 3A.2).
 */
class StructureSanitaire extends Model
{
    protected $table = 'structures_sanitaires';

    protected $fillable = [
        'nom',
        'type',
        'adresse',
        'commune',
        'latitude',
        'longitude',
        'telephone',
        'whatsapp',
        'horaires_json',
        'specialites_json',
        'tarif_min_cfa',
        'tarif_max_cfa',
        'note_moyenne',
        'nb_avis',
        'partenaire_ivoirsante',
    ];

    protected function casts(): array
    {
        return [
            'latitude' => 'float',
            'longitude' => 'float',
            'horaires_json' => 'array',
            'specialites_json' => 'array',
            'note_moyenne' => 'float',
            'partenaire_ivoirsante' => 'boolean',
        ];
    }

    /** Services médicaux de l'établissement. */
    public function services(): HasMany
    {
        return $this->hasMany(ServiceEtablissement::class, 'structure_id');
    }

    /** Disponibilités quotidiennes, à travers les services. */
    public function disponibilites(): HasManyThrough
    {
        return $this->hasManyThrough(
            DisponibiliteJour::class,
            ServiceEtablissement::class,
            'structure_id',
            'service_id',
        );
    }

    /** Inscriptions de garde (pharmacies). */
    public function gardes(): HasMany
    {
        return $this->hasMany(PharmacieGarde::class, 'structure_id');
    }
}
