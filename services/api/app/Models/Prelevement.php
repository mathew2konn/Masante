<?php

namespace App\Models;

use App\Services\Analyse\ServiceCircuitPrelevement;
use App\Support\StatutPrelevement;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Un prélèvement et son cycle (B5-b/B5-c, CDC_09 §7.4, CDC_04 §109).
 *
 * Toujours écrit par assignation directe depuis {@see ServiceCircuitPrelevement}
 * (jamais depuis un tableau de requête) : `$guarded` reste minimal plutôt qu'une longue liste
 * `$fillable` qui n'a aucun appelant à protéger.
 *
 * `resultats_bruts_json`/`resultats_bruts_origine` (B5-c, M1) : le BROUILLON des résultats, avant
 * validation biologique — jamais visible du carnet, qui ne connaît que `resultats_analyses`. Il
 * SURVIT à la publication (M8) : c'est la pièce médico-légale de ce que le laboratoire a
 * réellement validé, distincte de la copie du carnet que le patient peut ensuite modifier.
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
            'resultats_bruts_json' => 'encrypted:array',
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

    /** Les verdicts rendus sur ce prélèvement (B5-c) — plusieurs lignes possibles, jamais réécrites. */
    public function validations(): HasMany
    {
        return $this->hasMany(ValidationBiologique::class, 'prelevement_id')->orderBy('cree_le');
    }

    /** Ce laboratoire précis est-il celui qui détient ce prélèvement ? Anti-IDOR (404, pas 403). */
    public function appartientA(?int $structureId): bool
    {
        return $structureId !== null && $this->laboratoire_structure_id === $structureId;
    }

    /** Un brouillon de résultats est-il en attente de validation (B5-c) ? */
    public function aUnBrouillon(): bool
    {
        return is_array($this->resultats_bruts_json) && $this->resultats_bruts_json !== [];
    }
}
