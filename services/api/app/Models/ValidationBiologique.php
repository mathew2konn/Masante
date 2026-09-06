<?php

namespace App\Models;

use App\Support\VerdictValidationBiologique;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * Le journal des verdicts biologiques (B5-c, L7) — APPEND-ONLY, comme `journal_laboratoire`
 * (B5-b) et `traces_dispensation` (B3-c).
 *
 * « On ne supprime pas une validation, on en écrit une autre » (L6) : un rejet n'efface rien, il
 * ajoute une ligne de plus. `prelevement_id`/`user_id` sont des IDENTIFIANTS, pas des relations
 * vivantes (ADR-042 D1) — un compte ou un prélèvement supprimés ne doivent pas effacer la trace
 * d'un verdict déjà rendu. `nom` est FIGÉ, comme `preleve_par_nom`/`valide_par_nom` sur
 * {@see Prelevement}.
 */
class ValidationBiologique extends Model
{
    public const CREATED_AT = 'cree_le';

    public const UPDATED_AT = null;

    protected $table = 'validations_biologiques';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'verdict' => VerdictValidationBiologique::class,
            'cree_le' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(static function (): void {
            throw new RuntimeException('Une validation biologique ne se modifie pas : append-only.');
        });

        static::deleting(static function (): void {
            throw new RuntimeException('Une validation biologique ne s\'efface pas : append-only.');
        });
    }
}
