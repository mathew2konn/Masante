<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Reçu numérique de RDV (Analyse_Delta_RDV N2). Présentable à l'accueil, distinct de la fiche
 * triage F1.8. Le QR de check-in (N3) n'est pas stocké : token signé autonome régénéré à la
 * demande (RecuRdvService), cloisonné du QR carnet, sans accès au dossier.
 */
class RecuRdv extends Model
{
    protected $table = 'recus_rdv';

    protected $fillable = [
        'rendez_vous_id',
        'paiement_id',
        'reference',
        'statut',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
        ];
    }

    public function rendezVous(): BelongsTo
    {
        return $this->belongsTo(RendezVous::class, 'rendez_vous_id');
    }

    public function paiement(): BelongsTo
    {
        return $this->belongsTo(Paiement::class, 'paiement_id');
    }
}
