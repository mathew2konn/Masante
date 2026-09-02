<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * P11.2 — La trace d'un envoi partenaire.
 *
 * *Une intégration qui échoue en silence est pire qu'une intégration qui échoue.* Cette ligne est
 * ce qu'un exploitant lira le jour où un partenaire dira « je vous ai tout envoyé ».
 */
class JournalIngestion extends Model
{
    protected $table = 'journal_ingestion';

    public const CREATED_AT = 'cree_le';

    public const UPDATED_AT = null;

    protected $fillable = [
        'client_api_id', 'structure_id', 'domaine', 'idempotency_key',
        'lignes_recues', 'lignes_acceptees', 'lignes_refusees', 'refus_json', 'rejeu',
    ];

    protected $casts = [
        'refus_json' => 'array',
        'rejeu' => 'boolean',
        'cree_le' => 'datetime',
    ];
}
