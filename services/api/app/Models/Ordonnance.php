<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ordonnance médicale d'un membre (CdC §8.3, F2.5). `medicaments_json` chiffré AES-256
 * au repos (§6 Sécurité). Peut être rattachée au triage à l'origine (`triage_id`).
 */
class Ordonnance extends Model
{
    protected $fillable = [
        'triage_id',
        'medecin_nom',
        'structure_sanitaire',
        'date_prescription',
        'medicaments_json',
        'photo_url',
        'pdf_url',
        'added_by',
        'source',
    ];

    /** F2.13 — défaut aligné sur la colonne BDD, pour que la réponse de création porte déjà la provenance. */
    protected $attributes = ['source' => 'patient'];

    protected function casts(): array
    {
        return [
            'date_prescription' => 'date',
            'medicaments_json'  => 'encrypted:array',
        ];
    }

    public function membre(): BelongsTo
    {
        return $this->belongsTo(MembreFamille::class, 'membre_id');
    }
}
