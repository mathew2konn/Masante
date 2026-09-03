<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Un article en rayon dans une officine (B3-b, CDC_11 §7.5).
 *
 * AUCUNE COLONNE `quantite` : le stock courant est la SOMME des mouvements, jamais une valeur
 * stockée qu'on corrigerait — leçon du wallet (P5.3a), où le solde est une somme.
 *
 * DISTINCT DE `prix_pharmacie`, qui est le relevé PUBLIC (alimenté aussi par les patients). Celui-ci
 * est l'inventaire dont l'officine seule répond ; il alimente le relevé, il ne le double pas.
 */
class StockOfficine extends Model
{
    protected $table = 'stocks_officine';

    /**
     * `structure_id` et `medicament_id` y figurent parce que `firstOrCreate()` les pose par
     * ASSIGNATION DE MASSE — hors `$fillable`, elles seraient écartées EN SILENCE (piège relevé par
     * P6.7b, revu en B2-c). Ici l'oubli a levé une contrainte NOT NULL plutôt que de passer
     * inaperçu, ce qui vaut mieux ; la garantie n'en dépend pas pour autant : aucun chemin client
     * ne crée un article directement, tout passe par `ServiceStockOfficine::article()`, qui vérifie
     * que la structure est bien une pharmacie.
     */
    protected $fillable = ['structure_id', 'medicament_id', 'prix_cfa', 'seuil_alerte'];

    public function structure(): BelongsTo
    {
        return $this->belongsTo(StructureSanitaire::class, 'structure_id');
    }

    public function medicament(): BelongsTo
    {
        return $this->belongsTo(Medicament::class, 'medicament_id');
    }

    public function mouvements(): HasMany
    {
        return $this->hasMany(MouvementStock::class, 'stock_id')->orderByDesc('created_at');
    }

    /** Le stock courant : une SOMME, jamais une colonne. */
    public function stockCourant(): int
    {
        return (int) $this->mouvements()->sum('quantite');
    }

    /**
     * Sous le seuil d'alerte ?
     *
     * `null` quand aucun seuil n'est fixé : on ne prétend pas savoir si un stock est bas quand
     * personne n'a dit à partir de quand il l'est (précédent P10c-3-ii, « null n'est pas 0 »).
     */
    public function sousLeSeuil(): ?bool
    {
        if ($this->seuil_alerte === null) {
            return null;
        }

        return $this->stockCourant() <= (int) $this->seuil_alerte;
    }

    /** En rayon ? C'est ce que le relevé public appelle « disponible ». */
    public function estDisponible(): bool
    {
        return $this->stockCourant() > 0;
    }
}
