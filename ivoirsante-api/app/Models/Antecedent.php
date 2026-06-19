<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Antécédent médical d'un membre (CdC §8.3, F2.4). `description` et `traitement_actuel`
 * chiffrés AES-256 au repos (§6 Sécurité). `impact_triage` alimente le score de triage (F1.3).
 */
class Antecedent extends Model
{
    protected $fillable = [
        'type',
        'description',
        'date_diagnostic',
        'medecin_nom',
        'structure_sanitaire',
        'traitement_actuel',
        'impact_triage',
        'added_by',
    ];

    protected function casts(): array
    {
        return [
            'description'       => 'encrypted',
            'traitement_actuel' => 'encrypted',
            'date_diagnostic'   => 'date',
            'impact_triage'     => 'integer',
        ];
    }

    public function membre(): BelongsTo
    {
        return $this->belongsTo(MembreFamille::class, 'membre_id');
    }
}
