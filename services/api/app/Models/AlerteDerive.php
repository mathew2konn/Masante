<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * P10c-3-ii lot B (F37→F39) — Une dérive constatée (CDC_04 §123 ; CDC_05 §8).
 *
 * DÉTECTION SEULE : cette ligne ne porte aucune action et n'en déclenche aucune. Retirer un modèle
 * du service sur un indice statistique serait une décision d'exploitation prise par une machine —
 * la ligne tenue depuis ADR-017. Elle prévient ; un humain décide, avec le rollback de F24.
 *
 * Elle n'est PAS append-only, contrairement aux journaux de ce module : ce n'est pas un journal
 * d'événements mais un **rapport recalculable**. Rejouer le calcul d'une journée doit corriger la
 * ligne, pas en empiler une seconde — d'où la clé unique plutôt qu'un déclencheur d'immuabilité.
 */
class AlerteDerive extends Model
{
    protected $table = 'alertes_drift';

    public $timestamps = false;

    protected $fillable = [
        'version_id',
        'date_rapport',
        'nature',
        'niveau',
        'indicateur',
        'valeur',
        'seuil',
        'detail_json',
        'nb_lignes_reference',
        'nb_lignes_observees',
        'cree_le',
    ];

    protected function casts(): array
    {
        return [
            'date_rapport' => 'date',
            'detail_json' => 'array',
            'cree_le' => 'datetime',
        ];
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(VersionModeleIa::class, 'version_id');
    }
}
