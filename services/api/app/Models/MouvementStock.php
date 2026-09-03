<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * Un mouvement de stock (B3-b, CDC_11 §7.3) — APPEND-ONLY.
 *
 * Une erreur de saisie se corrige par un mouvement d'`ajustement`, qui la laisse VISIBLE : effacer
 * ou réécrire un fait daté ferait mentir l'historique sur lequel le stock courant est calculé.
 * Refusé à DEUX niveaux, comme `protocole_applications` (P10b-2) : ici par le modèle, et par le
 * moteur (déclencheurs dans les deux dialectes) — le second tient même face à un accès direct.
 *
 * `quantite` est SIGNÉE : une entrée est positive, une sortie négative, et le stock est leur somme.
 * Motif des contributions signées du grand livre (P5.5b-1), où aucun `abs()` n'intervient.
 */
class MouvementStock extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'mouvements_stock';

    protected $fillable = [];

    protected function casts(): array
    {
        return ['date_peremption' => 'date'];
    }

    protected static function booted(): void
    {
        static::updating(static function (): void {
            throw new RuntimeException(
                'Un mouvement de stock ne se modifie pas : corrigez par un ajustement.'
            );
        });

        static::deleting(static function (): void {
            throw new RuntimeException(
                'Un mouvement de stock ne s\'efface pas : corrigez par un ajustement.'
            );
        });
    }

    public function stock(): BelongsTo
    {
        return $this->belongsTo(StockOfficine::class, 'stock_id');
    }
}
