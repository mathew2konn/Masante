<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Module 5 / 5.6 — Une mesure du journal de bord (CdC §8.3, FN5).
 *
 * `statut_norme` et `unite` ne sont PAS `fillable` : le serveur les dérive du référentiel de seuils
 * ({@see App\Services\MesureSanteService}). Un client qui enverrait `statut_norme = 'normal'` sur une
 * glycémie à 4 g/L ne serait pas cru. `membre_id` n'est pas `fillable` non plus (convention du
 * carnet : on crée par la relation).
 *
 * `note` est chiffrée au repos (AES-256, §6.1 Sécurité).
 */
class MesureSante extends Model
{
    protected $table = 'mesures_sante';

    protected $fillable = [
        'type_mesure',
        'valeur',
        'date_mesure',
        'note',
        'added_by',
        'source',
    ];

    /** Aligné sur le défaut SQL, pour que la réponse de création porte déjà la provenance (F2.13). */
    protected $attributes = ['source' => 'patient'];

    protected function casts(): array
    {
        return [
            'valeur'      => 'float',
            'date_mesure' => 'datetime',
            'note'        => 'encrypted',
        ];
    }

    public function membre(): BelongsTo
    {
        return $this->belongsTo(MembreFamille::class, 'membre_id');
    }

    /** Le patient ne peut effacer que SA propre saisie — jamais une mesure prise par une structure. */
    public function estSupprimableParPatient(): bool
    {
        return $this->source === 'patient';
    }
}
