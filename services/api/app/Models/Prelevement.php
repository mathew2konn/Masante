<?php

namespace App\Models;

use App\Services\Analyse\ServiceCircuitPrelevement;
use App\Support\StatutPrelevement;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un prélèvement et son cycle (B5-b, CDC_09 §7.4, CDC_04 §109).
 *
 * Toujours écrit par assignation directe depuis {@see ServiceCircuitPrelevement}
 * (jamais depuis un tableau de requête) : `$guarded` reste minimal plutôt qu'une longue liste
 * `$fillable` qui n'a aucun appelant à protéger.
 */
class Prelevement extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'statut' => StatutPrelevement::class,
            'preleve_le' => 'datetime',
            'expedie_le' => 'datetime',
            'recu_le' => 'datetime',
            'analyse_le' => 'datetime',
            'valide_le' => 'datetime',
            'publie_le' => 'datetime',
        ];
    }

    public function demande(): BelongsTo
    {
        return $this->belongsTo(DemandeAnalyse::class, 'demande_id');
    }

    public function laboratoire(): BelongsTo
    {
        return $this->belongsTo(StructureSanitaire::class, 'laboratoire_structure_id');
    }

    /** Ce laboratoire précis est-il celui qui détient ce prélèvement ? Anti-IDOR (404, pas 403). */
    public function appartientA(?int $structureId): bool
    {
        return $structureId !== null && $this->laboratoire_structure_id === $structureId;
    }
}
