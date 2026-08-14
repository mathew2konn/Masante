<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Une analyse réalisée par un laboratoire (CDC_09 §7.2 « analyses disponibles »).
 *
 * DONNÉE D'EXPLOITATION, PAS DE RÉFÉRENTIEL. Le critère de P6.4a est refait ici : une accréditation
 * est délivrée par une autorité (gouvernée, dans `certifications_json`) ; la liste des analyses
 * qu'un laboratoire réalise change avec ses automates et son personnel. Elle n'entre donc pas dans
 * la projection gouvernée — sinon l'arrivée d'un appareil deviendrait une décision ministérielle.
 *
 * `structure_id` et `analyse_id` sont hors `$fillable` : le couple est posé par
 * {@see App\Services\Analyse\CatalogueDuLaboratoire}, qui vérifie d'abord que l'établissement EST un
 * laboratoire.
 */
class LaboratoireAnalyse extends Model
{
    protected $table = 'laboratoire_analyses';

    protected $fillable = [
        'delai_rendu_heures',
        'disponible',
        'methode',
    ];

    protected function casts(): array
    {
        return [
            'delai_rendu_heures' => 'integer',
            'disponible'         => 'boolean',
        ];
    }

    public function laboratoire(): BelongsTo
    {
        return $this->belongsTo(StructureSanitaire::class, 'structure_id');
    }

    public function analyse(): BelongsTo
    {
        return $this->belongsTo(Analyse::class, 'analyse_id');
    }
}
