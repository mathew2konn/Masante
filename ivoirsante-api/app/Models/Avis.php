<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Avis patient sur une structure (CdC §8.4, F3.9). `user_id` est masqué (confidentialité) ;
 * on n'expose qu'un libellé d'auteur (prénom). `note_moyenne`/`nb_avis` de la structure sont
 * recalculés à chaque écriture (AvisController).
 */
class Avis extends Model
{
    protected $table = 'avis';

    protected $fillable = [
        'structure_id',
        'user_id',
        'note',
        'commentaire',
        'consultation_verifiee',
        'signale',
        'visible',
        'modere_par_user_id',
        'modere_at',
        'motif_moderation',
    ];

    protected $hidden = [
        'user_id',
        'user',
        // 4.6 — la décision de modération est tracée en base, jamais renvoyée au public :
        // ni l'identité du modérateur ni le motif ne regardent les lecteurs de la fiche.
        'modere_par_user_id',
        'motif_moderation',
    ];

    protected $appends = ['auteur'];

    protected function casts(): array
    {
        return [
            'note' => 'integer',
            'consultation_verifiee' => 'boolean',
            'signale' => 'boolean',
            'visible' => 'boolean',
            'modere_at' => 'datetime',
        ];
    }

    /** Modérateur ayant pris la dernière décision (portail admin, 4.6). */
    public function moderateur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'modere_par_user_id');
    }

    public function structure(): BelongsTo
    {
        return $this->belongsTo(StructureSanitaire::class, 'structure_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Libellé d'auteur exposé publiquement : prénom uniquement (anonymisation partielle). */
    public function getAuteurAttribute(): string
    {
        return $this->relationLoaded('user') && $this->user
            ? ($this->user->prenom ?? 'Patient')
            : 'Patient';
    }
}
