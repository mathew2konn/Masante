<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un maillon du journal des signatures (CDC_09 §5.4 : « l'échec est journalisé » ; P6.5b).
 *
 * Chaîne de hachage GLOBALE, motif de `referentiel_journal` (P6.3). `cree_le` seul, sans
 * `updated_at` : un journal append-only ne se met pas à jour, et lui donner une colonne de
 * modification laisserait croire le contraire.
 */
class SignatureJournal extends Model
{
    protected $table = 'signature_journal';

    public $timestamps = false;

    protected $fillable = [
        'action',
        'type_document',
        'document_id',
        'medecin_id',
        'acteur_id',
        'acteur_nom',
        'motif',
        'details',
        'empreinte',
        'empreinte_precedente',
        'cree_le',
    ];

    protected function casts(): array
    {
        return [
            'details'     => 'array',
            'cree_le'     => 'datetime',
            'document_id' => 'integer',
        ];
    }

    public function professionnel(): BelongsTo
    {
        return $this->belongsTo(Medecin::class, 'medecin_id');
    }

    public function acteur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'acteur_id');
    }
}
