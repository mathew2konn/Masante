<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Paiement d'un RDV (Analyse_Delta_RDV N1). ⚠️ SIMULÉ : pas de passerelle Mobile Money réelle
 * (cf. FT5). Le statut est `paye` d'emblée et `transaction_ref` est factice.
 */
class Paiement extends Model
{
    protected $table = 'paiements';

    /** Modes de paiement proposés (simulés). */
    public const MODES = ['mobile_money', 'especes', 'carte'];

    protected $fillable = [
        'rendez_vous_id',
        'montant',
        'mode',
        'statut',
        'transaction_ref',
    ];

    protected function casts(): array
    {
        return [
            'montant' => 'integer',
        ];
    }

    public function rendezVous(): BelongsTo
    {
        return $this->belongsTo(RendezVous::class, 'rendez_vous_id');
    }
}
