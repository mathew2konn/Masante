<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Journal d'audit des accès aux dossiers (CdC §8.1 ; Sécurité §10), conformité loi 2013-450.
 *
 * IMMUABLE : toute modification ou suppression après écriture est refusée (§10.2). Un journal
 * modifiable n'a aucune valeur probante. Idéalement, on retire aussi les droits UPDATE/DELETE
 * de cette table au compte MySQL applicatif (défense en profondeur).
 *
 * Journal en ajout seul : pas de `updated_at`.
 */
class AccesDossier extends Model
{
    protected $table = 'acces_dossier';

    public $timestamps = false;

    protected $fillable = [
        'membre_id',
        'agent_id',
        'token_qr_id',
        'type_acces',
        // Bris de glace (Note_Continuite §5.3) : justification saisie AVANT l'ouverture. Immuable
        // comme le reste du journal — un accès d'exception doit rester explicable après coup.
        'motif_urgence',
        'sections_consultees',
        'donnees_ajoutees',
        'ip_address',
        'duree_minutes',
    ];

    protected function casts(): array
    {
        return [
            'sections_consultees' => 'array',
            'donnees_ajoutees'    => 'array',
            'created_at'          => 'datetime',
        ];
    }

    /** Garantit l'immuabilité du journal (§10.2 Sécurité). */
    protected static function booted(): void
    {
        static::updating(fn () => abort(403, 'Journal d\'audit immuable.'));
        static::deleting(fn () => abort(403, 'Journal d\'audit immuable.'));
    }

    public function membre(): BelongsTo
    {
        return $this->belongsTo(MembreFamille::class, 'membre_id');
    }
}
