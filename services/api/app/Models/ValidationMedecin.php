<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * P10c-2-i (F4) — La décision d'un médecin sur une ligne du jeu d'apprentissage (CDC_05 §7.2).
 *
 * Une ligne n'existe qu'une fois la décision PRISE (`valide` ou `rejete`) — pas d'état
 * « en attente » : voir la migration.
 */
class ValidationMedecin extends Model
{
    protected $table = 'validations_medecins';

    public $timestamps = false;

    protected $fillable = [
        'jeu_id',
        'valide_par',
        'statut',
        'motif',
        'decidee_le',
    ];

    protected function casts(): array
    {
        return [
            'decidee_le' => 'datetime',
        ];
    }

    public function jeu(): BelongsTo
    {
        return $this->belongsTo(JeuDonneesEntrainement::class, 'jeu_id');
    }
}
