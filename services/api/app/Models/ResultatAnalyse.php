<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Résultat d'analyse d'un membre (CdC §8.3, F2.6). `resultats_json` chiffré AES-256
 * au repos (§6 Sécurité).
 */
class ResultatAnalyse extends Model
{
    protected $table = 'resultats_analyses';

    protected $fillable = [
        'type_analyse',
        'intitule',
        'date_analyse',
        'laboratoire',
        'medecin_prescripteur',
        'resultats_json',
        'fichier_url',
        'added_by',
        'source',
    ];

    /** F2.13 — défaut aligné sur la colonne BDD, pour que la réponse de création porte déjà la provenance. */
    protected $attributes = ['source' => 'patient'];

    protected function casts(): array
    {
        return [
            'date_analyse'   => 'date',
            'resultats_json' => 'encrypted:array',
        ];
    }

    public function membre(): BelongsTo
    {
        return $this->belongsTo(MembreFamille::class, 'membre_id');
    }
}
