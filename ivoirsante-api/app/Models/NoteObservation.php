<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Note / observation médicale d'un membre (F2.12). `contenu` chiffré AES-256 au repos (§6 Sécurité).
 * Entrée append-only : seul `created_at` est géré (pas d'`updated_at`) ; SoftDeletes permet une
 * rétractation sans perdre la trace d'audit (FT6). `auteur_agent_id` (praticien) différé aux Modules 3/4.
 */
class NoteObservation extends Model
{
    use SoftDeletes;

    protected $table = 'notes_observations';

    /** Append-only : Eloquent ne gère que `created_at`. */
    public const UPDATED_AT = null;

    protected $fillable = [
        'contenu',
        'auteur_type',
        'auteur_user_id',
        'triage_id',
    ];

    protected function casts(): array
    {
        return [
            'contenu' => 'encrypted',
        ];
    }

    public function membre(): BelongsTo
    {
        return $this->belongsTo(MembreFamille::class, 'membre_id');
    }

    public function auteur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'auteur_user_id');
    }

    public function triage(): BelongsTo
    {
        return $this->belongsTo(Triage::class, 'triage_id');
    }
}
