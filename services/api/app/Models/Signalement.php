<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Signalement citoyen sur une structure (CdC §8.4, F3.10). Anonyme possible (`user_id` nullable
 * et masqué). Validation/modération par l'admin (Module 4) ; seuls les signalements
 * `visible_publiquement` apparaissent dans l'historique public.
 */
class Signalement extends Model
{
    protected $fillable = [
        'type',
        'structure_id',
        'user_id',
        'description',
        'statut',
        'visible_publiquement',
        'modere_par_user_id',
        'modere_at',
        'motif_moderation',
    ];

    protected $hidden = [
        // Anonymat du signalant (F3.10) : l'auteur n'est exposé à personne, pas même au
        // modérateur, qui tranche sur le seul contenu du signalement.
        'user_id',
        'modere_par_user_id',
        'motif_moderation',
    ];

    protected function casts(): array
    {
        return [
            'visible_publiquement' => 'boolean',
            'modere_at' => 'datetime',
        ];
    }

    public function structure(): BelongsTo
    {
        return $this->belongsTo(StructureSanitaire::class, 'structure_id');
    }

    /** Modérateur ayant pris la dernière décision (portail admin, 4.6). */
    public function moderateur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'modere_par_user_id');
    }

    /** Le signalement a-t-il déjà été tranché (validé ou rejeté) ? */
    public function estTraite(): bool
    {
        return $this->statut !== 'en_attente';
    }
}
