<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Contribution d'un délégué au carnet d'un proche (incrément C) — le « brouillon ».
 *
 * Ce qu'elle est : une PROPOSITION auto-déclarée, en attente de validation par un responsable.
 * Ce qu'elle n'est pas : un acte médical. Rien de ce qu'un médecin écrit ne passe par ici.
 *
 * ELLE N'EST JAMAIS CACHÉE. Un fait médical non validé reste un fait médical : si l'enfant est
 * revu deux jours plus tard, le soignant doit voir ce qui a été noté, même sans l'accord du
 * parent. La validation est un acte de gouvernance familiale, pas un critère de vérité clinique —
 * les confondre mettrait quelqu'un en danger.
 */
class Contribution extends Model
{
    public const BROUILLON = 'BROUILLON';

    public const VALIDEE = 'VALIDEE';

    public const REJETEE = 'REJETEE';

    protected $fillable = [
        'membre_id',
        'auteur_user_id',
        'section',
        'donnees',
        'statut',
        'decide_par_user_id',
        'decide_le',
        'motif_rejet',
        'entree_id',
    ];

    protected function casts(): array
    {
        return [
            'donnees'   => 'array',
            'decide_le' => 'datetime',
        ];
    }

    /** @param Builder<Contribution> $query */
    public function scopeEnAttente(Builder $query): void
    {
        $query->where('statut', self::BROUILLON);
    }

    public function estEnAttente(): bool
    {
        return $this->statut === self::BROUILLON;
    }

    public function membre(): BelongsTo
    {
        return $this->belongsTo(MembreFamille::class, 'membre_id');
    }

    public function auteur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'auteur_user_id');
    }

    public function decideur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decide_par_user_id');
    }
}
