<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Une ligne de prescription (B3-a, CDC_04 §105, CDC_11 §7.1).
 *
 * CE QUI EST EN CLAIR ET CE QUI NE L'EST PAS — décision prise ici, que B2-c avait renvoyée :
 * l'identité du produit est en clair (sans quoi ni la délivrance, ni les interactions, ni le §7.6
 * ne sont possibles), ce qui décrit le traitement de la personne est chiffré.
 * *Ce qui identifie un produit n'est pas ce qui décrit un traitement.*
 */
class OrdonnanceLigne extends Model
{
    protected $table = 'ordonnance_lignes';

    /**
     * Le lien au référentiel et ses valeurs figées ne sont PAS ici : ils sont posés par le serveur
     * depuis `ServiceLienMedicament` (P6.6b), comme sur `medicaments_json`. Un client qui les
     * déclarerait choisirait ce que le serveur doit vérifier.
     */
    protected $fillable = [
        'nom',
        'posologie',
        'duree',
        'instructions',
        'quantite_prescrite',
        'rang',
    ];

    protected function casts(): array
    {
        return [
            'posologie' => 'encrypted',
            'duree' => 'encrypted',
            'instructions' => 'encrypted',
        ];
    }

    public function ordonnance(): BelongsTo
    {
        return $this->belongsTo(Ordonnance::class, 'ordonnance_id');
    }

    public function medicament(): BelongsTo
    {
        return $this->belongsTo(Medicament::class, 'medicament_id');
    }

    public function lignesDelivrees(): HasMany
    {
        return $this->hasMany(DelivranceLigne::class, 'ordonnance_ligne_id');
    }

    /** Quantité déjà servie, toutes délivrances confondues. Une SOMME, jamais une colonne. */
    public function quantiteDelivree(): int
    {
        return (int) $this->lignesDelivrees()->sum('quantite');
    }

    /**
     * Reste-t-il à servir ?
     *
     * `null` quand le médecin n'a pas précisé de quantité — et `null` n'est pas `false` : on ne
     * sait pas, on ne prétend pas le contraire (précédent P10c-3-ii).
     */
    public function resteAServir(): ?int
    {
        if ($this->quantite_prescrite === null) {
            return null;
        }

        return max(0, (int) $this->quantite_prescrite - $this->quantiteDelivree());
    }

    /** Rattachée au référentiel national, ou seulement écrite en toutes lettres ? */
    public function estCodee(): bool
    {
        return $this->code_national !== null;
    }
}
